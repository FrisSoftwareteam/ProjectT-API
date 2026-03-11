<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IpoOfferAllotmentRequest;
use App\Http\Requests\IpoOfferRequest;
use App\Models\IpoOffer;
use App\Models\IpoOfferAllotment;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use App\Models\ShareholderRegisterAccount;
use App\Services\CapitalValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IpoOfferController extends Controller
{
    public function __construct(
        private readonly CapitalValidationService $capitalValidationService
    ) {
    }

    public function index(Request $request)
    {
        $query = IpoOffer::with('allotments');
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        return response()->json($query->orderByDesc('id')->paginate($request->query('per_page', 15)));
    }

    public function store(IpoOfferRequest $request)
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $request) {
            $register = null;
            if (! empty($data['register_id'])) {
                $register = Register::findOrFail($data['register_id']);
            } else {
                $register = Register::create([
                    'company_id' => $data['company_id'],
                    'register_code' => $this->generateRegisterCode((int) $data['company_id']),
                    'name' => $data['new_register_name'],
                    'instrument_type' => $data['instrument_type'] ?? 'equity',
                    'capital_behaviour_type' => $data['capital_behaviour_type'] ?? 'constant',
                    'paid_up_capital' => ($data['capital_behaviour_type'] ?? 'constant') === 'constant' ? 0 : null,
                    'narration' => $data['narration'] ?? null,
                    'is_default' => false,
                    'status' => 'active',
                ]);
            }

            $shareClass = ShareClass::where('register_id', $register->id)
                ->where('class_code', $data['class_code'])
                ->first();
            if (! $shareClass) {
                $shareClass = ShareClass::create([
                    'register_id' => $register->id,
                    'class_code' => $data['class_code'],
                    'currency' => 'NGN',
                    'par_value' => 1,
                    'description' => 'IPO/Offer class',
                ]);
            }

            $offer = IpoOffer::create([
                'company_id' => $data['company_id'],
                'register_id' => $register->id,
                'share_class_id' => $shareClass->id,
                'approved_units' => $data['approved_units'],
                'allotted_units' => 0,
                'status' => 'approved',
                'offer_ref' => 'OFR-' . now()->format('YmdHis') . '-' . $register->id,
                'created_by' => $request->user()?->id,
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
            ]);

            DB::table('user_activity_logs')->insert([
                'user_id' => $request->user()?->id,
                'action' => 'ipo_offer_created',
                'metadata' => json_encode([
                    'offer_id' => $offer->id,
                    'register_id' => $register->id,
                    'share_class_id' => $shareClass->id,
                    'approved_units' => $offer->approved_units,
                ]),
                'created_at' => now(),
            ]);

            return response()->json($offer, 201);
        });
    }

    public function addAllotment(IpoOfferAllotmentRequest $request, IpoOffer $offer)
    {
        if ($offer->status === 'finalized') {
            return response()->json(['message' => 'Cannot add allotment to finalized offer'], 422);
        }

        $payload = $request->validated();
        $allotment = IpoOfferAllotment::updateOrCreate(
            ['offer_id' => $offer->id, 'shareholder_id' => $payload['shareholder_id']],
            ['quantity' => $payload['quantity']]
        );

        $offer->allotted_units = (float) IpoOfferAllotment::where('offer_id', $offer->id)->sum('quantity');
        $offer->save();

        return response()->json($allotment, 201);
    }

    public function finalize(Request $request, IpoOffer $offer)
    {
        if ($offer->status === 'finalized') {
            return response()->json(['message' => 'Offer already finalized'], 422);
        }

        $totalAllotted = (float) IpoOfferAllotment::where('offer_id', $offer->id)->sum('quantity');
        $approvedUnits = (float) $offer->approved_units;
        if (abs($totalAllotted - $approvedUnits) > 0.000001) {
            return response()->json([
                'message' => 'Total allotted units must equal approved offer units before finalization',
                'approved_units' => $approvedUnits,
                'allotted_units' => $totalAllotted,
            ], 422);
        }

        return DB::transaction(function () use ($offer, $request, $totalAllotted) {
            $allotments = IpoOfferAllotment::where('offer_id', $offer->id)->where('post_status', 'pending')->get();
            foreach ($allotments as $allotment) {
                $sra = ShareholderRegisterAccount::firstOrCreate(
                    ['shareholder_id' => $allotment->shareholder_id, 'register_id' => $offer->register_id],
                    ['shareholder_no' => ShareholderRegisterAccount::generateAccountNumber($allotment->shareholder_id), 'status' => 'active']
                );

                $position = SharePosition::where('sra_id', $sra->id)
                    ->where('share_class_id', $offer->share_class_id)
                    ->lockForUpdate()
                    ->first();
                if (! $position) {
                    $position = SharePosition::create([
                        'sra_id' => $sra->id,
                        'share_class_id' => $offer->share_class_id,
                        'quantity' => 0,
                        'holding_mode' => 'demat',
                    ]);
                }

                $position->quantity = (string) ((float) $position->quantity + (float) $allotment->quantity);
                $position->save();

                ShareTransaction::create([
                    'sra_id' => $sra->id,
                    'share_class_id' => $offer->share_class_id,
                    'tx_type' => 'allot',
                    'quantity' => $allotment->quantity,
                    'tx_ref' => 'IPO-' . $offer->offer_ref,
                    'tx_date' => now(),
                    'created_by' => $request->user()?->id,
                ]);

                $allotment->post_status = 'posted';
                $allotment->posted_at = now();
                $allotment->save();
            }

            $register = Register::findOrFail($offer->register_id);
            if ($register->capital_behaviour_type === 'constant') {
                $register->paid_up_capital = (float) ($register->paid_up_capital ?? 0) + $totalAllotted;
                $register->save();
            }

            $offer->status = 'finalized';
            $offer->allotted_units = $totalAllotted;
            $offer->finalized_at = now();
            $offer->save();

            $this->capitalValidationService->syncOutstandingUnits((int) $offer->register_id);
            $this->capitalValidationService->assertConstantBalanced((int) $offer->register_id);

            DB::table('user_activity_logs')->insert([
                'user_id' => $request->user()?->id,
                'action' => 'ipo_offer_finalized',
                'metadata' => json_encode([
                    'offer_id' => $offer->id,
                    'offer_ref' => $offer->offer_ref,
                    'allotted_units' => $totalAllotted,
                    'register_id' => $offer->register_id,
                ]),
                'created_at' => now(),
            ]);

            return response()->json($offer->fresh('allotments'));
        });
    }

    private function generateRegisterCode(int $companyId): string
    {
        $existing = Register::where('company_id', $companyId)->count();
        return sprintf('REG-%d-%06d', $companyId, $existing + 1);
    }
}


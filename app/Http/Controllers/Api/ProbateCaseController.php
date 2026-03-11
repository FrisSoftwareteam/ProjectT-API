<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProbateCase;
use App\Models\ProbateBeneficiary;
use App\Http\Requests\ProbateCaseRequest;
use App\Http\Requests\ProbateBeneficiaryRequest;
use App\Http\Requests\EstateCaseRepresentativeRequest;
use App\Models\EstateCaseRepresentative;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use App\Models\ShareClass;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use App\Services\ShareholderAccountNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProbateCaseController extends Controller
{
    public function __construct(
        private readonly ShareholderAccountNumberService $accountNumberService
    ) {
    }

    public function index(Request $request)
    {
        $data = ProbateCase::with('beneficiaries', 'representatives', 'estateShareholder')->paginate($request->query('per_page', 15));
        return response()->json($data);
    }

    public function store(ProbateCaseRequest $request)
    {
        $payload = $request->validated();
        $payload['case_status'] = $payload['case_status'] ?? 'draft';
        $case = ProbateCase::create($payload);
        return response()->json($case, 201);
    }

    public function show(ProbateCase $probateCase)
    {
        $probateCase->load('beneficiaries', 'representatives', 'estateShareholder');
        return response()->json($probateCase);
    }

    public function update(ProbateCaseRequest $request, ProbateCase $probateCase)
    {
        $payload = $request->validated();
        $previousCaseStatus = $probateCase->case_status;
        $probateCase->update($payload);

        if (($payload['case_status'] ?? null) === 'approved' && $previousCaseStatus !== 'approved') {
            $this->createEstateAccountAndMoveHoldings($probateCase, $request->user()?->id);
        }

        return response()->json($probateCase->fresh(['beneficiaries', 'representatives', 'estateShareholder']));
    }

    public function destroy(ProbateCase $probateCase)
    {
        $probateCase->delete();
        return response()->noContent();
    }

    // Add beneficiary to a probate case
    public function addBeneficiary(ProbateBeneficiaryRequest $request, $probateCaseId)
    {
        $payload = $request->validated();
        $payload['probate_case_id'] = $probateCaseId;
        $benef = ProbateBeneficiary::create($payload);
        return response()->json($benef, 201);
    }

    public function addRepresentative(EstateCaseRepresentativeRequest $request, ProbateCase $probateCase)
    {
        $payload = $request->validated();

        if ($probateCase->case_type === 'probate' && $payload['representative_type'] !== 'executor') {
            return response()->json([
                'message' => 'Probate cases only allow executor representatives.',
            ], 422);
        }

        if ($probateCase->case_type === 'letters_of_administration' && $payload['representative_type'] !== 'administrator') {
            return response()->json([
                'message' => 'Letters of administration cases only allow administrator representatives.',
            ], 422);
        }

        $payload['probate_case_id'] = $probateCase->id;
        $representative = EstateCaseRepresentative::create($payload);

        return response()->json($representative, 201);
    }

    // Execute a beneficiary transfer (mark executed)
    public function executeBeneficiary(Request $request, $id)
    {
        $benef = ProbateBeneficiary::findOrFail($id);
        $probateCase = $benef->probateCase()->firstOrFail();

        if ($probateCase->case_status !== 'approved') {
            return response()->json(['message' => 'Only approved estate cases can distribute holdings'], 422);
        }
        if (! $probateCase->estate_shareholder_id) {
            return response()->json(['message' => 'Estate account not created for this case'], 422);
        }

        if ($benef->transfer_status === 'executed') {
            return response()->json(['message' => 'Already executed'], 422);
        }

        $shareClass = ShareClass::find($benef->share_class_id);
        if (! $shareClass) {
            return response()->json(['message' => 'Share class not found'], 422);
        }
        $registerId = $shareClass->register_id;
        $sourceSraId = ShareholderRegisterAccount::where('shareholder_id', $probateCase->estate_shareholder_id)
            ->where('register_id', $registerId)
            ->value('id');
        if (! $sourceSraId) {
            return response()->json(['message' => 'Estate register account not found for this instrument'], 422);
        }

        $qty = (float) $benef->quantity;
        if ($qty <= 0) {
            return response()->json(['message' => 'Invalid quantity'], 422);
        }

        // Start DB transaction to move shares
        DB::beginTransaction();
        try {
            // Reduce source position
            $sourcePos = SharePosition::where('sra_id', $sourceSraId)
                ->where('share_class_id', $benef->share_class_id)
                ->lockForUpdate()
                ->first();

            if (! $sourcePos || (float) $sourcePos->quantity < $qty) {
                DB::rollBack();
                return response()->json(['message' => 'Insufficient source shares'], 422);
            }

            $sourcePos->quantity = (float) $sourcePos->quantity - $qty;
            $sourcePos->save();

            // Increase or create beneficiary position (must have sra_id)
            $targetSraId = $benef->beneficiary_shareholder_id ? 
                DB::table('shareholder_register_accounts')->where('shareholder_id', $benef->beneficiary_shareholder_id)->where('register_id', $registerId)->value('id') : $benef->sra_id;

            if (! $targetSraId) {
                if (! $benef->beneficiary_shareholder_id) {
                    DB::rollBack();
                    return response()->json(['message' => 'Beneficiary shareholder is required for distribution'], 422);
                }
                $targetSraId = DB::table('shareholder_register_accounts')->insertGetId([
                    'shareholder_id' => $benef->beneficiary_shareholder_id,
                    'register_id' => $registerId,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $targetPos = SharePosition::where('sra_id', $targetSraId)
                ->where('share_class_id', $benef->share_class_id)
                ->lockForUpdate()
                ->first();

            if ($targetPos) {
                $targetPos->quantity = (float) $targetPos->quantity + $qty;
                $targetPos->save();
            } else {
                $targetPos = SharePosition::create([
                    'sra_id' => $targetSraId,
                    'share_class_id' => $benef->share_class_id,
                    'quantity' => $qty,
                    'holding_mode' => 'demat',
                ]);
            }

            // Create share_transactions records: transfer_out and transfer_in
            $txRef = 'probate-'.$benef->probate_case_id.'-'. $benef->id .'-'.time();

            ShareTransaction::create([
                'sra_id' => $sourceSraId,
                'share_class_id' => $benef->share_class_id,
                'tx_type' => 'transfer_out',
                'quantity' => $qty,
                'tx_ref' => $txRef,
                'tx_date' => now(),
                'created_by' => $request->user()?->id,
            ]);

            ShareTransaction::create([
                'sra_id' => $targetSraId,
                'share_class_id' => $benef->share_class_id,
                'tx_type' => 'transfer_in',
                'quantity' => $qty,
                'tx_ref' => $txRef,
                'tx_date' => now(),
                'created_by' => $request->user()?->id,
            ]);

            // Mark beneficiary as executed
            $benef->update([
                'transfer_status' => 'executed',
                'executed_by' => $request->user()?->id,
                'executed_at' => now(),
            ]);

            if ($request->user()?->id) {
                DB::table('user_activity_logs')->insert([
                    'user_id' => $request->user()->id,
                    'action' => 'estate_distribution',
                    'metadata' => json_encode([
                        'probate_case_id' => $probateCase->id,
                        'beneficiary_id' => $benef->id,
                        'quantity' => $qty,
                        'share_class_id' => $benef->share_class_id,
                    ]),
                    'created_at' => now(),
                ]);
            }

            DB::commit();
            return response()->json($benef);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Execution failed', 'error' => $e->getMessage()], 500);
        }
    }

    private function createEstateAccountAndMoveHoldings(ProbateCase $probateCase, ?int $userId): void
    {
        DB::transaction(function () use ($probateCase, $userId) {
            $deceased = Shareholder::findOrFail($probateCase->shareholder_id);
            if ($deceased->status !== 'deceased') {
                $deceased->status = 'deceased';
                $deceased->save();
            }

            $estateShareholder = $probateCase->estateShareholder;
            if (! $estateShareholder) {
                $estateTitle = 'Estate of ' . ($deceased->full_name ?: trim(($deceased->first_name ?? '') . ' ' . ($deceased->last_name ?? '')));
                $email = 'estate+' . $probateCase->id . '@estate.local';
                $phone = '9' . str_pad((string) $probateCase->id, 10, '0', STR_PAD_LEFT);

                $estateShareholder = Shareholder::create([
                    'account_no' => $this->accountNumberService->generate(),
                    'holder_type' => 'corporate',
                    'first_name' => $estateTitle,
                    'last_name' => null,
                    'middle_name' => null,
                    'full_name' => $estateTitle,
                    'email' => $email,
                    'phone' => $phone,
                    'status' => 'active',
                ]);

                $probateCase->estate_shareholder_id = $estateShareholder->id;
                $probateCase->save();
            }

            $deceasedSras = ShareholderRegisterAccount::where('shareholder_id', $deceased->id)->get();
            foreach ($deceasedSras as $deceasedSra) {
                $estateSra = ShareholderRegisterAccount::firstOrCreate(
                    ['shareholder_id' => $estateShareholder->id, 'register_id' => $deceasedSra->register_id],
                    ['shareholder_no' => ShareholderRegisterAccount::generateAccountNumber($estateShareholder->id), 'status' => 'active']
                );

                $positions = SharePosition::where('sra_id', $deceasedSra->id)->lockForUpdate()->get();
                foreach ($positions as $position) {
                    $qty = (float) $position->quantity;
                    if ($qty <= 0) {
                        continue;
                    }

                    $estatePos = SharePosition::where('sra_id', $estateSra->id)
                        ->where('share_class_id', $position->share_class_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $estatePos) {
                        $estatePos = SharePosition::create([
                            'sra_id' => $estateSra->id,
                            'share_class_id' => $position->share_class_id,
                            'quantity' => 0,
                            'holding_mode' => $position->holding_mode,
                        ]);
                    }

                    $position->quantity = 0;
                    $position->save();

                    $estatePos->quantity = (string) ((float) $estatePos->quantity + $qty);
                    $estatePos->save();

                    $txRef = 'ESTATE-MOVE-' . $probateCase->id . '-' . now()->timestamp;
                    ShareTransaction::create([
                        'sra_id' => $deceasedSra->id,
                        'share_class_id' => $position->share_class_id,
                        'tx_type' => 'transfer_out',
                        'quantity' => $qty,
                        'tx_ref' => $txRef,
                        'tx_date' => now(),
                        'created_by' => $userId,
                    ]);

                    ShareTransaction::create([
                        'sra_id' => $estateSra->id,
                        'share_class_id' => $position->share_class_id,
                        'tx_type' => 'transfer_in',
                        'quantity' => $qty,
                        'tx_ref' => $txRef,
                        'tx_date' => now(),
                        'created_by' => $userId,
                    ]);
                }
            }

            if ($userId) {
                DB::table('user_activity_logs')->insert([
                    'user_id' => $userId,
                    'action' => 'estate_account_created',
                    'metadata' => json_encode([
                        'probate_case_id' => $probateCase->id,
                        'estate_shareholder_id' => $estateShareholder->id,
                        'deceased_shareholder_id' => $deceased->id,
                        'case_type' => $probateCase->case_type,
                    ]),
                    'created_at' => now(),
                ]);
            }
        });
    }
}

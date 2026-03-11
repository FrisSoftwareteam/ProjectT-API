<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareholderMergeRequest;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use App\Models\Shareholder;
use App\Models\ShareholderMergeEvent;
use App\Models\ShareholderRegisterAccount;
use App\Services\CapitalValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShareholderMergeController extends Controller
{
    public function __construct(
        private readonly CapitalValidationService $capitalValidationService
    ) {
    }

    public function store(ShareholderMergeRequest $request)
    {
        $data = $request->validated();
        $primary = Shareholder::findOrFail($data['primary_shareholder_id']);
        $duplicate = Shareholder::findOrFail($data['duplicate_shareholder_id']);

        if (! $this->mergeConditionSatisfied($primary, $duplicate, $data['verification_basis'])) {
            throw ValidationException::withMessages([
                'verification_basis' => ['Merge condition failed. Require CHN match or identity (NIN/BVN) match.'],
            ]);
        }

        return DB::transaction(function () use ($data, $primary, $duplicate) {
            $registerAdjustments = [];

            $duplicateSras = ShareholderRegisterAccount::where('shareholder_id', $duplicate->id)->get();
            foreach ($duplicateSras as $dupSra) {
                $primarySra = ShareholderRegisterAccount::firstOrCreate(
                    ['shareholder_id' => $primary->id, 'register_id' => $dupSra->register_id],
                    ['shareholder_no' => ShareholderRegisterAccount::generateAccountNumber($primary->id), 'status' => 'active']
                );

                $dupPositions = SharePosition::where('sra_id', $dupSra->id)->lockForUpdate()->get();
                foreach ($dupPositions as $dupPos) {
                    $qty = (float) $dupPos->quantity;
                    if ($qty <= 0) {
                        continue;
                    }

                    $primaryPos = SharePosition::where('sra_id', $primarySra->id)
                        ->where('share_class_id', $dupPos->share_class_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $primaryPos) {
                        $primaryPos = SharePosition::create([
                            'sra_id' => $primarySra->id,
                            'share_class_id' => $dupPos->share_class_id,
                            'quantity' => 0,
                            'holding_mode' => $dupPos->holding_mode,
                        ]);
                    }

                    $dupPos->quantity = 0;
                    $dupPos->save();

                    $primaryPos->quantity = (string) ((float) $primaryPos->quantity + $qty);
                    $primaryPos->save();

                    $txRef = 'MRG-' . now()->format('YmdHis') . '-' . $duplicate->id . '-' . $primary->id;
                    ShareTransaction::create([
                        'sra_id' => $dupSra->id,
                        'share_class_id' => $dupPos->share_class_id,
                        'tx_type' => 'transfer_out',
                        'quantity' => $qty,
                        'tx_ref' => $txRef,
                        'tx_date' => now(),
                        'created_by' => auth()->id(),
                    ]);
                    ShareTransaction::create([
                        'sra_id' => $primarySra->id,
                        'share_class_id' => $dupPos->share_class_id,
                        'tx_type' => 'transfer_in',
                        'quantity' => $qty,
                        'tx_ref' => $txRef,
                        'tx_date' => now(),
                        'created_by' => auth()->id(),
                    ]);
                }

                DB::table('sra_external_identifiers')
                    ->where('sra_id', $dupSra->id)
                    ->update(['sra_id' => $primarySra->id, 'updated_at' => now()]);

                $dupSra->status = 'closed';
                $dupSra->save();

                $registerAdjustments[(int) $dupSra->register_id] = true;
            }

            $duplicate->status = 'closed';
            $duplicate->save();

            foreach (array_keys($registerAdjustments) as $registerId) {
                $this->capitalValidationService->syncOutstandingUnits((int) $registerId);
                $this->capitalValidationService->assertConstantBalanced((int) $registerId);
            }

            $event = ShareholderMergeEvent::create([
                'primary_shareholder_id' => $primary->id,
                'duplicate_shareholder_id' => $duplicate->id,
                'verification_basis' => $data['verification_basis'],
                'reason' => $data['reason'] ?? null,
                'metadata' => [
                    'primary_email' => $primary->email,
                    'duplicate_email' => $duplicate->email,
                    'primary_phone' => $primary->phone,
                    'duplicate_phone' => $duplicate->phone,
                ],
                'created_by' => auth()->id(),
            ]);

            DB::table('user_activity_logs')->insert([
                'user_id' => auth()->id(),
                'action' => 'shareholder_merge',
                'metadata' => json_encode([
                    'event_id' => $event->id,
                    'primary_shareholder_id' => $primary->id,
                    'duplicate_shareholder_id' => $duplicate->id,
                    'verification_basis' => $data['verification_basis'],
                ]),
                'created_at' => now(),
            ]);

            return response()->json([
                'message' => 'Shareholder merge completed',
                'data' => $event,
            ], 201);
        });
    }

    private function mergeConditionSatisfied(Shareholder $primary, Shareholder $duplicate, string $basis): bool
    {
        if ($basis === 'identity') {
            return (
                (! empty($primary->nin) && ! empty($duplicate->nin) && $primary->nin === $duplicate->nin) ||
                (! empty($primary->bvn) && ! empty($duplicate->bvn) && $primary->bvn === $duplicate->bvn)
            );
        }

        $primaryChns = DB::table('shareholder_register_accounts')
            ->where('shareholder_id', $primary->id)
            ->whereNotNull('chn')
            ->pluck('chn')
            ->all();
        $duplicateChns = DB::table('shareholder_register_accounts')
            ->where('shareholder_id', $duplicate->id)
            ->whereNotNull('chn')
            ->pluck('chn')
            ->all();

        return count(array_intersect($primaryChns, $duplicateChns)) > 0;
    }
}


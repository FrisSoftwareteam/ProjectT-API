<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddShareRequest;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use App\Models\SharePosition;
use App\Models\ShareLot;
use App\Models\ShareTransaction;
use App\Models\ShareClass;
use App\Services\CapitalValidationService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ShareAllocationController extends Controller
{
    public function __construct(
        private readonly CapitalValidationService $capitalValidationService
    ) {
    }

    /**
     * Allocate shares to a shareholder.
     * Creates or finds the shareholder register account (SRA), updates/creates a share position,
     * creates a share lot and a share transaction record.
     */
    public function allocate(AddShareRequest $request, $shareholderId)
    {
        $shareholder = Shareholder::findOrFail($shareholderId);
        $data = $request->validated();
        $shareClass = ShareClass::findOrFail($data['share_class_id']);
        $registerId = $data['register_id'] ?? $shareClass->register_id;

        if ((int) $shareClass->register_id !== (int) $registerId) {
            return response()->json([
                'message' => 'share_class_id does not belong to the supplied register_id',
            ], 422);
        }

        return DB::transaction(function () use ($shareholder, $data, $registerId) {
            $this->capitalValidationService->assertChangeAllowed(
                (int) $registerId,
                (float) $data['quantity'],
                isset($data['corporate_action_id']) ? (int) $data['corporate_action_id'] : null
            );

            // Find or create SRA (shareholder_register_accounts)
            $sra = ShareholderRegisterAccount::firstOrCreate(
                ['shareholder_id' => $shareholder->id, 'register_id' => $registerId],
                ['shareholder_no' => ShareholderRegisterAccount::generateAccountNumber($shareholder->id)]
            );

            // Find or create position for the share class (sra_id)
            $position = SharePosition::where('sra_id', $sra->id)
                ->where('share_class_id', $data['share_class_id'])
                ->lockForUpdate()
                ->first();

            if ($position) {
                $position->quantity = bcadd((string)$position->quantity, (string)$data['quantity'], 8);
                $position->save();
            } else {
                $position = SharePosition::create([
                    'sra_id' => $sra->id,
                    'share_class_id' => $data['share_class_id'],
                    'quantity' => $data['quantity'],
                    'holding_mode' => $data['holding_mode'] ?? 'demat',
                ]);
            }

            // Create lot
            $lot = ShareLot::create([
                'sra_id' => $sra->id,
                'share_class_id' => $data['share_class_id'],
                'quantity' => $data['quantity'],
                'lot_ref' => $data['lot_ref'] ?? ('ALLOC-' . strtoupper(Str::random(8))),
                'source_type' => $data['source_type'],
                'acquired_at' => $data['acquired_at'] ?? now(),
            ]);

            // map source_type to tx_type where necessary
            $txType = $data['source_type'];
            if ($txType === 'allotment') {
                $txType = 'allot';
            }

            // Create transaction record
            $tx = ShareTransaction::create([
                'sra_id' => $sra->id,
                'share_class_id' => $data['share_class_id'],
                'tx_type' => $txType,
                'quantity' => $data['quantity'],
                'tx_ref' => $data['lot_ref'] ?? $lot->lot_ref,
                'tx_date' => $data['acquired_at'] ?? now(),
                'created_by' => auth()->id(),
            ]);

            $this->capitalValidationService->syncOutstandingUnits((int) $registerId);
            $this->capitalValidationService->assertConstantBalanced((int) $registerId);

            return response()->json([
                'sra' => $sra,
                'position' => $position,
                'lot' => $lot,
                'transaction' => $tx,
            ], 201);
        });
    }
}

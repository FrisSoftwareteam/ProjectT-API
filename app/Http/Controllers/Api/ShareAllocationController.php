<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddShareRequest;
use App\Http\Requests\DisposeShareRequest;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use App\Models\SharePosition;
use App\Models\ShareLot;
use App\Models\ShareTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ShareAllocationController extends Controller
{
    /**
     * Allocate shares to a shareholder.
     * Creates or finds the shareholder register account (SRA), updates/creates a share position,
     * creates a share lot and a share transaction record.
     */
    public function allocate(AddShareRequest $request, $shareholderId)
    {
        $shareholder = Shareholder::findOrFail($shareholderId);

        $data = $request->validated();

        return DB::transaction(function () use ($shareholder, $data) {
            // Find or create SRA (shareholder_register_accounts)
            $sra = ShareholderRegisterAccount::firstOrCreate(
                ['shareholder_id' => $shareholder->id, 'register_id' => $data['register_id']],
                ['shareholder_no' => $this->generateShareholderNo($shareholder->id)]
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

            return response()->json([
                'direction' => 'inflow',
                'sra' => $sra,
                'position' => $position,
                'lot' => $lot,
                'transaction' => $tx,
            ], 201);
        });
    }

    /**
     * Dispose (outflow) shares from a shareholder's register account.
     */
    public function dispose(DisposeShareRequest $request, $shareholderId)
    {
        $shareholder = Shareholder::findOrFail($shareholderId);
        $data = $request->validated();

        return DB::transaction(function () use ($shareholder, $data) {
            $sra = ShareholderRegisterAccount::where('shareholder_id', $shareholder->id)
                ->where('register_id', $data['register_id'])
                ->firstOrFail();

            $position = SharePosition::where('sra_id', $sra->id)
                ->where('share_class_id', $data['share_class_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (bccomp((string) $position->quantity, (string) $data['quantity'], 8) < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient share balance for this disposal',
                    'errors' => [
                        'quantity' => ['Requested disposal quantity exceeds available position quantity.'],
                    ],
                ], 422);
            }

            $newQty = bcsub((string) $position->quantity, (string) $data['quantity'], 8);
            $closeIfZero = (bool) ($data['close_position_if_zero'] ?? false);

            if (bccomp($newQty, '0', 8) === 0 && $closeIfZero) {
                $position->delete();
            } else {
                $position->quantity = $newQty;
                $position->save();
            }

            $tx = ShareTransaction::create([
                'sra_id' => $sra->id,
                'share_class_id' => $data['share_class_id'],
                'tx_type' => $data['tx_type'],
                'quantity' => $data['quantity'],
                'tx_ref' => $data['tx_ref'] ?? ('DISP-' . strtoupper(Str::random(8))),
                'tx_date' => $data['tx_date'] ?? now(),
                'created_by' => auth()->id(),
            ]);

            return response()->json([
                'direction' => 'outflow',
                'sra' => $sra,
                'position' => $closeIfZero && bccomp($newQty, '0', 8) === 0 ? null : $position->fresh(),
                'transaction' => $tx,
            ], 201);
        });
    }

    private function generateShareholderNo(int $shareholderId): string
    {
        return 'SRA-' . str_pad((string) $shareholderId, 8, '0', STR_PAD_LEFT) . '-' . strtoupper(Str::random(4));
    }
}

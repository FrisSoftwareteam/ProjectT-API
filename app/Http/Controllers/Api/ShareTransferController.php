<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareTransferRequest;
use App\Models\ShareClass;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use App\Models\ShareTransferEvent;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use App\Services\CapitalValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShareTransferController extends Controller
{
    public function __construct(
        private readonly CapitalValidationService $capitalValidationService
    ) {
    }

    public function store(ShareTransferRequest $request)
    {
        $data = $request->validated();
        $fromShareholder = Shareholder::findOrFail($data['from_shareholder_id']);
        $toShareholder = Shareholder::findOrFail($data['to_shareholder_id']);
        $shareClass = ShareClass::findOrFail($data['share_class_id']);
        $registerId = (int) $shareClass->register_id;

        if ($fromShareholder->status === 'deceased') {
            return response()->json([
                'message' => 'Direct transfer is blocked for deceased shareholders. Use approved estate flow.',
            ], 422);
        }

        return DB::transaction(function () use ($data, $fromShareholder, $toShareholder, $shareClass, $registerId) {
            $fromSra = ShareholderRegisterAccount::where('shareholder_id', $fromShareholder->id)
                ->where('register_id', $registerId)
                ->firstOrFail();

            $toSra = ShareholderRegisterAccount::firstOrCreate(
                ['shareholder_id' => $toShareholder->id, 'register_id' => $registerId],
                ['shareholder_no' => ShareholderRegisterAccount::generateAccountNumber($toShareholder->id), 'status' => 'active']
            );

            $qty = (float) $data['quantity'];
            $fromPos = SharePosition::where('sra_id', $fromSra->id)
                ->where('share_class_id', $shareClass->id)
                ->lockForUpdate()
                ->first();

            if (! $fromPos || (float) $fromPos->quantity < $qty) {
                throw ValidationException::withMessages([
                    'quantity' => ['Insufficient units for transfer.'],
                ]);
            }

            $toPos = SharePosition::where('sra_id', $toSra->id)
                ->where('share_class_id', $shareClass->id)
                ->lockForUpdate()
                ->first();

            if (! $toPos) {
                $toPos = SharePosition::create([
                    'sra_id' => $toSra->id,
                    'share_class_id' => $shareClass->id,
                    'quantity' => 0,
                    'holding_mode' => 'demat',
                ]);
            }

            $fromPos->quantity = (string) ((float) $fromPos->quantity - $qty);
            $toPos->quantity = (string) ((float) $toPos->quantity + $qty);
            $fromPos->save();
            $toPos->save();

            $txRef = 'TRF-' . now()->format('YmdHis') . '-' . $fromSra->id . '-' . $toSra->id;
            ShareTransaction::create([
                'sra_id' => $fromSra->id,
                'share_class_id' => $shareClass->id,
                'tx_type' => 'transfer_out',
                'quantity' => $qty,
                'tx_ref' => $txRef,
                'tx_date' => now(),
                'created_by' => auth()->id(),
            ]);

            ShareTransaction::create([
                'sra_id' => $toSra->id,
                'share_class_id' => $shareClass->id,
                'tx_type' => 'transfer_in',
                'quantity' => $qty,
                'tx_ref' => $txRef,
                'tx_date' => now(),
                'created_by' => auth()->id(),
            ]);

            $this->capitalValidationService->syncOutstandingUnits($registerId);
            $this->capitalValidationService->assertConstantBalanced($registerId);

            $event = ShareTransferEvent::create([
                'from_shareholder_id' => $fromShareholder->id,
                'to_shareholder_id' => $toShareholder->id,
                'from_sra_id' => $fromSra->id,
                'to_sra_id' => $toSra->id,
                'share_class_id' => $shareClass->id,
                'quantity' => $qty,
                'tx_ref' => $txRef,
                'document_ref' => $data['document_ref'] ?? null,
                'metadata' => [
                    'corporate_action_id' => $data['corporate_action_id'] ?? null,
                ],
                'created_by' => auth()->id(),
            ]);

            DB::table('user_activity_logs')->insert([
                'user_id' => auth()->id(),
                'action' => 'share_transfer',
                'metadata' => json_encode([
                    'event_id' => $event->id,
                    'from_shareholder_id' => $fromShareholder->id,
                    'to_shareholder_id' => $toShareholder->id,
                    'share_class_id' => $shareClass->id,
                    'quantity' => $qty,
                ]),
                'created_at' => now(),
            ]);

            return response()->json([
                'message' => 'Share transfer completed',
                'data' => $event,
            ], 201);
        });
    }
}


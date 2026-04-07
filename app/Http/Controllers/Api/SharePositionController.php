<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use App\Http\Requests\SharePositionUpdateRequest;
use App\Services\CapitalValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SharePositionController extends Controller
{
    public function __construct(
        private readonly CapitalValidationService $capitalValidationService
    ) {
    }

    public function index(Request $request)
    {
        $query = SharePosition::with('shareClass.register.company');
        if ($request->filled('sra_id')) {
            $query->where('sra_id', $request->query('sra_id'));
        }
        if ($request->filled('share_class_id')) {
            $query->where('share_class_id', $request->query('share_class_id'));
        }
        return response()->json($query->paginate($request->query('per_page', 15)));
    }

    public function show(SharePosition $sharePosition)
    {
        $sharePosition->loadMissing('shareClass.register.company');

        return response()->json($sharePosition);
    }

    public function update(SharePositionUpdateRequest $request, SharePosition $sharePosition)
    {
        $payload = $request->validated();
        $registerId = (int) $sharePosition->shareClass->register_id;
        $before = (float) $sharePosition->quantity;
        $after = (float) $payload['quantity'];
        $delta = $after - $before;

        return DB::transaction(function () use ($payload, $sharePosition, $registerId, $before, $delta) {
            $this->capitalValidationService->assertChangeAllowed(
                $registerId,
                $delta,
                isset($payload['corporate_action_id']) ? (int) $payload['corporate_action_id'] : null
            );

            $sharePosition->update([
                'quantity' => $payload['quantity'],
                'holding_mode' => $payload['holding_mode'],
            ]);

            if (abs($delta) > 0.000001) {
                ShareTransaction::create([
                    'sra_id' => $sharePosition->sra_id,
                    'share_class_id' => $sharePosition->share_class_id,
                    'tx_type' => $delta > 0 ? 'transfer_in' : 'transfer_out',
                    'quantity' => (string) abs($delta),
                    'tx_ref' => 'SPU-' . $sharePosition->id . '-' . now()->timestamp,
                    'tx_date' => now(),
                    'created_by' => auth()->id(),
                ]);
            }

            $this->capitalValidationService->syncOutstandingUnits($registerId);
            $this->capitalValidationService->assertConstantBalanced($registerId);

            return response()->json($sharePosition->fresh()->load('shareClass.register.company'));
        });
    }
}

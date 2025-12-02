<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProbateCase;
use App\Models\ProbateBeneficiary;
use App\Http\Requests\ProbateCaseRequest;
use App\Http\Requests\ProbateBeneficiaryRequest;
use Illuminate\Http\Request;

class ProbateCaseController extends Controller
{
    public function index(Request $request)
    {
        $data = ProbateCase::with('beneficiaries')->paginate($request->query('per_page', 15));
        return response()->json($data);
    }

    public function store(ProbateCaseRequest $request)
    {
        $payload = $request->validated();
        $case = ProbateCase::create($payload);
        return response()->json($case, 201);
    }

    public function show(ProbateCase $probateCase)
    {
        $probateCase->load('beneficiaries');
        return response()->json($probateCase);
    }

    public function update(ProbateCaseRequest $request, ProbateCase $probateCase)
    {
        $probateCase->update($request->validated());
        return response()->json($probateCase);
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

    // Execute a beneficiary transfer (mark executed)
    public function executeBeneficiary(Request $request, $id)
    {
        $benef = ProbateBeneficiary::findOrFail($id);

        if ($benef->transfer_status === 'executed') {
            return response()->json(['message' => 'Already executed'], 422);
        }

        // Determine source SRA: the deceased shareholder's SRA (if sra_id not specified on beneficiary)
        $sourceSraId = $benef->sra_id;
        if (! $sourceSraId) {
            // try to find a default SRA for the deceased shareholder for the share_class
            $probateCase = $benef->probateCase;
            $source = \DB::table('shareholder_register_accounts')
                ->where('shareholder_id', $probateCase->shareholder_id)
                ->first();
            if (! $source) {
                return response()->json(['message' => 'Source SRA not found for deceased shareholder'], 422);
            }
            $sourceSraId = $source->id;
        }

        $qty = (float) $benef->quantity;
        if ($qty <= 0) {
            return response()->json(['message' => 'Invalid quantity'], 422);
        }

        // Start DB transaction to move shares
        \DB::beginTransaction();
        try {
            // Reduce source position
            $sourcePos = \App\Models\SharePosition::where('sra_id', $sourceSraId)
                ->where('share_class_id', $benef->share_class_id)
                ->lockForUpdate()
                ->first();

            if (! $sourcePos || (float) $sourcePos->quantity < $qty) {
                \DB::rollBack();
                return response()->json(['message' => 'Insufficient source shares'], 422);
            }

            $sourcePos->quantity = (float) $sourcePos->quantity - $qty;
            $sourcePos->save();

            // Increase or create beneficiary position (must have sra_id)
            $targetSraId = $benef->beneficiary_shareholder_id ? 
                \DB::table('shareholder_register_accounts')->where('shareholder_id', $benef->beneficiary_shareholder_id)->value('id') : $benef->sra_id;

            if (! $targetSraId) {
                // create a shareholder_register_accounts entry for beneficiary (minimal)
                $targetSraId = \DB::table('shareholder_register_accounts')->insertGetId([
                    'shareholder_id' => $benef->beneficiary_shareholder_id ?? null,
                    'register_id' => null,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $targetPos = \App\Models\SharePosition::where('sra_id', $targetSraId)
                ->where('share_class_id', $benef->share_class_id)
                ->lockForUpdate()
                ->first();

            if ($targetPos) {
                $targetPos->quantity = (float) $targetPos->quantity + $qty;
                $targetPos->save();
            } else {
                $targetPos = \App\Models\SharePosition::create([
                    'sra_id' => $targetSraId,
                    'share_class_id' => $benef->share_class_id,
                    'quantity' => $qty,
                    'holding_mode' => 'demat',
                ]);
            }

            // Create share_transactions records: transfer_out and transfer_in
            $txRef = 'probate-'.$benef->probate_case_id.'-'. $benef->id .'-'.time();

            \App\Models\ShareTransaction::create([
                'sra_id' => $sourceSraId,
                'share_class_id' => $benef->share_class_id,
                'tx_type' => 'transfer_out',
                'quantity' => $qty,
                'tx_ref' => $txRef,
                'tx_date' => now(),
                'created_by' => $request->user()?->id,
            ]);

            \App\Models\ShareTransaction::create([
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

            \DB::commit();
            return response()->json($benef);
        } catch (\Throwable $e) {
            \DB::rollBack();
            return response()->json(['message' => 'Execution failed', 'error' => $e->getMessage()], 500);
        }
    }
}

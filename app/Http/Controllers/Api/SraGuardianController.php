<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SraGuardian;
use App\Models\Shareholder;
use App\Http\Requests\SraGuardianRequest;
use Illuminate\Http\Request;

class SraGuardianController extends Controller
{
    public function index(Request $request)
    {
        $query = SraGuardian::with(['sra', 'guardianShareholder']);

        if ($request->filled('shareholder_id')) {
            $query->whereHas('sra', function ($q) use ($request) {
                $q->where('shareholder_id', $request->query('shareholder_id'));
            });
        }

        if ($request->filled('sra_id')) {
            $query->where('sra_id', $request->query('sra_id'));
        }

        if ($request->filled('verified_status')) {
            $query->where('verified_status', $request->query('verified_status'));
        }

        $data = $query->paginate($request->query('per_page', 15));
        return response()->json($data);
    }

    public function store(SraGuardianRequest $request)
    {
        $payload = $request->validated();
        if (empty($payload['guardian_name']) && !empty($payload['guardian_shareholder_id'])) {
            $payload['guardian_name'] = Shareholder::query()
                ->where('id', $payload['guardian_shareholder_id'])
                ->value('full_name') ?? 'Guardian';
        }
        $guardian = SraGuardian::create($payload);
        return response()->json($guardian, 201);
    }

    public function show(SraGuardian $sraGuardian)
    {
        $sraGuardian->load(['sra','guardianShareholder']);
        return response()->json($sraGuardian);
    }

    public function update(SraGuardianRequest $request, SraGuardian $sraGuardian)
    {
        $payload = $request->validated();
        if (empty($payload['guardian_name']) && !empty($payload['guardian_shareholder_id'])) {
            $payload['guardian_name'] = Shareholder::query()
                ->where('id', $payload['guardian_shareholder_id'])
                ->value('full_name') ?? $sraGuardian->guardian_name;
        }
        $sraGuardian->update($payload);
        return response()->json($sraGuardian);
    }

    public function destroy(SraGuardian $sraGuardian)
    {
        $sraGuardian->delete();
        return response()->noContent();
    }
}

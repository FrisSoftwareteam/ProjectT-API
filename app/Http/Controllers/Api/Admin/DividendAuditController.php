<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DividendDeclaration;
use App\Models\DividendWorkflowEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DividendAuditController extends Controller
{
    /**
     * 7.1 Dividend Audit Log
     * GET /admin/dividend-declarations/{declaration_id}/audit-logs
     */
    public function index(Request $request, int $declaration_id): JsonResponse
    {
        try {
            DividendDeclaration::findOrFail($declaration_id);

            $perPage = (int) $request->query('per_page', 50);

            $logs = DividendWorkflowEvent::with('actor')
                ->where('dividend_declaration_id', $declaration_id)
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $logs,
                'message' => 'Dividend audit logs retrieved successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving dividend audit logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShareholderCaution;
use App\Models\ShareholderRegisterAccount;
use App\Services\CautionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

/**
 * CautionController
 * Routes (all nested under /sras/{sra_id}/):
 *   GET    /sras/{sra_id}/cautions             → list cautions for this SRA(ShareholderRegisterAccount)
 *   POST   /sras/{sra_id}/cautions             → apply a caution
 *   GET    /sras/{sra_id}/cautions/{id}        → get a specific caution
 *   DELETE /sras/{sra_id}/cautions/{id}        → remove (lift) a caution
 *   GET    /sras/{sra_id}/cautions/{id}/logs   → audit trail
 *
 *   GET    /shareholders/{shareholder_id}/caution-summary → all cautions across registers
 */
class CautionController extends Controller
{
    public function __construct(
        private readonly CautionService $cautionService,
    ) {}

    /**
     * GET /sras/{sra_id}/cautions
     */
    public function index(Request $request, int $sraId): JsonResponse
    {
        try {
            $sra = ShareholderRegisterAccount::with(['shareholder', 'register.company'])
                ->findOrFail($sraId);

            $query = ShareholderCaution::with([
                    'cautionShareClass',
                    'createdBy',
                    'removedBy',
                    'sra.register.company',
                ])
                ->where('sra_id', $sra->id);

            $status = $request->input('status', 'all');
            if ($status === 'active') {
                $query->whereNull('removed_at');
            } elseif ($status === 'removed') {
                $query->whereNotNull('removed_at');
            }

            if ($request->filled('caution_type')) {
                $query->where('caution_type', $request->input('caution_type'));
            }

            $cautions = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'message' => 'Cautions retrieved successfully.',
                'data'    => [
                    'sra' => [
                        'id'               => $sra->id,
                        'shareholder_id'   => $sra->shareholder_id,
                        'shareholder_name' => $sra->shareholder->full_name,
                        'register_id'      => $sra->register_id,
                        'register_name'    => $sra->register->name,
                        'company_name'     => $sra->register->company->name,
                    ],
                    'is_cautioned' => $this->cautionService->isCautioned($sra->id),
                    'cautions'     => $cautions,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Register account not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Error listing cautions: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error retrieving cautions.'], 500);
        }
    }

    /**
     * POST /sras/{sra_id}/cautions
     *
     * Body:
     * {
     *   "scope":              "global" | "company",
     *   "caution_type":       "regulatory" | "legal" | "operational",
     *   "instruction_source": "sec" | "court" | "exchange" | "bank" | "internal",
     *   "reason":             "Fraud investigation pending outcome",
     *   "effective_date":     "2026-04-01"
     * }
     */
    public function store(Request $request, int $sraId): JsonResponse
    {
        try {
            $sra = ShareholderRegisterAccount::with(['register.company', 'shareholder'])
                ->findOrFail($sraId);

            $validated = $request->validate([
                'scope' => [
                    'required',
                    Rule::in(['global', 'company']),
                ],
                'caution_type' => [
                    'required',
                    Rule::in(['regulatory', 'legal', 'operational']),
                ],
                'instruction_source' => [
                    'required',
                    Rule::in(['sec', 'court', 'exchange', 'bank', 'internal']),
                ],
                'reason'         => 'required|string|max:500',
                'supporting_document' => 'nullable|file|max:10240',
                'effective_date' => 'required|date',
            ]);

            $documentPath = null;
            if ($request->hasFile('supporting_document')) {
                $documentPath = $request->file('supporting_document')->store('caution-documents', 'public');
            }
            $validated['supporting_document_path'] = $documentPath;

            $result = $this->cautionService->apply(
                $sra,
                $validated,
                $request->user()->id
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data'    => $result['caution'],
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Register account not found.'], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error applying caution: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error applying caution.'], 500);
        }
    }


    /**
     * GET /sras/{sra_id}/cautions/{caution_id}
     */
    public function show(int $sraId, int $cautionId): JsonResponse
    {
        try {
            ShareholderRegisterAccount::findOrFail($sraId);

            $caution = ShareholderCaution::with([
                    'sra.register.company',
                    'cautionShareClass',
                    'createdBy',
                    'removedBy',
                ])
                ->where('sra_id', $sraId)
                ->findOrFail($cautionId);

            return response()->json([
                'success' => true,
                'message' => 'Caution retrieved successfully.',
                'data'    => $caution,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Caution not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving caution: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error retrieving caution.'], 500);
        }
    }

    /**
     * DELETE /sras/{sra_id}/cautions/{caution_id}
     *
     * Body:
     * {
     *   "removal_reason": "Court order lifted. Account cleared by legal team."
     * }
     */
    public function destroy(Request $request, int $sraId, int $cautionId): JsonResponse
    {
        try {
            ShareholderRegisterAccount::findOrFail($sraId);

            $caution = ShareholderCaution::where('sra_id', $sraId)
                ->findOrFail($cautionId);

            $validated = $request->validate([
                'removal_reason' => 'required|string|max:500',
            ]);

            $result = $this->cautionService->remove(
                $caution,
                $validated['removal_reason'],
                $request->user()->id
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data'    => $caution->fresh(['sra.register.company', 'cautionShareClass', 'createdBy', 'removedBy']),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Caution not found.'], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error removing caution: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error removing caution.'], 500);
        }
    }

    /**
     * GET /sras/{sra_id}/cautions/{caution_id}/logs
     */
    public function logs(int $sraId, int $cautionId): JsonResponse
    {
        try {
            ShareholderRegisterAccount::findOrFail($sraId);

            $caution = ShareholderCaution::with(['cautionShareClass', 'sra.register.company'])
                ->where('sra_id', $sraId)
                ->findOrFail($cautionId);

            $logs = $caution->logs()
                ->with(['actor', 'company', 'cautionShareClass'])
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Audit logs retrieved successfully.',
                'data'    => [
                    'caution' => $caution,
                    'logs'    => $logs,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Caution not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving caution logs: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error retrieving logs.'], 500);
        }
    }

    /**
     * GET /shareholders/{shareholder_id}/caution-summary
     */
    public function summary(int $shareholderId): JsonResponse
    {
        try {
            $shareholder = \App\Models\Shareholder::findOrFail($shareholderId);
            $summary     = $this->cautionService->getShareholderCautionSummary($shareholder->id);

            return response()->json([
                'success' => true,
                'message' => 'Caution summary retrieved successfully.',
                'data'    => [
                    'shareholder_id'   => $shareholder->id,
                    'shareholder_name' => $shareholder->full_name,
                    'is_cautioned'     => $summary['is_cautioned'],
                    'active_count'     => $summary['active_count'],
                    'cautions'         => $summary['cautions'],
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Shareholder not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving caution summary: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error retrieving caution summary.'], 500);
        }
    }
}
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareholderChangeRequestDecisionRequest;
use App\Http\Requests\ShareholderChangeRequestStoreRequest;
use App\Models\Shareholder;
use App\Models\ShareholderChangeRequest;
use App\Services\ShareholderChangeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShareholderChangeRequestController extends Controller
{
    public function __construct(
        protected ShareholderChangeRequestService $changeRequestService
    ) {}

    /**
     * Submit a pending profile update for a shareholder.
     * POST /shareholders/{shareholder}/change-requests
     */
    public function store(ShareholderChangeRequestStoreRequest $request, Shareholder $shareholder): JsonResponse
    {
        try {
            $changeRequest = $this->changeRequestService->submitProfileUpdate(
                $shareholder,
                $request->proposedFields(),
                $request->proposedAddress(),
                $request->validated('reason'),
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Pending shareholder update submitted for approval',
                'data' => $changeRequest,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error submitting shareholder change request: '.$e->getMessage(), [
                'shareholder_id' => $shareholder->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error submitting pending shareholder update',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List pending shareholder updates.
     * GET /shareholder-change-requests
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'nullable|string|in:draft,submitted,verified,approved_level1,approved_level2,rejected,applied',
                'search' => 'nullable|string|max:255',
                'per_page' => 'nullable|integer|min:1|max:100',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
            ]);

            $query = ShareholderChangeRequest::query()
                ->with([
                    'shareholder:id,account_no,first_name,last_name,full_name',
                    'submitter:id,first_name,last_name,email',
                ])
                ->latest('submitted_at');

            if (! empty($validated['status'])) {
                $query->where('status', $validated['status']);
            } else {
                $query->whereNotIn('status', ['applied', 'rejected']);
            }

            if (! empty($validated['search'])) {
                $search = $validated['search'];
                $query->where(function ($builder) use ($search) {
                    $like = '%'.$search.'%';
                    $builder->where('control_no', 'like', $like)
                        ->orWhereHas('shareholder', function ($q) use ($like) {
                            $q->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('full_name', 'like', $like)
                                ->orWhere('account_no', 'like', $like);
                        });
                });
            }

            if (! empty($validated['date_from'])) {
                $query->where('submitted_at', '>=', Carbon::parse($validated['date_from'])->startOfDay());
            }

            if (! empty($validated['date_to'])) {
                $query->where('submitted_at', '<=', Carbon::parse($validated['date_to'])->endOfDay());
            }

            return response()->json([
                'success' => true,
                'data' => $query->paginate($validated['per_page'] ?? 15),
                'message' => 'Pending shareholder updates retrieved successfully',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error retrieving shareholder change requests: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving pending shareholder updates',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View a single pending update: existing values, proposed values, approval history.
     * GET /shareholder-change-requests/{changeRequest}
     */
    public function show(ShareholderChangeRequest $changeRequest): JsonResponse
    {
        $changeRequest->load([
            'shareholder.activeCautions',
            'submitter:id,first_name,last_name,email',
            'approvals.decider:id,first_name,last_name,email',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $changeRequest->id,
                'control_no' => $changeRequest->control_no,
                'status' => $changeRequest->status,
                'reason' => $changeRequest->reason,
                'shareholder' => $changeRequest->shareholder,
                'submitter' => $changeRequest->submitter,
                'submitted_at' => $changeRequest->submitted_at,
                'existing_values' => $changeRequest->payload_old,
                'proposed_values' => $changeRequest->payload_new,
                'approval_history' => $changeRequest->approvals,
            ],
        ]);
    }

    /**
     * Approve a pending update and apply it to the shareholder record.
     * POST /shareholder-change-requests/{changeRequest}/approve
     */
    public function approve(ShareholderChangeRequestDecisionRequest $request, ShareholderChangeRequest $changeRequest): JsonResponse
    {
        if (! $this->canDecide($request, $changeRequest)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to approve bank mandate changes',
            ], 403);
        }

        try {
            $result = $this->changeRequestService->approve($changeRequest, $request->user(), $request->validated('remarks'));

            return response()->json([
                'success' => true,
                'message' => 'Change request approved and applied to shareholder',
                'data' => array_merge(['change_request' => $changeRequest->fresh()], $result),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()['status'][0] ?? 'Validation failed',
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error approving shareholder change request: '.$e->getMessage(), [
                'change_request_id' => $changeRequest->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error approving pending shareholder update',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a pending update.
     * POST /shareholder-change-requests/{changeRequest}/reject
     */
    public function reject(ShareholderChangeRequestDecisionRequest $request, ShareholderChangeRequest $changeRequest): JsonResponse
    {
        if (! $this->canDecide($request, $changeRequest)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to reject bank mandate changes',
            ], 403);
        }

        try {
            $this->changeRequestService->reject($changeRequest, $request->user(), $request->validated('remarks'));

            return response()->json([
                'success' => true,
                'message' => 'Change request rejected',
                'data' => $changeRequest->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()['status'][0] ?? 'Validation failed',
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error rejecting shareholder change request: '.$e->getMessage(), [
                'change_request_id' => $changeRequest->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error rejecting pending shareholder update',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bank mandate changes require the dedicated approve_mandate permission
     * in addition to the general shareholder_change_requests.approve gate
     * already enforced at the route level for every other request type.
     */
    private function canDecide(Request $request, ShareholderChangeRequest $changeRequest): bool
    {
        if ($changeRequest->request_type !== 'bank_mandate') {
            return true;
        }

        return (bool) $request->user()?->can('shareholder_change_requests.approve_mandate');
    }
}

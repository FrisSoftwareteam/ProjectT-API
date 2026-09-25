<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareholderChangeRequestDecisionRequest;
use App\Http\Requests\ShareholderChangeRequestInfoRequest;
use App\Http\Requests\ShareholderChangeRequestStoreRequest;
use App\Models\AdminUser;
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
                'data' => $this->formatChangeRequest($changeRequest),
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
                'shareholder_id' => 'nullable|integer|exists:shareholders,id',
                'status' => 'nullable|string|in:draft,submitted,verified,approved_level1,approved_level2,rejected,applied,info_requested',
                'search' => 'nullable|string|max:255',
                'per_page' => 'nullable|integer|min:1|max:100',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
            ]);

            $query = ShareholderChangeRequest::query()
                ->with([
                    'shareholder:id,account_no,first_name,last_name,full_name',
                    'submitter:id,first_name,last_name,email',
                    'approvals.decider:id,first_name,last_name,email',
                ])
                ->latest('submitted_at');

            if (! empty($validated['shareholder_id'])) {
                $query->where('shareholder_id', $validated['shareholder_id']);
            }

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

            $paginated = $query->paginate($validated['per_page'] ?? 15);
            $paginated->getCollection()->transform(fn (ShareholderChangeRequest $changeRequest) => $this->formatChangeRequest($changeRequest));

            return response()->json([
                'success' => true,
                'data' => $paginated,
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
            'infoRequestedBy:id,first_name,last_name,email',
        ]);

        $formatted = $this->formatChangeRequest($changeRequest);
        $formatted['existing_values'] = $changeRequest->payload_old;
        $formatted['proposed_values'] = $changeRequest->payload_new;
        $formatted['approval_history'] = $changeRequest->approvals->map(fn ($approval) => [
            'level_no' => $approval->level_no,
            'decision' => $approval->decision,
            'remarks' => $approval->remarks,
            'decided_at' => $approval->decided_at,
            'decider' => $this->formatUser($approval->decider),
        ]);

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    /**
     * Approve a pending update and apply it to the shareholder record.
     * POST /shareholder-change-requests/{changeRequest}/approve
     */
    public function approve(ShareholderChangeRequestDecisionRequest $request, ShareholderChangeRequest $changeRequest): JsonResponse
    {
        if ($deny = $this->denyDecision($request, $changeRequest, 'approve')) {
            return $deny;
        }

        try {
            $result = $this->changeRequestService->approve($changeRequest, $request->user(), $request->validated('remarks'));

            return response()->json([
                'success' => true,
                'message' => 'Change request approved and applied to shareholder',
                'data' => array_merge(['change_request' => $this->formatChangeRequest($changeRequest->fresh())], $result),
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
        if ($deny = $this->denyDecision($request, $changeRequest, 'reject')) {
            return $deny;
        }

        try {
            $this->changeRequestService->reject($changeRequest, $request->user(), $request->validated('remarks'));

            return response()->json([
                'success' => true,
                'message' => 'Change request rejected',
                'data' => $this->formatChangeRequest($changeRequest->fresh()),
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
     * Ask the submitter for more information without approving or rejecting.
     * POST /shareholder-change-requests/{changeRequest}/request-info
     */
    public function requestInfo(ShareholderChangeRequestInfoRequest $request, ShareholderChangeRequest $changeRequest): JsonResponse
    {
        if ($deny = $this->denyDecision($request, $changeRequest, 'request more information on')) {
            return $deny;
        }

        try {
            $updated = $this->changeRequestService->requestMoreInfo(
                $changeRequest,
                $request->user(),
                $request->validated('type'),
                $request->validated('note')
            );

            return response()->json([
                'success' => true,
                'message' => 'More information requested from the submitter',
                'data' => $this->formatChangeRequest($updated),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()['status'][0] ?? 'Validation failed',
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error requesting more info on shareholder change request: '.$e->getMessage(), [
                'change_request_id' => $changeRequest->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error requesting more information',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Shared authorization gate for approve/reject/request-info: bank mandate
     * changes require the dedicated approve_mandate permission on top of the
     * general shareholder_change_requests.approve gate already enforced at
     * the route level, and nobody may decide on their own submission.
     */
    private function denyDecision(Request $request, ShareholderChangeRequest $changeRequest, string $action): ?JsonResponse
    {
        if ($changeRequest->submitted_by === $request->user()?->id) {
            return response()->json([
                'success' => false,
                'message' => "You cannot {$action} your own submission",
            ], 403);
        }

        if ($changeRequest->request_type === 'bank_mandate' && ! $request->user()?->can('shareholder_change_requests.approve_mandate')) {
            return response()->json([
                'success' => false,
                'message' => "You are not authorized to {$action} bank mandate changes",
            ], 403);
        }

        return null;
    }

    /**
     * Consistent shape for a change request across index/show/store/approve/reject:
     * every previously-existing field stays, plus submitter/approver as
     * {id, name, email} instead of a bare id.
     */
    private function formatChangeRequest(ShareholderChangeRequest $changeRequest): array
    {
        $changeRequest->loadMissing([
            'shareholder:id,account_no,first_name,last_name,full_name',
            'submitter:id,first_name,last_name,email',
            'approvals.decider:id,first_name,last_name,email',
            'infoRequestedBy:id,first_name,last_name,email',
        ]);

        $latestDecision = $changeRequest->approvals->sortByDesc('decided_at')->first();

        return [
            'id' => $changeRequest->id,
            'shareholder_id' => $changeRequest->shareholder_id,
            'shareholder' => $changeRequest->shareholder,
            'request_type' => $changeRequest->request_type,
            'payload_old' => $changeRequest->payload_old,
            'payload_new' => $changeRequest->payload_new,
            'reason' => $changeRequest->reason,
            'status' => $changeRequest->status,
            'control_no' => $changeRequest->control_no,
            'submitted_by' => $changeRequest->submitted_by,
            'submitted_at' => $changeRequest->submitted_at,
            'updated_at' => $changeRequest->updated_at,
            'submitter' => $this->formatUser($changeRequest->submitter),
            'approver' => $latestDecision ? $this->formatUser($latestDecision->decider) : null,
            'info_requested_type' => $changeRequest->info_requested_type,
            'info_requested_note' => $changeRequest->info_requested_note,
            'info_requested_by' => $this->formatUser($changeRequest->infoRequestedBy),
            'info_requested_at' => $changeRequest->info_requested_at,
        ];
    }

    private function formatUser(?AdminUser $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
        ];
    }
}

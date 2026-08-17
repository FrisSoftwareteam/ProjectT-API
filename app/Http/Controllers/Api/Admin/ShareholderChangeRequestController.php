<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareholderChangeRequestDecisionRequest;
use App\Http\Requests\ShareholderChangeRequestStoreRequest;
use App\Models\Shareholder;
use App\Models\ShareholderChangeApproval;
use App\Models\ShareholderChangeRequest;
use App\Services\ShareholderChangeRequestReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShareholderChangeRequestController extends Controller
{
    public function __construct(
        protected ShareholderChangeRequestReferenceService $referenceService
    ) {}

    /**
     * Submit a pending profile update for a shareholder.
     * POST /shareholders/{shareholder}/change-requests
     */
    public function store(ShareholderChangeRequestStoreRequest $request, Shareholder $shareholder): JsonResponse
    {
        try {
            $proposedFields = $request->proposedFields();
            $proposedAddress = $request->proposedAddress();

            $payloadOld = collect($proposedFields)
                ->keys()
                ->mapWithKeys(fn ($field) => [$field => $shareholder->{$field}])
                ->toArray();
            $payloadNew = $proposedFields;

            if ($proposedAddress !== null) {
                $primaryAddress = $shareholder->addresses()->where('is_primary', true)->first();

                $payloadOld['address'] = collect($proposedAddress)
                    ->keys()
                    ->mapWithKeys(fn ($field) => [$field => $primaryAddress?->{$field}])
                    ->toArray();
                $payloadNew['address'] = $proposedAddress;
            }

            $changeRequest = ShareholderChangeRequest::create([
                'shareholder_id' => $shareholder->id,
                'request_type' => $this->inferRequestType($proposedFields, $proposedAddress !== null),
                'payload_old' => $payloadOld,
                'payload_new' => $payloadNew,
                'reason' => $request->validated('reason'),
                'status' => 'submitted',
                'control_no' => $this->referenceService->generate(),
                'submitted_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pending shareholder update submitted for approval',
                'data' => $changeRequest,
            ], 201);
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
        if ($changeRequest->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending (submitted) updates can be approved',
            ], 422);
        }

        $addressPayload = $changeRequest->payload_new['address'] ?? null;

        if ($addressPayload !== null && ! $changeRequest->shareholder->hasActiveAddress()) {
            return response()->json([
                'success' => false,
                'message' => 'Shareholder has no primary address on file to update. Add one via the addresses endpoint before approving this request.',
            ], 422);
        }

        try {
            $shareholder = DB::transaction(function () use ($request, $changeRequest, $addressPayload) {
                $shareholder = Shareholder::findOrFail($changeRequest->shareholder_id);

                $flatPayload = Arr::except($changeRequest->payload_new, ['address']);
                if (! empty($flatPayload)) {
                    $shareholder->update($flatPayload);
                }

                if ($addressPayload !== null) {
                    $shareholder->addresses()->where('is_primary', true)->first()->update($addressPayload);
                }

                ShareholderChangeApproval::create([
                    'change_request_id' => $changeRequest->id,
                    'level_no' => 1,
                    'decision' => 'approved',
                    'decided_by' => $request->user()->id,
                    'decided_at' => now(),
                    'remarks' => $request->validated('remarks'),
                ]);

                $changeRequest->update(['status' => 'applied']);

                return $shareholder->fresh()->load('activeCautions', 'addresses');
            });

            return response()->json([
                'success' => true,
                'message' => 'Change request approved and applied to shareholder',
                'data' => [
                    'change_request' => $changeRequest->fresh(),
                    'shareholder' => $shareholder,
                ],
            ]);
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
        if ($changeRequest->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending (submitted) updates can be rejected',
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $changeRequest) {
                ShareholderChangeApproval::create([
                    'change_request_id' => $changeRequest->id,
                    'level_no' => 1,
                    'decision' => 'rejected',
                    'decided_by' => $request->user()->id,
                    'decided_at' => now(),
                    'remarks' => $request->validated('remarks'),
                ]);

                $changeRequest->update(['status' => 'rejected']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Change request rejected',
                'data' => $changeRequest->fresh(),
            ]);
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
     * Derive a friendly request_type label from which fields were actually
     * proposed. Falls back to the generic 'profile_update' whenever a
     * submission mixes fields from more than one category.
     */
    private function inferRequestType(array $flatFields, bool $hasAddress): string
    {
        if ($hasAddress) {
            return empty($flatFields) ? 'address_change' : 'profile_update';
        }

        $keys = array_keys($flatFields);

        if ($keys === ['email']) {
            return 'email_change';
        }

        if ($keys === ['phone']) {
            return 'phone_change';
        }

        $nameFields = ['first_name', 'last_name', 'middle_name'];
        if (! empty($keys) && empty(array_diff($keys, $nameFields))) {
            return 'name_change';
        }

        return 'profile_update';
    }
}

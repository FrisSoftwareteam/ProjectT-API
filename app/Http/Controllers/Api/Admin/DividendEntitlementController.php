<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DividendDeclaration;
use App\Models\SharePosition;
use App\Models\ShareholderRegisterAccount;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\DividendWorkflowEvent;
use App\Models\DividendEntitlementRun;
use App\Models\DividendEntitlement;
use App\Models\DividendPayment;
use App\Models\DividendApprovalAction;
use App\Models\DividendApprovalDelegation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DividendEntitlementController extends Controller
{
    /**
     * 1.1 Create Dividend Declaration (Draft)
     * POST /admin/registers/{register_id}/dividend-declarations
     */
    public function store(Request $request, int $register_id): JsonResponse
    {
        try {
            // Validate request
            $validated = $request->validate([
                'dividend_declaration_no' => 'required|string|max:100|unique:dividend_declarations,dividend_declaration_no',
                'period_label' => 'required|string|max:100',
                'description' => 'nullable|string|max:255',
                'initiator' => 'required|in:operations,mutual_funds',
                'supplementary_of_declaration_id' => 'nullable|exists:dividend_declarations,id',
                'share_class_ids' => 'required|array|min:1',
                'share_class_ids.*' => 'required|exists:share_classes,id',
                'rate_per_share' => 'nullable|numeric|min:0|max:999999999999.999999',
                'announcement_date' => 'nullable|date',
                'record_date' => 'nullable|date',
                'payment_date' => 'nullable|date',
                'exclude_caution_accounts' => 'nullable|boolean',
                'require_active_bank_mandate' => 'nullable|boolean',
            ]);
            $dateValidationError = $this->validateDateSequence($validated);
            if ($dateValidationError) {
                return $dateValidationError;
            }

            // Verify register exists and get company_id
            $register = Register::findOrFail($register_id);
            $register->load('company');

            if (!$register->company || !$register->company->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company must be active to create a dividend declaration',
                    'errors' => [
                        'company_id' => ['Company is not active']
                    ]
                ], 422);
            }

            // Verify all share classes belong to this register
            $shareClasses = ShareClass::whereIn('id', $validated['share_class_ids'])
                ->where('register_id', $register_id)
                ->get();

            if ($shareClasses->count() !== count($validated['share_class_ids'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more share classes do not belong to this register',
                    'errors' => [
                        'share_class_ids' => ['All share classes must belong to the specified register']
                    ]
                ], 422);
            }

            // Check for duplicate period_label within company
            $exists = DividendDeclaration::where('company_id', $register->company_id)
                ->where('period_label', $validated['period_label'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'A dividend declaration with this period label already exists for this company',
                    'errors' => [
                        'period_label' => ['This period label is already in use for this company']
                    ]
                ], 422);
            }

            DB::beginTransaction();

            try {
                // Prevent client-supplied totals from being saved
                unset(
                    $validated['total_gross_amount'],
                    $validated['total_tax_amount'],
                    $validated['total_net_amount'],
                    $validated['rounding_residue'],
                    $validated['eligible_shareholders_count']
                );

                // Create dividend declaration
                $declaration = DividendDeclaration::create([
                    'dividend_declaration_no' => $validated['dividend_declaration_no'],
                    'company_id' => $register->company_id,
                    'register_id' => $register_id,
                    'supplementary_of_declaration_id' => $validated['supplementary_of_declaration_id'] ?? null,
                    'period_label' => $validated['period_label'],
                    'description' => $validated['description'] ?? null,
                    'initiator' => $validated['initiator'],
                    'action_type' => 'DIVIDEND',
                    'declaration_method' => 'RATE_PER_SHARE',
                    'rate_per_share' => $validated['rate_per_share'] ?? null,
                    'announcement_date' => $validated['announcement_date'] ?? null,
                    'record_date' => $validated['record_date'] ?? null,
                    'payment_date' => $validated['payment_date'] ?? null,
                    'exclude_caution_accounts' => $validated['exclude_caution_accounts'] ?? false,
                    'require_active_bank_mandate' => $validated['require_active_bank_mandate'] ?? true,
                    'status' => 'DRAFT',
                    'is_frozen' => false,
                    'created_by' => $request->user()->id,
                ]);

                // Attach share classes
                $declaration->shareClasses()->attach($validated['share_class_ids']);

                // Create workflow event
                DividendWorkflowEvent::create([
                    'dividend_declaration_id' => $declaration->id,
                    'event_type' => 'CREATED',
                    'actor_id' => $request->user()->id,
                    'note' => 'Dividend declaration created',
                ]);

                if (!empty($validated['supplementary_of_declaration_id'])) {
                    DividendWorkflowEvent::create([
                        'dividend_declaration_id' => $declaration->id,
                        'event_type' => 'SUPPLEMENTARY_CREATED',
                        'actor_id' => $request->user()->id,
                        'note' => 'Supplementary declaration linked to #' . $validated['supplementary_of_declaration_id'],
                    ]);
                }

                DB::commit();

                // Load relationships
                $declaration->load(['shareClasses', 'register.company', 'creator']);

                Log::info('Dividend declaration created', [
                    'declaration_id' => $declaration->id,
                    'register_id' => $register_id,
                    'company_id' => $register->company_id,
                    'period_label' => $declaration->period_label,
                    'created_by' => $request->user()->id
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Dividend declaration created successfully',
                    'data' => [
                        'declaration' => $declaration,
                        'summary' => $this->buildSummary($declaration),
                    ],
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Register not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error creating dividend declaration: ' . $e->getMessage(), [
                'register_id' => $register_id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating dividend declaration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 1.2 Get Dividend Declaration (Full Context)
     * GET /admin/dividend-declarations/{declaration_id}
     */
    public function show(int $declaration_id): JsonResponse
    {
        try {
            $declaration = DividendDeclaration::with([
                'shareClasses',
                'register.company',
                'creator',
                'submitter',
                'approver',
                'rejecter',
                'approvalActions.actor',
                'delegations.reliever',
                'workflowEvents.actor'
            ])->findOrFail($declaration_id);

            return response()->json([
                'success' => true,
                'data' => [
                    'declaration' => $declaration,
                    'summary' => $this->buildSummary($declaration),
                    'active_step' => $declaration->current_approval_step,
                ],
                'message' => 'Dividend declaration retrieved successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving dividend declaration: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving dividend declaration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 1.3 Update Dividend Declaration (Draft Only)
     * PUT /admin/dividend-declarations/{declaration_id}
     */
    public function update(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = DividendDeclaration::findOrFail($declaration_id);

            // Only allow editing drafts
            if (!$declaration->canBeEdited()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only draft declarations can be edited',
                    'errors' => [
                        'status' => ['This declaration is not in DRAFT status and cannot be edited']
                    ]
                ], 422);
            }

            $validated = $request->validate([
                'dividend_declaration_no' => 'nullable|string|max:100|unique:dividend_declarations,dividend_declaration_no,' . $declaration->id,
                'period_label' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:255',
                'initiator' => 'nullable|in:operations,mutual_funds',
                'share_class_ids' => 'nullable|array|min:1',
                'share_class_ids.*' => 'nullable|exists:share_classes,id',
                'rate_per_share' => 'nullable|numeric|min:0|max:999999999999.999999',
                'announcement_date' => 'nullable|date',
                'record_date' => 'nullable|date',
                'payment_date' => 'nullable|date',
                'exclude_caution_accounts' => 'nullable|boolean',
                'require_active_bank_mandate' => 'nullable|boolean',
            ]);
            $dateValidationError = $this->validateDateSequence($validated);
            if ($dateValidationError) {
                return $dateValidationError;
            }

            DB::beginTransaction();

            try {
                // Check for duplicate period_label if it's being changed
                if (isset($validated['period_label']) && $validated['period_label'] !== $declaration->period_label) {
                    $exists = DividendDeclaration::where('company_id', $declaration->company_id)
                        ->where('period_label', $validated['period_label'])
                        ->where('id', '!=', $declaration->id)
                        ->exists();

                    if ($exists) {
                        return response()->json([
                            'success' => false,
                            'message' => 'A dividend declaration with this period label already exists',
                            'errors' => [
                                'period_label' => ['This period label is already in use for this company']
                            ]
                        ], 422);
                    }
                }

                // Update share classes if provided
                if (isset($validated['share_class_ids'])) {
                    // Verify all share classes belong to this register
                    $shareClasses = ShareClass::whereIn('id', $validated['share_class_ids'])
                        ->where('register_id', $declaration->register_id)
                        ->get();

                    if ($shareClasses->count() !== count($validated['share_class_ids'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'One or more share classes do not belong to this register',
                            'errors' => [
                                'share_class_ids' => ['All share classes must belong to the specified register']
                            ]
                        ], 422);
                    }

                    $declaration->shareClasses()->sync($validated['share_class_ids']);
                    unset($validated['share_class_ids']);
                }

                // Prevent client-supplied totals from being saved
                unset(
                    $validated['total_gross_amount'],
                    $validated['total_tax_amount'],
                    $validated['total_net_amount'],
                    $validated['rounding_residue'],
                    $validated['eligible_shareholders_count']
                );

                // Update declaration
                $declaration->update($validated);

                // Create workflow event
                DividendWorkflowEvent::create([
                    'dividend_declaration_id' => $declaration->id,
                    'event_type' => 'UPDATED',
                    'actor_id' => $request->user()->id,
                    'note' => 'Dividend declaration updated',
                ]);

                DB::commit();

                $declaration->load(['shareClasses', 'register.company', 'creator', 'workflowEvents.actor']);

                Log::info('Dividend declaration updated', [
                    'declaration_id' => $declaration->id,
                    'updated_by' => $request->user()->id
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Dividend declaration updated successfully',
                    'data' => [
                        'declaration' => $declaration,
                        'summary' => $this->buildSummary($declaration),
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating dividend declaration: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating dividend declaration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 1.4 Cancel Draft Declaration
     * DELETE /admin/dividend-declarations/{declaration_id}
     */
    public function destroy(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = DividendDeclaration::findOrFail($declaration_id);

            // Only allow deleting drafts
            if (!$declaration->canBeDeleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only draft declarations can be deleted',
                    'errors' => [
                        'status' => ['This declaration is no longer in draft status and cannot be deleted']
                    ]
                ], 422);
            }

            DB::beginTransaction();

            try {
                // Delete related records (cascade should handle most, but being explicit)
                $declaration->shareClasses()->detach();
                $declaration->workflowEvents()->delete();
                $declaration->entitlementRuns()->delete();
                
                // Delete the declaration
                $declaration->delete();

                DB::commit();

                Log::info('Dividend declaration deleted', [
                    'declaration_id' => $declaration_id,
                    'deleted_by' => $request->user()->id
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Dividend declaration deleted successfully',
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting dividend declaration: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting dividend declaration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 2.1 Generate Entitlement Preview (Compute & Paginate)
     * POST /admin/dividend-declarations/{declaration_id}/preview
     */
    public function generatePreview(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);
            
            // Validate declaration
            $validationError = $this->validateDeclaration($declaration);
            if ($validationError) {
                return $validationError;
            }

            $perPage = (int) $request->input('per_page', 50);
            $page = (int) $request->input('page', 1);

            // Get eligible accounts
            $accounts = $this->getEligibleAccounts($declaration, $perPage);

            // Compute entitlements
            $result = $this->computeEntitlements($accounts, $declaration);

            // Calculate grand totals
            $grandTotals = $this->calculateGrandTotals($declaration);

            // Format response
            return $this->formatSuccessResponse($declaration, $result, $accounts, $grandTotals);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            return $this->handleError($e, $declaration_id);
        }
    }

    /**
     * 2.2 Fetch Entitlement Preview (Paginated)
     * GET /admin/dividend-declarations/{declaration_id}/preview
     */
    public function fetchPreview(Request $request, int $declaration_id): JsonResponse
    {
        return $this->generatePreview($request, $declaration_id);
    }

    /**
     * 3.1 Submit Dividend Declaration
     * POST /admin/dividend-declarations/{declaration_id}/submit
     */
    public function submit(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);

            if (!$declaration->isDraft() && !$declaration->isQueryRaised()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only draft declarations can be submitted',
                    'errors' => [
                        'status' => ['Declaration must be in DRAFT status']
                    ]
                ], 422);
            }

            $validationError = $this->validateDeclaration($declaration);
            if ($validationError) {
                return $validationError;
            }

            DB::beginTransaction();

            try {
                // Reset approval chain whenever declaration is submitted.
                $declaration->approvalActions()->delete();

                $declaration->update([
                    'status' => 'SUBMITTED',
                    'submitted_at' => now(),
                    'submitted_by' => $request->user()->id,
                    'current_approval_step' => 1,
                ]);

                DividendWorkflowEvent::create([
                    'dividend_declaration_id' => $declaration->id,
                    'event_type' => 'SUBMITTED',
                    'actor_id' => $request->user()->id,
                    'note' => 'Dividend declaration submitted',
                ]);

                DB::commit();

                $declaration->load(['shareClasses', 'register.company', 'creator', 'submitter', 'workflowEvents.actor']);

                return response()->json([
                    'success' => true,
                    'message' => 'Dividend declaration submitted successfully',
                    'data' => [
                        'declaration' => $declaration,
                        'summary' => $this->buildSummary($declaration),
                        'active_step' => $declaration->current_approval_step,
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error submitting dividend declaration: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error submitting dividend declaration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 3.2 Verify Dividend Declaration
     * POST /admin/dividend-declarations/{declaration_id}/verify
     */
    public function verify(Request $request, int $declaration_id): JsonResponse
    {
        // Backward-compatible endpoint: verify maps to step 1 (IT approval).
        return $this->approve($request, $declaration_id);
    }

    /**
     * 3.3 Approve Dividend Declaration
     * POST /admin/dividend-declarations/{declaration_id}/approve
     */
    public function approve(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);

            if (!in_array($declaration->status, ['SUBMITTED', 'QUERY_RAISED', 'APPROVED'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Declaration is not in an approvable state',
                    'errors' => [
                        'status' => ['Declaration must be SUBMITTED, QUERY_RAISED, or APPROVED (step 3 pending)']
                    ]
                ], 422);
            }

            $validationError = $this->validateDeclaration($declaration);
            if ($validationError) {
                return $validationError;
            }

            DB::beginTransaction();

            try {
                $activeStep = (int) ($declaration->current_approval_step ?? 0);
                if (!in_array($activeStep, [1, 2, 3], true)) {
                    $activeStep = 1;
                }

                $requiredRoles = $this->requiredRolesForStep($declaration, $activeStep);
                $actorRole = $this->resolveActorRoleForCurrentStep($request->user(), $declaration, $requiredRoles);

                if (!$actorRole) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not authorized to approve the active step',
                    ], 403);
                }

                if ($this->userHasApprovedOtherStep($declaration->id, $request->user()->id, $activeStep)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A user cannot approve more than one step in the same declaration',
                    ], 422);
                }

                if ($this->roleAlreadyApproved($declaration->id, $actorRole)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Approval already recorded for this role',
                    ], 422);
                }

                DividendApprovalAction::create([
                    'dividend_declaration_id' => $declaration->id,
                    'step_no' => $activeStep,
                    'role_code' => $actorRole,
                    'decision' => 'APPROVED',
                    'actor_id' => $request->user()->id,
                    'comment' => null,
                    'acted_at' => now(),
                ]);

                DividendWorkflowEvent::create([
                    'dividend_declaration_id' => $declaration->id,
                    'event_type' => 'STEP_APPROVED',
                    'actor_id' => $request->user()->id,
                    'note' => 'Step ' . $activeStep . ' approved as ' . $actorRole,
                ]);

                $stepComplete = $this->isStepComplete($declaration, $activeStep);
                if ($stepComplete && $activeStep < 3) {
                    $declaration->update([
                        'status' => 'SUBMITTED',
                        'current_approval_step' => $activeStep + 1,
                    ]);
                } elseif ($stepComplete && $activeStep === 3) {
                    $declaration->update([
                        'status' => 'APPROVED',
                        'approved_at' => now(),
                        'approved_by' => $request->user()->id,
                        'current_approval_step' => 3,
                    ]);

                    DividendWorkflowEvent::create([
                        'dividend_declaration_id' => $declaration->id,
                        'event_type' => 'APPROVED',
                        'actor_id' => $request->user()->id,
                        'note' => 'All approval steps completed',
                    ]);
                } else {
                    $declaration->update([
                        'status' => 'SUBMITTED',
                        'current_approval_step' => 3,
                    ]);
                }

                DB::commit();

                $declaration->load(['shareClasses', 'register.company', 'approver', 'workflowEvents.actor', 'approvalActions.actor']);

                return response()->json([
                    'success' => true,
                    'message' => 'Approval recorded successfully',
                    'data' => [
                        'declaration' => $declaration,
                        'summary' => $this->buildSummary($declaration),
                        'active_step' => $declaration->current_approval_step,
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error approving dividend declaration: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error approving dividend declaration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 3.4 Reject Dividend Declaration
     * POST /admin/dividend-declarations/{declaration_id}/reject
     */
    public function reject(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);

            if (!in_array($declaration->status, ['SUBMITTED', 'QUERY_RAISED', 'APPROVED'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only declarations in active approval can be rejected',
                    'errors' => [
                        'status' => ['Declaration must be in SUBMITTED, QUERY_RAISED, or APPROVED status']
                    ]
                ], 422);
            }

            $validated = $request->validate([
                'reason' => 'required|string|max:255',
            ]);

            DB::beginTransaction();

            try {
                $activeStep = (int) ($declaration->current_approval_step ?? 0);
                if (!in_array($activeStep, [1, 2, 3], true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No active approval step found for this declaration',
                    ], 422);
                }

                $requiredRoles = $this->requiredRolesForStep($declaration, $activeStep);
                $actorRole = $this->resolveActorRoleForCurrentStep($request->user(), $declaration, $requiredRoles);
                if (!$actorRole) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only the active step approver can reject',
                    ], 403);
                }

                DividendApprovalAction::create([
                    'dividend_declaration_id' => $declaration->id,
                    'step_no' => $activeStep,
                    'role_code' => $actorRole,
                    'decision' => 'REJECTED',
                    'actor_id' => $request->user()->id,
                    'comment' => $validated['reason'],
                    'acted_at' => now(),
                ]);

                $declaration->update([
                    'status' => 'REJECTED',
                    'rejected_at' => now(),
                    'rejected_by' => $request->user()->id,
                    'rejection_reason' => $validated['reason'],
                    'current_approval_step' => null,
                ]);

                DividendWorkflowEvent::create([
                    'dividend_declaration_id' => $declaration->id,
                    'event_type' => 'REJECTED',
                    'actor_id' => $request->user()->id,
                    'note' => $validated['reason'],
                ]);

                DB::commit();

                $declaration->load(['shareClasses', 'register.company', 'rejecter', 'workflowEvents.actor']);

                return response()->json([
                    'success' => true,
                    'message' => 'Dividend declaration rejected successfully',
                    'data' => [
                        'declaration' => $declaration,
                        'summary' => $this->buildSummary($declaration),
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error rejecting dividend declaration: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error rejecting dividend declaration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Raise query at active approval step.
     */
    public function raiseQuery(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);

            if (!in_array($declaration->status, ['SUBMITTED', 'APPROVED', 'QUERY_RAISED'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Declaration is not in active approval workflow',
                ], 422);
            }

            $validated = $request->validate([
                'comment' => 'required|string|max:255',
            ]);

            $activeStep = (int) ($declaration->current_approval_step ?? 0);
            $requiredRoles = $this->requiredRolesForStep($declaration, $activeStep);
            $actorRole = $this->resolveActorRoleForCurrentStep($request->user(), $declaration, $requiredRoles);
            if (!$actorRole) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only active step approvers can raise a query',
                ], 403);
            }

            DB::transaction(function () use ($declaration, $request, $validated, $activeStep, $actorRole) {
                DividendApprovalAction::create([
                    'dividend_declaration_id' => $declaration->id,
                    'step_no' => $activeStep,
                    'role_code' => $actorRole,
                    'decision' => 'QUERY_RAISED',
                    'actor_id' => $request->user()->id,
                    'comment' => $validated['comment'],
                    'acted_at' => now(),
                ]);

                $declaration->update([
                    'status' => 'QUERY_RAISED',
                ]);

                DividendWorkflowEvent::create([
                    'dividend_declaration_id' => $declaration->id,
                    'event_type' => 'QUERY_RAISED',
                    'actor_id' => $request->user()->id,
                    'note' => $validated['comment'],
                ]);
            });

            $declaration->refresh()->load(['approvalActions.actor', 'workflowEvents.actor']);

            return response()->json([
                'success' => true,
                'message' => 'Query raised successfully',
                'data' => [
                    'declaration' => $declaration,
                    'summary' => $this->buildSummary($declaration),
                    'active_step' => $declaration->current_approval_step,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error raising dividend query: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error raising query',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Respond to a raised query.
     */
    public function respondQuery(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);
            if (!$declaration->isQueryRaised()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active query to respond to',
                ], 422);
            }

            $validated = $request->validate([
                'comment' => 'required|string|max:255',
            ]);

            $declaration->update(['status' => 'SUBMITTED']);
            DividendWorkflowEvent::create([
                'dividend_declaration_id' => $declaration->id,
                'event_type' => 'QUERY_RESPONDED',
                'actor_id' => $request->user()->id,
                'note' => $validated['comment'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Query response recorded',
                'data' => [
                    'declaration' => $declaration->fresh(),
                    'summary' => $this->buildSummary($declaration->fresh()),
                    'active_step' => $declaration->current_approval_step,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error responding to query',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign reliever for a declaration/role.
     */
    public function assignDelegation(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);
            $validated = $request->validate([
                'role_code' => 'required|in:IT,OVERSIGHT_OPS,OVERSIGHT_MF,ACCOUNTS,AUDIT',
                'reliever_user_id' => 'required|exists:admin_users,id',
            ]);

            $delegation = DividendApprovalDelegation::firstOrCreate([
                'dividend_declaration_id' => $declaration->id,
                'role_code' => $validated['role_code'],
                'reliever_user_id' => $validated['reliever_user_id'],
            ], [
                'assigned_by' => $request->user()->id,
                'created_at' => now(),
            ]);

            DividendWorkflowEvent::create([
                'dividend_declaration_id' => $declaration->id,
                'event_type' => 'DELEGATION_ASSIGNED',
                'actor_id' => $request->user()->id,
                'note' => 'Reliever assigned for role ' . $validated['role_code'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Delegation assigned successfully',
                'data' => $delegation,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error assigning delegation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive declaration before go-live.
     */
    public function archive(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);
            if ($declaration->isLive() || $declaration->isRejected()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Live or rejected declarations cannot be archived',
                ], 422);
            }

            $previousStatus = $declaration->status;
            $declaration->update([
                'status' => 'ARCHIVED',
                'archived_at' => now(),
                'archived_from_status' => $previousStatus,
            ]);

            DividendWorkflowEvent::create([
                'dividend_declaration_id' => $declaration->id,
                'event_type' => 'ARCHIVED',
                'actor_id' => $request->user()->id,
                'note' => 'Declaration archived from ' . $previousStatus,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Declaration archived successfully',
                'data' => $declaration->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error archiving declaration',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resume archived declaration. Approval cycle restarts from step 1.
     */
    public function resume(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);
            if (!$declaration->isArchived()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only archived declarations can be resumed',
                ], 422);
            }

            DB::transaction(function () use ($declaration, $request) {
                $declaration->approvalActions()->delete();
                $declaration->update([
                    'status' => 'DRAFT',
                    'current_approval_step' => null,
                    'submitted_at' => null,
                    'submitted_by' => null,
                    'approved_at' => null,
                    'approved_by' => null,
                    'archived_at' => null,
                    'archived_from_status' => null,
                ]);

                DividendWorkflowEvent::create([
                    'dividend_declaration_id' => $declaration->id,
                    'event_type' => 'RESUMED',
                    'actor_id' => $request->user()->id,
                    'note' => 'Declaration resumed and approval cycle reset',
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Declaration resumed successfully',
                'data' => $declaration->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error resuming declaration',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Go live after full approvals.
     */
    public function goLive(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = $this->loadDeclaration($declaration_id);
            if (!$declaration->isApproved()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only approved declarations can go live',
                ], 422);
            }

            if (!$this->isStepComplete($declaration, 3)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Finance and Risk approvals are incomplete',
                ], 422);
            }

            DB::beginTransaction();
            try {
                $totals = $this->calculateGrandTotalsRaw($declaration);
                $run = $this->freezeEntitlements($declaration, $request->user()->id, $totals);
                $this->generatePaymentRecords($declaration, $run->id, $request->user()->id);

                $declaration->update([
                    'status' => 'LIVE',
                    'live_at' => now(),
                    'is_frozen' => true,
                    'total_gross_amount' => $totals['total_gross_amount'],
                    'total_tax_amount' => $totals['total_tax_amount'],
                    'total_net_amount' => $totals['total_net_amount'],
                    'rounding_residue' => $totals['rounding_residue'],
                    'eligible_shareholders_count' => $totals['eligible_shareholders_count'],
                ]);

                DividendWorkflowEvent::create([
                    'dividend_declaration_id' => $declaration->id,
                    'event_type' => 'GO_LIVE',
                    'actor_id' => $request->user()->id,
                    'note' => 'Declaration moved to LIVE',
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Dividend declaration is now LIVE',
                    'data' => [
                        'declaration' => $declaration->fresh(),
                        'summary' => $this->buildSummary($declaration->fresh()),
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing go live',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ========================================================================
    // PRIVATE HELPER METHODS
    // ========================================================================

    private function requiredRolesForStep(DividendDeclaration $declaration, int $stepNo): array
    {
        if ($stepNo === 1) {
            return ['IT'];
        }
        if ($stepNo === 2) {
            return [$declaration->initiator === 'mutual_funds' ? 'OVERSIGHT_MF' : 'OVERSIGHT_OPS'];
        }
        if ($stepNo === 3) {
            return ['ACCOUNTS', 'AUDIT'];
        }

        return [];
    }

    private function roleNameMap(): array
    {
        return [
            'IT' => ['Head of IT', 'IT Approval', 'IT'],
            'OVERSIGHT_OPS' => ['Operations Approval Role', 'Operations'],
            'OVERSIGHT_MF' => ['Mutual Funds Approval Role', 'Mutual Funds'],
            'ACCOUNTS' => ['Accounts'],
            'AUDIT' => ['Audit', 'Internal Audit'],
        ];
    }

    private function resolveActorRoleForCurrentStep($actor, DividendDeclaration $declaration, array $requiredRoles): ?string
    {
        foreach ($requiredRoles as $roleCode) {
            if ($this->canUserActForRole($actor, $declaration, $roleCode)) {
                return $roleCode;
            }
        }
        return null;
    }

    private function canUserActForRole($actor, DividendDeclaration $declaration, string $roleCode): bool
    {
        $roleNames = $this->roleNameMap()[$roleCode] ?? [];
        foreach ($roleNames as $roleName) {
            if ($actor->hasRole($roleName)) {
                return true;
            }
        }

        return DividendApprovalDelegation::where('dividend_declaration_id', $declaration->id)
            ->where('role_code', $roleCode)
            ->where('reliever_user_id', $actor->id)
            ->exists();
    }

    private function roleAlreadyApproved(int $declarationId, string $roleCode): bool
    {
        return DividendApprovalAction::where('dividend_declaration_id', $declarationId)
            ->where('role_code', $roleCode)
            ->where('decision', 'APPROVED')
            ->exists();
    }

    private function userHasApprovedOtherStep(int $declarationId, int $userId, int $activeStep): bool
    {
        return DividendApprovalAction::where('dividend_declaration_id', $declarationId)
            ->where('actor_id', $userId)
            ->where('decision', 'APPROVED')
            ->where('step_no', '!=', $activeStep)
            ->exists();
    }

    private function isStepComplete(DividendDeclaration $declaration, int $stepNo): bool
    {
        $requiredRoles = $this->requiredRolesForStep($declaration, $stepNo);
        if (empty($requiredRoles)) {
            return false;
        }

        foreach ($requiredRoles as $roleCode) {
            if (!$this->roleAlreadyApproved($declaration->id, $roleCode)) {
                return false;
            }
        }

        return true;
    }

    private function buildSummary(DividendDeclaration $declaration): array
    {
        $declaration->loadMissing(['register.company']);

        $canCalculate = $declaration->rate_per_share && $declaration->record_date;

        $totals = $declaration->is_frozen
            ? [
                'total_gross_amount' => (float) $declaration->total_gross_amount,
                'total_tax_amount' => (float) $declaration->total_tax_amount,
                'total_net_amount' => (float) $declaration->total_net_amount,
                'eligible_shareholders_count' => (int) $declaration->eligible_shareholders_count,
            ]
            : ($canCalculate ? $this->calculateGrandTotalsRaw($declaration) : [
                'total_gross_amount' => 0.0,
                'total_tax_amount' => 0.0,
                'total_net_amount' => 0.0,
                'eligible_shareholders_count' => 0,
            ]);

        return [
            'dividend_declaration_number' => $declaration->dividend_declaration_no,
            'company_name' => $declaration->register?->company?->name,
            'register_name' => $declaration->register?->name,
            'period_label' => $declaration->period_label,
            'rate_per_share' => number_format((float) $declaration->rate_per_share, 6),
            'total_gross_amount' => number_format((float) $totals['total_gross_amount'], 2),
            'total_tax_amount' => number_format((float) $totals['total_tax_amount'], 2),
            'total_net_payable' => number_format((float) $totals['total_net_amount'], 2),
            'eligible_shareholders_count' => (int) $totals['eligible_shareholders_count'],
            'current_status' => $declaration->status,
        ];
    }

    private function generatePaymentRecords(DividendDeclaration $declaration, int $frozenRunId, int $actorId): void
    {
        $entitlements = DividendEntitlement::with(['registerAccount.shareholder.mandates'])
            ->where('dividend_declaration_id', $declaration->id)
            ->where('entitlement_run_id', $frozenRunId)
            ->get();

        foreach ($entitlements as $entitlement) {
            $exists = DividendPayment::where('entitlement_id', $entitlement->id)
                ->whereIn('status', ['initiated', 'paid', 'failed', 'disputed', 'reissued'])
                ->exists();
            if ($exists) {
                continue;
            }

            $mandate = optional($entitlement->registerAccount?->shareholder)->mandates()
                ->where('status', 'active')
                ->first();

            $payoutMode = $mandate ? 'edividend' : 'warrant';

            DividendPayment::create([
                'dividend_payment_no' => $this->generatePaymentNo(),
                'entitlement_id' => $entitlement->id,
                'payout_mode' => $payoutMode,
                'bank_mandate_id' => $mandate?->id,
                'paid_ref' => $this->generatePaymentRef(),
                'status' => 'initiated',
                'created_by' => $actorId,
            ]);
        }
    }

    private function generatePaymentNo(): string
    {
        return 'DPN-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
    }

    private function generatePaymentRef(): string
    {
        return 'DPREF-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
    }

    /**
     * Load declaration with relations
     */
    private function loadDeclaration(int $declaration_id): DividendDeclaration
    {
        return DividendDeclaration::with(['shareClasses', 'register.company', 'approvalActions', 'delegations'])
            ->findOrFail($declaration_id);
    }

    /**
     * Validate declaration has required fields
     */
    private function validateDeclaration(DividendDeclaration $declaration): ?JsonResponse
    {
        if (!$declaration->rate_per_share || $declaration->rate_per_share <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Rate per share must be set before generating preview',
                'errors' => [
                    'rate_per_share' => ['Rate per share is required and must be greater than 0']
                ]
            ], 422);
        }

        if (!$declaration->record_date) {
            return response()->json([
                'success' => false,
                'message' => 'Record date must be set before generating preview',
                'errors' => [
                    'record_date' => ['Record date is required']
                ]
            ], 422);
        }

        return null;
    }

    /**
     * Validate announcement/record/payment date order.
     */
    private function validateDateSequence(array $validated): ?JsonResponse
    {
        if (!empty($validated['announcement_date']) && !empty($validated['record_date'])) {
            $announcement = Carbon::parse($validated['announcement_date']);
            $record = Carbon::parse($validated['record_date']);
            if ($record->lt($announcement)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Record date must be on or after announcement date',
                    'errors' => [
                        'record_date' => ['Record date must be on or after announcement date']
                    ]
                ], 422);
            }
        }

        if (!empty($validated['record_date']) && !empty($validated['payment_date'])) {
            $record = Carbon::parse($validated['record_date']);
            $payment = Carbon::parse($validated['payment_date']);
            if ($payment->lt($record)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment date must be on or after record date',
                    'errors' => [
                        'payment_date' => ['Payment date must be on or after record date']
                    ]
                ], 422);
            }
        }

        return null;
    }

    /**
     * Get eligible shareholder accounts
     */
    private function getEligibleAccounts(DividendDeclaration $declaration, int $perPage)
    {
        $shareClassIds = $declaration->shareClasses->pluck('id')->toArray();
        $recordDateEnd = Carbon::parse($declaration->record_date)->endOfDay();

        $query = ShareholderRegisterAccount::query()
            ->where('register_id', $declaration->register_id)
            ->where('status', 'active')
            ->with(['shareholder', 'sharePositions' => function($q) use ($shareClassIds, $recordDateEnd) {
                $q->whereIn('share_class_id', $shareClassIds)
                  ->where('quantity', '>', 0)
                  ->where('last_updated_at', '<=', $recordDateEnd);
            }])
            ->whereHas('sharePositions', function($q) use ($shareClassIds, $recordDateEnd) {
                $q->whereIn('share_class_id', $shareClassIds)
                  ->where('quantity', '>', 0)
                  ->where('last_updated_at', '<=', $recordDateEnd);
            });

        return $query->paginate($perPage);
    }

    /**
     * Compute entitlements for paginated accounts
     */
    private function computeEntitlements($accounts, DividendDeclaration $declaration): array
    {
        $entitlements = [];
        $pageTotals = [
            'gross_amount' => 0.0,
            'tax_amount' => 0.0,
            'net_amount' => 0.0,
            'total_shares' => 0.0,
        ];

        foreach ($accounts as $account) {
            $accountEntitlements = $this->processAccountPositions($account, $declaration);
            
            foreach ($accountEntitlements as $entitlement) {
                $entitlements[] = $entitlement;
                
                // Add to page totals (parse formatted strings back to floats)
                $pageTotals['gross_amount'] += (float) str_replace(',', '', $entitlement['gross_amount']);
                $pageTotals['tax_amount'] += (float) str_replace(',', '', $entitlement['tax_amount']);
                $pageTotals['net_amount'] += (float) str_replace(',', '', $entitlement['net_amount']);
                $pageTotals['total_shares'] += (float) str_replace(',', '', $entitlement['eligible_shares']);
            }
        }

        return [
            'entitlements' => $entitlements,
            'page_totals' => $pageTotals
        ];
    }

    /**
     * Process share positions for an account
     */
    private function processAccountPositions(ShareholderRegisterAccount $account, DividendDeclaration $declaration): array
    {
        $entitlements = [];

        foreach ($account->sharePositions as $position) {
            $shareClass = $declaration->shareClasses->firstWhere('id', $position->share_class_id);
            
            if (!$shareClass) {
                continue;
            }

            $amounts = $this->calculateAmounts($position, $declaration, $shareClass);
            $eligibility = $this->checkEligibility($account, $declaration);

            $entitlements[] = [
                'register_account_id' => $account->id,
                'shareholder_id' => $account->shareholder_id,
                'shareholder_name' => $account->shareholder->full_name ?? 'N/A',
                'shareholder_no' => $account->shareholder_no,
                'share_class_id' => $position->share_class_id,
                'share_class_code' => $shareClass->class_code,
                'eligible_shares' => number_format($amounts['eligible_shares'], 6),
                'rate_per_share' => number_format((float) $declaration->rate_per_share, 6),
                'gross_amount' => number_format($amounts['gross_amount'], 2),
                'tax_rate' => number_format($amounts['tax_rate'], 2),
                'tax_amount' => number_format($amounts['tax_amount'], 2),
                'net_amount' => number_format($amounts['net_amount'], 2),
                'currency' => $shareClass->currency,
                'is_payable' => $eligibility['is_payable'],
                'ineligibility_reason' => $eligibility['reason'],
            ];
        }

        return $entitlements;
    }

    /**
     * Calculate dividend amounts
     */
    private function calculateAmounts(SharePosition $position, DividendDeclaration $declaration, $shareClass): array
    {
        $eligibleShares = (float) $position->quantity;
        $ratePerShare = (float) $declaration->rate_per_share;
        $taxRate = (float) $shareClass->withholding_tax_rate;

        $grossAmount = $eligibleShares * $ratePerShare;
        $taxAmount = ($grossAmount * $taxRate) / 100;
        $netAmount = $grossAmount - $taxAmount;

        return [
            'eligible_shares' => $eligibleShares,
            'gross_amount' => $grossAmount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'net_amount' => $netAmount,
        ];
    }

    /**
     * Check if account is eligible for payment
     */
    private function checkEligibility(ShareholderRegisterAccount $account, DividendDeclaration $declaration): array
    {
        if ($declaration->exclude_caution_accounts && $account->shareholder && $account->shareholder->status === 'caution') {
            return [
                'is_payable' => false,
                'reason' => 'CAUTION_ACCOUNT'
            ];
        }

        if (!$declaration->require_active_bank_mandate) {
            return [
                'is_payable' => true,
                'reason' => 'NONE'
            ];
        }

        $hasActiveBankMandate = DB::table('shareholder_bank_mandates')
            ->where('shareholder_id', $account->shareholder_id)
            ->where('status', 'active')
            ->exists();

        return [
            'is_payable' => $hasActiveBankMandate,
            'reason' => $hasActiveBankMandate ? 'NONE' : 'NO_ACTIVE_BANK_MANDATE'
        ];
    }

    /**
     * Format success response
     */
    private function formatSuccessResponse(DividendDeclaration $declaration, array $result, $accounts, array $grandTotals): JsonResponse
    {
        // Safely format record_date
        $recordDate = $declaration->record_date;
        $formattedDate = $recordDate instanceof Carbon 
            ? $recordDate->format('Y-m-d') 
            : Carbon::parse($recordDate)->format('Y-m-d');

        $response = [
            'success' => true,
            'data' => [
                'declaration' => [
                    'id' => $declaration->id,
                    'dividend_declaration_no' => $declaration->dividend_declaration_no,
                    'period_label' => $declaration->period_label,
                    'rate_per_share' => number_format((float) $declaration->rate_per_share, 6),
                    'record_date' => $formattedDate,
                    'status' => $declaration->status,
                ],
                'summary' => $this->buildSummary($declaration),
                'entitlements' => $result['entitlements'],
                'pagination' => [
                    'current_page' => $accounts->currentPage(),
                    'per_page' => $accounts->perPage(),
                    'total' => $accounts->total(),
                    'last_page' => $accounts->lastPage(),
                    'from' => $accounts->firstItem(),
                    'to' => $accounts->lastItem(),
                ],
                'page_summary' => [
                    'count' => count($result['entitlements']),
                    'total_shares' => number_format($result['page_totals']['total_shares'], 6),
                    'total_gross' => number_format($result['page_totals']['gross_amount'], 2),
                    'total_tax' => number_format($result['page_totals']['tax_amount'], 2),
                    'total_net' => number_format($result['page_totals']['net_amount'], 2),
                ],
                'grand_totals' => $grandTotals,
            ],
            'message' => 'Entitlement preview generated successfully'
        ];

        return response()->json($response);
    }

    /**
     * Handle errors
     */
    private function handleError(\Exception $e, int $declaration_id): JsonResponse
    {
        Log::error('Error generating entitlement preview: ' . $e->getMessage(), [
            'declaration_id' => $declaration_id,
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error generating entitlement preview',
            'error' => $e->getMessage()
        ], 500);
    }

    /**
     * Calculate grand totals across all pages
     */
    private function calculateGrandTotals(DividendDeclaration $declaration): array
    {
        $shareClassIds = $declaration->shareClasses->pluck('id')->toArray();

        $positions = $this->getAllEligiblePositions($declaration, $shareClassIds);

        $grandTotals = $this->initializeGrandTotals();
        $accountsProcessed = [];

        foreach ($positions as $position) {
            $this->processPositionForTotals($position, $declaration, $grandTotals, $accountsProcessed);
        }

        $grandTotals['eligible_shareholders_count'] = count($accountsProcessed);

        return $this->formatGrandTotals($grandTotals);
    }

    /**
     * Calculate grand totals (raw numeric values)
     */
    private function calculateGrandTotalsRaw(DividendDeclaration $declaration): array
    {
        $shareClassIds = $declaration->shareClasses->pluck('id')->toArray();

        $positions = $this->getAllEligiblePositions($declaration, $shareClassIds);

        $grandTotals = $this->initializeGrandTotals();
        $accountsProcessed = [];

        foreach ($positions as $position) {
            $this->processPositionForTotals($position, $declaration, $grandTotals, $accountsProcessed);
        }

        $grandTotals['eligible_shareholders_count'] = count($accountsProcessed);
        $grandTotals['rounding_residue'] = $grandTotals['total_gross_amount']
            - $grandTotals['total_tax_amount']
            - $grandTotals['total_net_amount'];

        return $grandTotals;
    }

    /**
     * Freeze entitlements on approval.
     */
    private function freezeEntitlements(DividendDeclaration $declaration, int $actorId, array $totals): DividendEntitlementRun
    {
        $shareClassIds = $declaration->shareClasses->pluck('id')->toArray();
        $positions = $this->getAllEligiblePositions($declaration, $shareClassIds);

        $run = DividendEntitlementRun::create([
            'dividend_declaration_id' => $declaration->id,
            'run_type' => 'FROZEN',
            'run_status' => 'COMPLETED',
            'computed_at' => now(),
            'computed_by' => $actorId,
            'total_gross_amount' => $totals['total_gross_amount'],
            'total_tax_amount' => $totals['total_tax_amount'],
            'total_net_amount' => $totals['total_net_amount'],
            'rounding_residue' => $totals['rounding_residue'],
            'eligible_shareholders_count' => $totals['eligible_shareholders_count'],
        ]);

        $rows = [];
        $eligibilityCache = [];

        foreach ($positions as $position) {
            $account = $position->registerAccount;
            $shareClass = $position->shareClass;
            if (!$account || !$shareClass) {
                continue;
            }

            if (!isset($eligibilityCache[$account->id])) {
                $eligibilityCache[$account->id] = $this->determineEligibilityForAccount($account, $declaration);
            }

            $eligibility = $eligibilityCache[$account->id];
            $amounts = $this->calculateAmounts($position, $declaration, $shareClass);

            $rows[] = [
                'entitlement_run_id' => $run->id,
                'dividend_declaration_id' => $declaration->id,
                'register_account_id' => $account->id,
                'share_class_id' => $position->share_class_id,
                'eligible_shares' => $amounts['eligible_shares'],
                'gross_amount' => $amounts['gross_amount'],
                'tax_amount' => $amounts['tax_amount'],
                'net_amount' => $amounts['net_amount'],
                'is_payable' => $eligibility['is_payable'],
                'ineligibility_reason' => $eligibility['reason'],
            ];

            if (count($rows) >= 1000) {
                DividendEntitlement::insert($rows);
                $rows = [];
            }
        }

        if (!empty($rows)) {
            DividendEntitlement::insert($rows);
        }

        return $run;
    }

    /**
     * Determine payment eligibility for an account.
     */
    private function determineEligibilityForAccount(ShareholderRegisterAccount $account, DividendDeclaration $declaration): array
    {
        if ($declaration->exclude_caution_accounts && $account->shareholder && $account->shareholder->status === 'caution') {
            return [
                'is_payable' => false,
                'reason' => 'CAUTION',
            ];
        }

        if (!$declaration->require_active_bank_mandate) {
            return [
                'is_payable' => true,
                'reason' => 'NONE',
            ];
        }

        $hasActiveBankMandate = DB::table('shareholder_bank_mandates')
            ->where('shareholder_id', $account->shareholder_id)
            ->where('status', 'active')
            ->exists();

        return [
            'is_payable' => $hasActiveBankMandate,
            'reason' => $hasActiveBankMandate ? 'NONE' : 'NO_ACTIVE_BANK_MANDATE',
        ];
    }

    /**
     * Get all eligible positions
     */
    private function getAllEligiblePositions(DividendDeclaration $declaration, array $shareClassIds)
    {
        $recordDateEnd = Carbon::parse($declaration->record_date)->endOfDay();

        $query = SharePosition::whereHas('registerAccount', function($q) use ($declaration) {
                $q->where('register_id', $declaration->register_id)
                  ->where('status', 'active');
            })
            ->whereIn('share_class_id', $shareClassIds)
            ->where('quantity', '>', 0)
            ->where('last_updated_at', '<=', $recordDateEnd);

        if ($declaration->exclude_caution_accounts) {
            $query->whereHas('registerAccount.shareholder', function ($q) {
                $q->where('status', '!=', 'caution');
            });
        }

        return $query->with('shareClass', 'registerAccount.shareholder')->get();
    }

    /**
     * Initialize grand totals structure
     */
    private function initializeGrandTotals(): array
    {
        return [
            'eligible_shareholders_count' => 0,
            'total_shares' => 0.0,
            'total_gross_amount' => 0.0,
            'total_tax_amount' => 0.0,
            'total_net_amount' => 0.0,
            'payable_count' => 0,
            'payable_amount' => 0.0,
            'non_payable_count' => 0,
            'non_payable_amount' => 0.0,
            'by_share_class' => [],
        ];
    }

    /**
     * Process position for grand totals
     */
    private function processPositionForTotals(SharePosition $position, DividendDeclaration $declaration, array &$grandTotals, array &$accountsProcessed): void
    {
        $shareClass = $position->shareClass;
        
        if (!$shareClass) {
            return;
        }

        $amounts = $this->calculateAmounts($position, $declaration, $shareClass);
        
        // Track unique shareholders
        if (!in_array($position->sra_id, $accountsProcessed)) {
            $accountsProcessed[] = $position->sra_id;
        }

        // Check eligibility
        $isPayable = true;
        if ($declaration->exclude_caution_accounts && $position->registerAccount->shareholder) {
            if ($position->registerAccount->shareholder->status === 'caution') {
                $isPayable = false;
            }
        }
        if ($declaration->require_active_bank_mandate) {
            $hasActiveBankMandate = DB::table('shareholder_bank_mandates')
                ->where('shareholder_id', $position->registerAccount->shareholder_id)
                ->where('status', 'active')
                ->exists();
            
            if (!$hasActiveBankMandate) {
                $isPayable = false;
            }
        }

        // Add to totals
        $grandTotals['total_shares'] += $amounts['eligible_shares'];
        $grandTotals['total_gross_amount'] += $amounts['gross_amount'];
        $grandTotals['total_tax_amount'] += $amounts['tax_amount'];
        $grandTotals['total_net_amount'] += $amounts['net_amount'];

        if ($isPayable) {
            $grandTotals['payable_count']++;
            $grandTotals['payable_amount'] += $amounts['net_amount'];
        } else {
            $grandTotals['non_payable_count']++;
            $grandTotals['non_payable_amount'] += $amounts['net_amount'];
        }

        // By share class
        $this->updateShareClassTotals($grandTotals, $shareClass->class_code, $amounts);
    }

    /**
     * Update share class totals
     */
    private function updateShareClassTotals(array &$grandTotals, string $classCode, array $amounts): void
    {
        if (!isset($grandTotals['by_share_class'][$classCode])) {
            $grandTotals['by_share_class'][$classCode] = [
                'share_class_code' => $classCode,
                'total_shares' => 0.0,
                'gross_amount' => 0.0,
                'tax_amount' => 0.0,
                'net_amount' => 0.0,
            ];
        }

        $grandTotals['by_share_class'][$classCode]['total_shares'] += $amounts['eligible_shares'];
        $grandTotals['by_share_class'][$classCode]['gross_amount'] += $amounts['gross_amount'];
        $grandTotals['by_share_class'][$classCode]['tax_amount'] += $amounts['tax_amount'];
        $grandTotals['by_share_class'][$classCode]['net_amount'] += $amounts['net_amount'];
    }

    /**
     * Format grand totals for response
     */
    private function formatGrandTotals(array $grandTotals): array
    {
        $grandTotals['rounding_residue'] = $grandTotals['total_gross_amount']
            - $grandTotals['total_tax_amount']
            - $grandTotals['total_net_amount'];

        $grandTotals['total_shares'] = number_format($grandTotals['total_shares'], 6);
        $grandTotals['total_gross_amount'] = number_format($grandTotals['total_gross_amount'], 2);
        $grandTotals['total_tax_amount'] = number_format($grandTotals['total_tax_amount'], 2);
        $grandTotals['total_net_amount'] = number_format($grandTotals['total_net_amount'], 2);
        $grandTotals['payable_amount'] = number_format($grandTotals['payable_amount'], 2);
        $grandTotals['non_payable_amount'] = number_format($grandTotals['non_payable_amount'], 2);
        $grandTotals['rounding_residue'] = number_format($grandTotals['rounding_residue'], 6);

        foreach ($grandTotals['by_share_class'] as $code => $data) {
            $grandTotals['by_share_class'][$code]['total_shares'] = number_format($data['total_shares'], 6);
            $grandTotals['by_share_class'][$code]['gross_amount'] = number_format($data['gross_amount'], 2);
            $grandTotals['by_share_class'][$code]['tax_amount'] = number_format($data['tax_amount'], 2);
            $grandTotals['by_share_class'][$code]['net_amount'] = number_format($data['net_amount'], 2);
        }

        return $grandTotals;
    }
}

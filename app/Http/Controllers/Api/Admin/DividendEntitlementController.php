<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DividendDeclaration;
use App\Models\SharePosition;
use App\Models\ShareholderRegisterAccount;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\DividendWorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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
                'period_label' => 'required|string|max:100',
                'description' => 'nullable|string|max:255',
                'share_class_ids' => 'required|array|min:1',
                'share_class_ids.*' => 'required|exists:share_classes,id',
                'rate_per_share' => 'nullable|numeric|min:0|max:999999999999.999999',
                'announcement_date' => 'nullable|date',
                'record_date' => 'nullable|date',
                'payment_date' => 'nullable|date|after_or_equal:record_date',
                'exclude_caution_accounts' => 'nullable|boolean',
                'require_active_bank_mandate' => 'nullable|boolean',
            ]);

            // Verify register exists and get company_id
            $register = Register::findOrFail($register_id);

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
                // Create dividend declaration
                $declaration = DividendDeclaration::create([
                    'company_id' => $register->company_id,
                    'register_id' => $register_id,
                    'period_label' => $validated['period_label'],
                    'description' => $validated['description'] ?? null,
                    'action_type' => 'DIVIDEND',
                    'declaration_method' => 'RATE_PER_SHARE',
                    'rate_per_share' => $validated['rate_per_share'] ?? null,
                    'announcement_date' => $validated['announcement_date'] ?? null,
                    'record_date' => $validated['record_date'] ?? null,
                    'payment_date' => $validated['payment_date'] ?? null,
                    'exclude_caution_accounts' => $validated['exclude_caution_accounts'] ?? false,
                    'require_active_bank_mandate' => $validated['require_active_bank_mandate'] ?? true,
                    'status' => 'DRAFT',
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
                    'data' => $declaration,
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
                'verifier',
                'approver',
                'rejecter',
                'workflowEvents.actor'
            ])->findOrFail($declaration_id);

            return response()->json([
                'success' => true,
                'data' => $declaration,
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
                        'status' => ['This declaration is no longer in draft status and cannot be edited']
                    ]
                ], 422);
            }

            $validated = $request->validate([
                'period_label' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:255',
                'share_class_ids' => 'nullable|array|min:1',
                'share_class_ids.*' => 'nullable|exists:share_classes,id',
                'rate_per_share' => 'nullable|numeric|min:0|max:999999999999.999999',
                'announcement_date' => 'nullable|date',
                'record_date' => 'nullable|date',
                'payment_date' => 'nullable|date|after_or_equal:record_date',
                'exclude_caution_accounts' => 'nullable|boolean',
                'require_active_bank_mandate' => 'nullable|boolean',
            ]);

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
                    'data' => $declaration,
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

    // ========================================================================
    // PRIVATE HELPER METHODS
    // ========================================================================

    /**
     * Load declaration with relations
     */
    private function loadDeclaration(int $declaration_id): DividendDeclaration
    {
        return DividendDeclaration::with(['shareClasses', 'register'])
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
     * Get eligible shareholder accounts
     */
    private function getEligibleAccounts(DividendDeclaration $declaration, int $perPage)
    {
        $shareClassIds = $declaration->shareClasses->pluck('id')->toArray();

        $query = ShareholderRegisterAccount::query()
            ->where('register_id', $declaration->register_id)
            ->where('status', 'active')
            ->with(['shareholder', 'sharePositions' => function($q) use ($shareClassIds) {
                $q->whereIn('share_class_id', $shareClassIds)
                  ->where('quantity', '>', 0);
            }])
            ->whereHas('sharePositions', function($q) use ($shareClassIds) {
                $q->whereIn('share_class_id', $shareClassIds)
                  ->where('quantity', '>', 0);
            });

        // Apply exclusion filters
        if ($declaration->exclude_caution_accounts) {
            $query->whereDoesntHave('shareholder', function($q) {
                $q->where('status', 'caution');
            });
        }

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
                    'period_label' => $declaration->period_label,
                    'rate_per_share' => number_format((float) $declaration->rate_per_share, 6),
                    'record_date' => $formattedDate,
                ],
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
     * Get all eligible positions
     */
    private function getAllEligiblePositions(DividendDeclaration $declaration, array $shareClassIds)
    {
        $query = SharePosition::whereHas('registerAccount', function($q) use ($declaration) {
                $q->where('register_id', $declaration->register_id)
                  ->where('status', 'active');
            })
            ->whereIn('share_class_id', $shareClassIds)
            ->where('quantity', '>', 0);

        if ($declaration->exclude_caution_accounts) {
            $query->whereHas('registerAccount.shareholder', function($q) {
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
        $grandTotals['total_shares'] = number_format($grandTotals['total_shares'], 6);
        $grandTotals['total_gross_amount'] = number_format($grandTotals['total_gross_amount'], 2);
        $grandTotals['total_tax_amount'] = number_format($grandTotals['total_tax_amount'], 2);
        $grandTotals['total_net_amount'] = number_format($grandTotals['total_net_amount'], 2);
        $grandTotals['payable_amount'] = number_format($grandTotals['payable_amount'], 2);
        $grandTotals['non_payable_amount'] = number_format($grandTotals['non_payable_amount'], 2);

        foreach ($grandTotals['by_share_class'] as $code => $data) {
            $grandTotals['by_share_class'][$code]['total_shares'] = number_format($data['total_shares'], 6);
            $grandTotals['by_share_class'][$code]['gross_amount'] = number_format($data['gross_amount'], 2);
            $grandTotals['by_share_class'][$code]['tax_amount'] = number_format($data['tax_amount'], 2);
            $grandTotals['by_share_class'][$code]['net_amount'] = number_format($data['net_amount'], 2);
        }

        return $grandTotals;
    }
}
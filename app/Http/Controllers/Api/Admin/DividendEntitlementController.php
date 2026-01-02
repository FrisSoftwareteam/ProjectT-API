<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DividendDeclaration;
use App\Models\SharePosition;
use App\Models\ShareholderRegisterAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DividendEntitlementController extends Controller
{
    /**
     * 2.1 Generate Entitlement Preview (Compute & Paginate)
     * POST /dividend-declarations/{declaration_id}/preview
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
     * GET /dividend-declarations/{declaration_id}/preview
     */
    public function fetchPreview(Request $request, int $declaration_id): JsonResponse
    {
        return $this->generatePreview($request, $declaration_id);
    }

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
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DividendDeclaration;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DividendValidationController extends Controller
{
    /**
     * 6.1 Validate Period Uniqueness
     * GET /admin/companies/{company_id}/dividend-declarations/validate-period
     */
    public function validatePeriod(Request $request, int $company_id): JsonResponse
    {
        try {
            Company::findOrFail($company_id);

            $validated = $request->validate([
                'period_label' => 'nullable|string|max:100',
                'dividend_declaration_no' => 'nullable|string|max:100',
            ]);

            if (empty($validated['period_label']) && empty($validated['dividend_declaration_no'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => [
                        'period_label' => ['Provide period_label or dividend_declaration_no'],
                    ],
                ], 422);
            }

            $periodExists = false;
            if (!empty($validated['period_label'])) {
                $periodExists = DividendDeclaration::where('company_id', $company_id)
                    ->where('period_label', $validated['period_label'])
                    ->exists();
            }

            $numberExists = false;
            if (!empty($validated['dividend_declaration_no'])) {
                $numberExists = DividendDeclaration::where('dividend_declaration_no', $validated['dividend_declaration_no'])
                    ->exists();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'company_id' => $company_id,
                    'period_label' => $validated['period_label'] ?? null,
                    'dividend_declaration_no' => $validated['dividend_declaration_no'] ?? null,
                    'is_period_unique' => !$periodExists,
                    'is_declaration_number_unique' => !$numberExists,
                ],
                'message' => ($periodExists || $numberExists)
                    ? 'One or more values are already in use'
                    : 'Validation values are available',
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
                'message' => 'Company not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error validating period label',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShareClass;
use App\Models\Register;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class ShareClassController extends Controller
{
    /**
     * Display a listing of share classes.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShareClass::with('register.company');

            // Filter by register
            if ($request->has('register_id')) {
                $query->where('register_id', $request->input('register_id'));
            }

            // Filter by currency
            if ($request->has('currency')) {
                $query->where('currency', $request->input('currency'));
            }

            // Search by class_code or description
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('class_code', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Include soft-deleted records if requested
            if ($request->boolean('include_deleted')) {
                $query->withTrashed();
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $shareClasses = $query->paginate($request->input('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $shareClasses,
                'message' => 'Share classes retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving share classes: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving share classes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created share class.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'register_id' => 'required|exists:registers,id',
                'class_code' => 'required|string|max:32',
                'currency' => 'nullable|string|size:3',
                'par_value' => 'nullable|numeric|min:0|max:999999999999.999999',
                'description' => 'nullable|string|max:255',
                'withholding_tax_rate' => 'nullable|numeric|min:0|max:100',
            ]);

            // Check for unique combination of register_id and class_code
            $exists = ShareClass::where('register_id', $validated['register_id'])
                                ->where('class_code', $validated['class_code'])
                                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Class code already exists for this register',
                    'errors' => ['class_code' => ['This class code is already in use for this register']]
                ], 422);
            }

            // Set default values
            $validated['currency'] = $validated['currency'] ?? 'NGN';
            $validated['par_value'] = $validated['par_value'] ?? 0;
            $validated['withholding_tax_rate'] = $validated['withholding_tax_rate'] ?? 0;

            $shareClass = ShareClass::create($validated);
            $shareClass->load('register.company');

            Log::info('Share class created', [
                'share_class_id' => $shareClass->id,
                'register_id' => $shareClass->register_id,
                'class_code' => $shareClass->class_code,
                'withholding_tax_rate' => $shareClass->withholding_tax_rate,
                'created_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Share class created successfully',
                'data' => $shareClass,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating share class: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating share class',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified share class.
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $query = ShareClass::with('register.company');

            // Include soft-deleted records if requested
            if ($request->boolean('include_deleted')) {
                $query->withTrashed();
            }

            $shareClass = $query->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $shareClass,
                'message' => 'Share class retrieved successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Share class not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving share class: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving share class',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified share class.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $shareClass = ShareClass::findOrFail($id);

            $validated = $request->validate([
                'class_code' => 'required|string|max:32',
                'currency' => 'nullable|string|size:3',
                'par_value' => 'nullable|numeric|min:0|max:999999999999.999999',
                'description' => 'nullable|string|max:255',
                'withholding_tax_rate' => 'nullable|numeric|min:0|max:100',
            ]);

            // Check for unique combination of register_id and class_code
            $exists = ShareClass::where('register_id', $shareClass->register_id)
                                ->where('class_code', $validated['class_code'])
                                ->where('id', '!=', $shareClass->id)
                                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Class code already exists for this register',
                    'errors' => ['class_code' => ['This class code is already in use for this register']]
                ], 422);
            }

            $shareClass->update($validated);
            $shareClass->load('register.company');

            Log::info('Share class updated', [
                'share_class_id' => $shareClass->id,
                'register_id' => $shareClass->register_id,
                'withholding_tax_rate' => $shareClass->withholding_tax_rate,
                'updated_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Share class updated successfully',
                'data' => $shareClass->fresh(['register.company']),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Share class not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating share class: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating share class',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified share class (soft delete).
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $shareClass = ShareClass::findOrFail($id);

            // For now, we'll allow deletion since we haven't built the related features yet
            // When you create SharePosition and ShareTransaction models, uncomment the check below:
            
            // if ($shareClass->sharePositions()->exists() || $shareClass->shareTransactions()->exists()) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Cannot delete share class with associated positions or transactions',
            //     ], 422);
            // }

            $shareClass->delete();

            Log::info('Share class deleted', [
                'share_class_id' => $shareClass->id,
                'register_id' => $shareClass->register_id,
                'deleted_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Share class deleted successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Share class not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting share class: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting share class',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate withholding tax for a given amount.
     */
    public function calculateTax(Request $request, $id): JsonResponse
    {
        try {
            $shareClass = ShareClass::findOrFail($id);

            $validated = $request->validate([
                'amount' => 'required|numeric|min:0',
            ]);

            $amount = (float) $validated['amount'];
            $taxAmount = $shareClass->calculateWithholdingTax($amount);
            $netAmount = $shareClass->calculateNetAmount($amount);

            Log::info('Tax calculation performed', [
                'share_class_id' => $shareClass->id,
                'class_code' => $shareClass->class_code,
                'gross_amount' => $amount,
                'tax_rate' => $shareClass->withholding_tax_rate,
                'tax_amount' => $taxAmount,
                'net_amount' => $netAmount,
                'calculated_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'share_class' => [
                        'id' => $shareClass->id,
                        'class_code' => $shareClass->class_code,
                        'withholding_tax_rate' => $shareClass->withholding_tax_rate,
                        'currency' => $shareClass->currency,
                    ],
                    'calculation' => [
                        'gross_amount' => number_format($amount, 2),
                        'tax_rate' => number_format((float)$shareClass->withholding_tax_rate, 2) . '%',
                        'tax_amount' => number_format($taxAmount, 2),
                        'net_amount' => number_format($netAmount, 2),
                        'currency' => $shareClass->currency,
                    ]
                ],
                'message' => 'Tax calculated successfully'
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Share class not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error calculating tax: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error calculating tax',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
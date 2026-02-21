<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class CompanyController extends Controller
{
    /**
     * Display a listing of companies.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Company::query();

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            // Search by name, issuer_code, rc_number, or tin
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('issuer_code', 'like', "%{$search}%")
                      ->orWhere('rc_number', 'like', "%{$search}%")
                      ->orWhere('tin', 'like', "%{$search}%");
                });
            }

            // Include soft-deleted records if requested
            if ($request->boolean('include_deleted')) {
                $query->withTrashed();
            }

            // Include registers relationship if requested
            if ($request->boolean('include_registers')) {
                $query->with('registers');
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $companies = $query->paginate($request->input('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $companies,
                'message' => 'Companies retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving companies: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving companies',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created company.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'issuer_code' => 'required|string|max:32|unique:companies,issuer_code',
                'name' => 'required|string|max:255',
                'rc_number' => 'nullable|string|max:50',
                'tin' => 'nullable|string|max:50',
                'status' => 'nullable|in:active,suspended,closed',
            ]);

            // Set default status if not provided
            $validated['status'] = $validated['status'] ?? 'active';

            $company = Company::create($validated);

            Log::info('Company created', [
                'company_id' => $company->id,
                'issuer_code' => $company->issuer_code,
                'created_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Company created successfully',
                'data' => $company,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating company: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating company',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified company.
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $query = Company::query();

            // Include soft-deleted records if requested
            if ($request->boolean('include_deleted')) {
                $query->withTrashed();
            }

            // Include registers relationship if requested
            if ($request->boolean('include_registers')) {
                $query->with('registers.shareClasses');
            }

            $company = $query->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $company,
                'message' => 'Company retrieved successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving company: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving company',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display company full context (company + registers + share classes).
     */
    public function fullContext(Request $request, $id): JsonResponse
    {
        try {
            $query = Company::query();

            if ($request->boolean('include_deleted')) {
                $query->withTrashed();
            }

            $company = $query
                ->with([
                    'registers' => function ($q) {
                        $q->orderBy('name');
                    },
                    'registers.shareClasses' => function ($q) {
                        $q->orderBy('class_code');
                    },
                ])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $company,
                'message' => 'Company full context retrieved successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving company full context: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving company full context',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified company.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $company = Company::findOrFail($id);

            $validated = $request->validate([
                'issuer_code' => 'required|string|max:32|unique:companies,issuer_code,' . $company->id,
                'name' => 'required|string|max:255',
                'rc_number' => 'nullable|string|max:50',
                'tin' => 'nullable|string|max:50',
                'status' => 'nullable|in:active,suspended,closed',
            ]);

            $company->update($validated);

            Log::info('Company updated', [
                'company_id' => $company->id,
                'issuer_code' => $company->issuer_code,
                'updated_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Company updated successfully',
                'data' => $company->fresh(),
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
                'message' => 'Company not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating company: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating company',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified company (soft delete).
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $company = Company::findOrFail($id);

            // Check if company has active registers
            if ($company->registers()->where('status', 'active')->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete company with active registers. Please close all registers first.',
                ], 422);
            }

            $company->delete();

            Log::info('Company deleted', [
                'company_id' => $company->id,
                'issuer_code' => $company->issuer_code,
                'deleted_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Company deleted successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting company: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting company',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted company.
     */
    public function restore($id): JsonResponse
    {
        try {
            $company = Company::withTrashed()->findOrFail($id);

            if (!$company->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company is not deleted',
                ], 422);
            }

            $company->restore();

            Log::info('Company restored', [
                'company_id' => $company->id,
                'issuer_code' => $company->issuer_code
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Company restored successfully',
                'data' => $company,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error restoring company: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error restoring company',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get company statistics.
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total' => Company::count(),
                'active' => Company::where('status', 'active')->count(),
                'suspended' => Company::where('status', 'suspended')->count(),
                'closed' => Company::where('status', 'closed')->count(),
                'deleted' => Company::onlyTrashed()->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
                'message' => 'Company statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving company statistics: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving company statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

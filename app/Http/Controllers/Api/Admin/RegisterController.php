 <?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Register;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    /**
     * Display a listing of registers.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Register::with('company');

            // Filter by company
            if ($request->has('company_id')) {
                $query->where('company_id', $request->input('company_id'));
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            // Filter by is_default
            if ($request->has('is_default')) {
                $query->where('is_default', $request->boolean('is_default'));
            }

            // Search by name or register_code
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('register_code', 'like', "%{$search}%");
                });
            }

            // Include soft-deleted records if requested
            if ($request->boolean('include_deleted')) {
                $query->withTrashed();
            }

            // Include share classes if requested
            if ($request->boolean('include_share_classes')) {
                $query->with('shareClasses');
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $registers = $query->paginate($request->input('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $registers,
                'message' => 'Registers retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving registers: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving registers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created register.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'company_id' => 'required|exists:companies,id',
                'register_code' => 'required|string|max:32',
                'name' => 'required|string|max:255',
                'is_default' => 'nullable|boolean',
                'status' => 'nullable|in:active,closed',
            ]);

            // Check for unique combination of company_id and register_code
            $exists = Register::where('company_id', $validated['company_id'])
                              ->where('register_code', $validated['register_code'])
                              ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Register code already exists for this company',
                    'errors' => ['register_code' => ['This register code is already in use for this company']]
                ], 422);
            }

            // Set default values
            $validated['is_default'] = $validated['is_default'] ?? true;
            $validated['status'] = $validated['status'] ?? 'active';

            // If this is set as default, unset other defaults for this company
            if ($validated['is_default']) {
                Register::where('company_id', $validated['company_id'])
                        ->where('is_default', true)
                        ->update(['is_default' => false]);
            }

            $register = Register::create($validated);
            $register->load('company');

            Log::info('Register created', [
                'register_id' => $register->id,
                'company_id' => $register->company_id,
                'register_code' => $register->register_code,
                'created_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Register created successfully',
                'data' => $register,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating register: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating register',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified register.
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $query = Register::with('company');

            // Include soft-deleted records if requested
            if ($request->boolean('include_deleted')) {
                $query->withTrashed();
            }

            // Include share classes if requested
            if ($request->boolean('include_share_classes')) {
                $query->with('shareClasses');
            }

            $register = $query->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $register,
                'message' => 'Register retrieved successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Register not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving register: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving register',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified register.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $register = Register::findOrFail($id);

            $validated = $request->validate([
                'register_code' => 'required|string|max:32',
                'name' => 'required|string|max:255',
                'is_default' => 'nullable|boolean',
                'status' => 'nullable|in:active,closed',
            ]);

            // Check for unique combination of company_id and register_code
            $exists = Register::where('company_id', $register->company_id)
                              ->where('register_code', $validated['register_code'])
                              ->where('id', '!=', $register->id)
                              ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Register code already exists for this company',
                    'errors' => ['register_code' => ['This register code is already in use for this company']]
                ], 422);
            }

            // If this is being set as default, unset other defaults for this company
            if (isset($validated['is_default']) && $validated['is_default']) {
                Register::where('company_id', $register->company_id)
                        ->where('id', '!=', $register->id)
                        ->where('is_default', true)
                        ->update(['is_default' => false]);
            }

            $register->update($validated);
            $register->load('company');

            Log::info('Register updated', [
                'register_id' => $register->id,
                'company_id' => $register->company_id,
                'updated_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Register updated successfully',
                'data' => $register->fresh(['company']),
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
                'message' => 'Register not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating register: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating register',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified register (soft delete).
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $register = Register::findOrFail($id);

            // Check if register has share classes
            if ($register->shareClasses()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete register with associated share classes',
                ], 422);
            }

            $register->delete();

            Log::info('Register deleted', [
                'register_id' => $register->id,
                'company_id' => $register->company_id,
                'deleted_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Register deleted successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Register not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting register: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting register',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
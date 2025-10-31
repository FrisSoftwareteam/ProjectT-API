<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Log;

class PermissionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Permission::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Filter by module
        if ($request->has('module')) {
            $module = $request->input('module');
            $query->where('name', 'like', "{$module}.%");
        }

        // Filter by action
        if ($request->has('action')) {
            $action = $request->input('action');
            $query->where('name', 'like', "%.{$action}");
        }

        $permissions = $query->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:permissions,name',
                'module' => 'nullable|string|max:100',
                'action' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:500',
            ]);

            $permission = Permission::create([
                'name' => $validated['name'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission created successfully',
                'data' => $permission,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Permission $permission): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $permission,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Permission $permission): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:permissions,name,' . $permission->id,
                'module' => 'nullable|string|max:100',
                'action' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:500',
            ]);

            $permission->update([
                'name' => $validated['name'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission updated successfully',
                'data' => $permission,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Permission $permission): JsonResponse
    {
        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permission deleted successfully',
        ]);
    }

    /**
     * Get roles that have this permission
     */
    public function roles(Request $request, $id): JsonResponse
    {
        try {
            // Find the permission by ID to ensure we have the correct permission
            $permission = Permission::findOrFail($id);
            
            // Get roles that have this specific permission
            $roles = $permission->roles()->paginate(15);

            return response()->json([
                'success' => true,
                'data' => $roles,
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving roles for permission ID ' . $id . ': ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving roles for permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get users that have this permission
     */
    public function users(Request $request, $id): JsonResponse
    {
        try {
            // Find the permission by ID to ensure we have the correct permission
            $permission = Permission::findOrFail($id);
            
            // Get users that have this specific permission
            $users = AdminUser::permission($permission->name)->paginate(15);

            return response()->json([
                'success' => true,
                'data' => $users,
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving users for permission ID ' . $id . ': ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving users for permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get permissions grouped by module
     */
    public function groupedByModule(): JsonResponse
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0] ?? 'other';
        });

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Get available modules
     */
    public function modules(): JsonResponse
    {
        $modules = Permission::all()
            ->map(function ($permission) {
                return explode('.', $permission->name)[0] ?? 'other';
            })
            ->unique()
            ->values();

        return response()->json([
            'success' => true,
            'data' => $modules,
        ]);
    }

    /**
     * Get available actions
     */
    public function actions(): JsonResponse
    {
        $actions = Permission::all()
            ->map(function ($permission) {
                $parts = explode('.', $permission->name);
                return end($parts);
            })
            ->unique()
            ->values();

        return response()->json([
            'success' => true,
            'data' => $actions,
        ]);
    }

    /**
     * Bulk create permissions
     */
    public function bulkCreate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*.name' => 'required|string|max:255|unique:permissions,name',
                'permissions.*.module' => 'nullable|string|max:100',
                'permissions.*.action' => 'nullable|string|max:100',
                'permissions.*.description' => 'nullable|string|max:500',
            ]);

            $createdPermissions = [];
            foreach ($validated['permissions'] as $permissionData) {
                $permission = Permission::create([
                    'name' => $permissionData['name'],
                ]);
                $createdPermissions[] = $permission;
            }

            return response()->json([
                'success' => true,
                'message' => 'Permissions created successfully',
                'data' => $createdPermissions,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}


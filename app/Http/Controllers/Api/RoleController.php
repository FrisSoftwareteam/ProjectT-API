<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Role::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Filter by permission
        if ($request->has('permission')) {
            $permission = $request->input('permission');
            $query->whereHas('permissions', function ($q) use ($permission) {
                $q->where('name', $permission);
            });
        }

        $roles = $query->with('permissions')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:roles,name',
                'permissions' => 'nullable|array',
                'permissions.*' => 'exists:permissions,name',
            ]);

            $role = Role::create(['name' => $validated['name']]);

            // Assign permissions if provided
            if (isset($validated['permissions']) && is_array($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            // Load permissions for response
            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => $role,
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
    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');
        
        return response()->json([
            'success' => true,
            'data' => $role,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
                'permissions' => 'nullable|array',
                'permissions.*' => 'exists:permissions,name',
            ]);

            $role->update(['name' => $validated['name']]);

            // Sync permissions if provided
            if (isset($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            // Load permissions for response
            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => $role,
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
    public function destroy(Role $role): JsonResponse
    {
        // Prevent deletion of super admin role
        if ($role->name === 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete Super Admin role',
            ], 403);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully',
        ]);
    }

    /**
     * Assign permissions to role
     */
    public function assignPermissions(Request $request, Role $role): JsonResponse
    {
        try {
            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,name',
            ]);

            $role->syncPermissions($validated['permissions']);

            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Permissions assigned successfully',
                'data' => $role,
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
     * Revoke permissions from role
     */
    public function revokePermissions(Request $request, Role $role): JsonResponse
    {
        try {
            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,name',
            ]);

            $role->revokePermissionTo($validated['permissions']);

            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Permissions revoked successfully',
                'data' => $role,
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
     * Get users with this role
     */
    public function users(Role $role): JsonResponse
    {
        $users = $role->users()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Get available permissions for role
     */
    public function availablePermissions(Role $role): JsonResponse
    {
        $assignedPermissions = $role->permissions->pluck('name')->toArray();
        $availablePermissions = Permission::whereNotIn('name', $assignedPermissions)->get();

        return response()->json([
            'success' => true,
            'data' => $availablePermissions,
        ]);
    }
}
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AdminUser::query();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by department
        if ($request->has('department')) {
            $query->where('department', $request->input('department'));
        }

        // Filter by role
        if ($request->has('role')) {
            $roleName = $request->input('role');
            $query->whereHas('roles', function ($q) use ($roleName) {
                $q->where('name', $roleName);
            });
        }

        // Filter by permission
        if ($request->has('permission')) {
            $permissionName = $request->input('permission');
            $query->whereHas('permissions', function ($q) use ($permissionName) {
                $q->where('name', $permissionName);
            });
        }

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Load roles and permissions with users
        $users = $query->with(['roles', 'permissions'])
                      ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'microsoft_id' => 'nullable|string|unique:admin_users,microsoft_id',
                'email' => 'required|email|unique:admin_users,email',
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'department' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'profile_picture' => 'nullable|string|max:500',
                'microsoft_data' => 'nullable|array',
            ]);

            $user = AdminUser::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Admin user created successfully',
                'data' => $user,
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
    public function show(AdminUser $adminUser): JsonResponse
    {
        // Load roles and permissions
        $adminUser->load(['roles', 'permissions']);

        return response()->json([
            'success' => true,
            'data' => $adminUser,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AdminUser $adminUser): JsonResponse
    {
        try {
            $validated = $request->validate([
                'microsoft_id' => 'nullable|string|unique:admin_users,microsoft_id,' . $adminUser->id,
                'email' => 'required|email|unique:admin_users,email,' . $adminUser->id,
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'department' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'profile_picture' => 'nullable|string|max:500',
                'microsoft_data' => 'nullable|array',
            ]);

            $adminUser->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Admin user updated successfully',
                'data' => $adminUser->fresh(),
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
    public function destroy(AdminUser $adminUser): JsonResponse
    {
        $adminUser->delete();

        return response()->json([
            'success' => true,
            'message' => 'Admin user deleted successfully',
        ]);
    }

    /**
     * Assign roles to user
     */
    public function assignRoles(Request $request, AdminUser $adminUser): JsonResponse
    {
        try {
            $validated = $request->validate([
                'roles' => 'required|array',
                'roles.*' => 'exists:roles,name',
            ]);

            $adminUser->syncRoles($validated['roles']);

            $adminUser->load(['roles', 'permissions']);

            return response()->json([
                'success' => true,
                'message' => 'Roles assigned successfully',
                'data' => $adminUser,
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
     * Revoke roles from user
     */
    public function revokeRoles(Request $request, AdminUser $adminUser): JsonResponse
    {
        try {
            $validated = $request->validate([
                'roles' => 'required|array',
                'roles.*' => 'exists:roles,name',
            ]);

            $adminUser->removeRole($validated['roles']);

            $adminUser->load(['roles', 'permissions']);

            return response()->json([
                'success' => true,
                'message' => 'Roles revoked successfully',
                'data' => $adminUser,
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
     * Assign permissions directly to user
     */
    public function assignPermissions(Request $request, AdminUser $adminUser): JsonResponse
    {
        try {
            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,name',
            ]);

            $adminUser->syncPermissions($validated['permissions']);

            $adminUser->load(['roles', 'permissions']);

            return response()->json([
                'success' => true,
                'message' => 'Permissions assigned successfully',
                'data' => $adminUser,
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
     * Revoke permissions from user
     */
    public function revokePermissions(Request $request, AdminUser $adminUser): JsonResponse
    {
        try {
            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,name',
            ]);

            $adminUser->revokePermissionTo($validated['permissions']);

            $adminUser->load(['roles', 'permissions']);

            return response()->json([
                'success' => true,
                'message' => 'Permissions revoked successfully',
                'data' => $adminUser,
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
     * Get user's roles
     */
    public function getRoles(AdminUser $adminUser): JsonResponse
    {
        $adminUser->load('roles');

        return response()->json([
            'success' => true,
            'data' => $adminUser->roles,
        ]);
    }

    /**
     * Get user's permissions (including from roles)
     */
    public function getPermissions(AdminUser $adminUser): JsonResponse
    {
        $adminUser->load('permissions');
        
        // Get all permissions (direct + via roles)
        $allPermissions = $adminUser->getAllPermissions();

        return response()->json([
            'success' => true,
            'data' => [
                'direct_permissions' => $adminUser->permissions,
                'all_permissions' => $allPermissions,
            ],
        ]);
    }
}

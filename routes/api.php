<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\CompanyController;
use App\Http\Controllers\Api\Admin\RegisterController;
use App\Http\Controllers\Api\Admin\ShareClassController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Authentication Routes (Public)
Route::prefix('auth')->group(function () {
    Route::middleware(['web'])->group(function () {
        Route::get('/microsoft/redirect', [AuthController::class, 'redirectToMicrosoft']);
        Route::get('/microsoft/callback', [AuthController::class, 'handleMicrosoftCallback']);
    });
    
    Route::post('/simulate', [AuthController::class, 'simulateLogin']);
    Route::get('/simulation-users', [AuthController::class, 'getSimulationUsers']);
});

// Protected Routes (require authentication)
Route::middleware(['auth:sanctum'])->group(function () {
    // User info
    Route::get('/user', [AuthController::class, 'me']);
    
    // Auth management
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    
    // Admin Users API Routes
    Route::prefix('admin/users')->group(function () {
        Route::post('/{adminUser}/roles', [AdminUserController::class, 'assignRoles']);
        Route::delete('/{adminUser}/roles', [AdminUserController::class, 'revokeRoles']);
        Route::get('/{adminUser}/roles', [AdminUserController::class, 'getRoles']);
        Route::post('/{adminUser}/permissions', [AdminUserController::class, 'assignPermissions']);
        Route::delete('/{adminUser}/permissions', [AdminUserController::class, 'revokePermissions']);
        Route::get('/{adminUser}/permissions', [AdminUserController::class, 'getPermissions']);
    });
    
    // Admin Users CRUD
    Route::prefix('admin')->group(function () {
        Route::apiResource('users', AdminUserController::class);
    });

    // Roles API Routes
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('/', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::get('/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
        Route::put('/{role}', [RoleController::class, 'update'])->middleware('permission:roles.edit');
        Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
        
        Route::post('/{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:roles.assign');
        Route::delete('/{role}/permissions', [RoleController::class, 'revokePermissions'])->middleware('permission:roles.assign');
        Route::get('/{role}/users', [RoleController::class, 'users'])->middleware('permission:roles.view');
        Route::get('/{role}/available-permissions', [RoleController::class, 'availablePermissions']);
    });

    // Permissions API Routes
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index'])->middleware('permission:permissions.view');
        Route::post('/', [PermissionController::class, 'store']);
        Route::post('/bulk', [PermissionController::class, 'bulkCreate'])->middleware('permission:permissions.create');
        Route::get('/{permission}', [PermissionController::class, 'show'])->middleware('permission:permissions.view');
        Route::put('/{permission}', [PermissionController::class, 'update'])->middleware('permission:permissions.edit');
        Route::delete('/{permission}', [PermissionController::class, 'destroy']);
        
        Route::get('/{permission}/roles', [PermissionController::class, 'roles']);
        Route::get('/{permission}/users', [PermissionController::class, 'users']);
        Route::get('/grouped/modules', [PermissionController::class, 'groupedByModule']);
        Route::get('/modules/list', [PermissionController::class, 'modules']);
        Route::get('/actions/list', [PermissionController::class, 'actions']);
    });

    /*
    |--------------------------------------------------------------------------
    | NEW: Company Management Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('admin')->group(function () {
        
        // Company Routes
        Route::prefix('companies')->group(function () {
            Route::get('/', [CompanyController::class, 'index'])
                ->middleware('permission:users.view');
            
            Route::post('/', [CompanyController::class, 'store'])
                ->middleware('role:Super Admin');
            
            Route::get('/{id}', [CompanyController::class, 'show'])
                ->middleware('permission:users.view');
            
            Route::put('/{id}', [CompanyController::class, 'update'])
                ->middleware('permission:users.view');
            
            Route::delete('/{id}', [CompanyController::class, 'destroy'])
                ->middleware('role:Super Admin');
            
            Route::post('/{id}/restore', [CompanyController::class, 'restore'])
                ->middleware('role:Super Admin');
            
            Route::get('/statistics/overview', [CompanyController::class, 'statistics'])
                ->middleware('permission:users.view');
        });

        // Register Routes
        Route::prefix('registers')->group(function () {
            Route::get('/', [RegisterController::class, 'index'])
                ->middleware('permission:users.view');
            
            Route::post('/', [RegisterController::class, 'store'])
                ->middleware('role:Super Admin');
            
            Route::get('/{id}', [RegisterController::class, 'show'])
                ->middleware('permission:users.view');
            
            Route::put('/{id}', [RegisterController::class, 'update'])
                ->middleware('permission:users.view');
            
            Route::delete('/{id}', [RegisterController::class, 'destroy'])
                ->middleware('role:Super Admin');
        });

        // Share Class Routes
        Route::prefix('share-classes')->group(function () {
            Route::get('/', [ShareClassController::class, 'index'])
                ->middleware('permission:users.view');
            
            Route::post('/', [ShareClassController::class, 'store'])
                ->middleware('role:Super Admin');
            
            Route::get('/{id}', [ShareClassController::class, 'show'])
                ->middleware('permission:users.view');
            
            Route::put('/{id}', [ShareClassController::class, 'update'])
                ->middleware('permission:users.view');
            
            Route::delete('/{id}', [ShareClassController::class, 'destroy'])
                ->middleware('role:Super Admin');
        });
    });
});
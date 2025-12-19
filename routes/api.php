<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\CompanyController;
use App\Http\Controllers\Api\Admin\RegisterController;
use App\Http\Controllers\Api\Admin\ShareClassController;
use App\Http\Controllers\Api\Admin\ShareholderController;
use App\Http\Controllers\Api\UserActivityLogController;
use App\Http\Controllers\Api\SraGuardianController;
use App\Http\Controllers\Api\ProbateCaseController;
use App\Http\Controllers\Api\ShareAllocationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Authentication Routes (Public)
Route::prefix('auth')->group(function () {
    Route::middleware(['web'])->group(function () {
        Route::get('/microsoft/redirect', [AuthController::class, 'redirectToMicrosoft']);
        // Optional target lets us pick prod or local frontend (e.g. /local/microsoft/callback)
        Route::get('/{target?}/microsoft/callback', [AuthController::class, 'handleMicrosoftCallback'])
            ->whereIn('target', ['local', null]);
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
        Route::post('/{adminUser}/roles', [AdminUserController::class, 'assignRoles'])->middleware('permission:users.edit');
        Route::delete('/{adminUser}/roles', [AdminUserController::class, 'revokeRoles'])->middleware('permission:users.edit');
        Route::get('/{adminUser}/roles', [AdminUserController::class, 'getRoles'])->middleware('permission:users.view');
        Route::post('/{adminUser}/permissions', [AdminUserController::class, 'assignPermissions'])->middleware('permission:users.edit');
        Route::delete('/{adminUser}/permissions', [AdminUserController::class, 'revokePermissions'])->middleware('permission:users.edit');
        Route::get('/{adminUser}/permissions', [AdminUserController::class, 'getPermissions'])->middleware('permission:users.view');
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
        Route::post('/', [PermissionController::class, 'store'])->middleware('permission:permissions.create');
        Route::post('/bulk', [PermissionController::class, 'bulkCreate'])->middleware('permission:permissions.create');
        Route::get('/{permission}', [PermissionController::class, 'show'])->middleware('permission:permissions.view');
        Route::put('/{permission}', [PermissionController::class, 'update'])->middleware('permission:permissions.edit');
        Route::delete('/{permission}', [PermissionController::class, 'destroy'])->middleware('permission:permissions.delete');
        
        // Permission-specific endpoints
        Route::get('/{permission}/roles', [PermissionController::class, 'roles'])->middleware('permission:permissions.view');
        Route::get('/{permission}/users', [PermissionController::class, 'users'])->middleware('permission:permissions.view');
        Route::get('/grouped/modules', [PermissionController::class, 'groupedByModule'])->middleware('permission:permissions.view');
        Route::get('/modules/list', [PermissionController::class, 'modules'])->middleware('permission:permissions.view');
        Route::get('/actions/list', [PermissionController::class, 'actions'])->middleware('permission:permissions.view');
    });

    // Shareholders API Routes
    Route::prefix('shareholders')->group(function () {
        Route::get('/', [ShareholderController::class, 'index'])->middleware('permission:shareholders.view');
        Route::post('/', [ShareholderController::class, 'store'])->middleware('permission:shareholders.create');
        Route::get('/{shareholder}', [ShareholderController::class, 'show'])->middleware('permission:shareholders.view');
        Route::put('/{shareholder}', [ShareholderController::class, 'update'])->middleware('permission:shareholders.edit');
        Route::delete('/{shareholder}', [ShareholderController::class, 'destroy'])->middleware('permission:shareholders.delete');
        Route::post('/{shareholder}/addresses', [ShareholderController::class, 'addAddress'])->middleware('permission:shareholders.edit');
        Route::put('/{shareholder}/addresses/{address}', [ShareholderController::class, 'updateAddress'])->middleware('permission:shareholders.edit');
        Route::post('/{shareholder}/mandates', [ShareholderController::class, 'addMandate'])->middleware('permission:shareholder_mandates.create');
        Route::put('/{shareholder}/mandates/{mandate}', [ShareholderController::class, 'updateMandate'])->middleware('permission:shareholder_mandates.edit');
        Route::post('/{shareholder}/identities', [ShareholderController::class, 'shareholderIdentityCreate'])->middleware('permission:shareholder_identities.create');
        Route::put('/{shareholder}/identities/{identity}', [ShareholderController::class, 'shareholderIdentityUpdate'])->middleware('permission:shareholder_identities.edit');
    });

    // User Activity Logs
    Route::prefix('user-activity-logs')->group(function () {
        Route::get('/', [UserActivityLogController::class, 'index'])->middleware('permission:user_activity_logs.view');
        Route::post('/', [UserActivityLogController::class, 'store'])->middleware('permission:user_activity_logs.create');
        Route::get('/{userActivityLog}', [UserActivityLogController::class, 'show'])->middleware('permission:user_activity_logs.view');
        Route::put('/{userActivityLog}', [UserActivityLogController::class, 'update'])->middleware('permission:user_activity_logs.edit');
        Route::delete('/{userActivityLog}', [UserActivityLogController::class, 'destroy'])->middleware('permission:user_activity_logs.delete');
        Route::post('/bulk-delete', [UserActivityLogController::class, 'bulkDestroy'])->middleware('permission:user_activity_logs.delete');
        Route::post('/{id}/restore', [UserActivityLogController::class, 'restore'])->middleware('permission:user_activity_logs.edit');
        Route::delete('/{id}/force', [UserActivityLogController::class, 'forceDelete'])->middleware('permission:user_activity_logs.delete');
    });

    // Guardianship (SRA Guardians)
    Route::prefix('sra-guardians')->group(function () {
        Route::get('/', [SraGuardianController::class, 'index'])->middleware('permission:sra_guardians.view');
        Route::post('/', [SraGuardianController::class, 'store'])->middleware('permission:sra_guardians.create');
        Route::get('/{sraGuardian}', [SraGuardianController::class, 'show'])->middleware('permission:sra_guardians.view');
        Route::put('/{sraGuardian}', [SraGuardianController::class, 'update'])->middleware('permission:sra_guardians.edit');
        Route::delete('/{sraGuardian}', [SraGuardianController::class, 'destroy'])->middleware('permission:sra_guardians.delete');
    });

    // Probate cases & beneficiaries
    Route::prefix('probates')->group(function () {
        Route::get('/', [ProbateCaseController::class, 'index'])->middleware('permission:probates.view');
        Route::post('/', [ProbateCaseController::class, 'store'])->middleware('permission:probates.create');
        Route::get('/{probateCase}', [ProbateCaseController::class, 'show'])->middleware('permission:probates.view');
        Route::put('/{probateCase}', [ProbateCaseController::class, 'update'])->middleware('permission:probates.edit');
        Route::delete('/{probateCase}', [ProbateCaseController::class, 'destroy'])->middleware('permission:probates.delete');

        // beneficiaries under a probate case
        Route::post('/{probateCase}/beneficiaries', [ProbateCaseController::class, 'addBeneficiary'])->middleware('permission:probates.edit');
        Route::post('/beneficiaries/{id}/execute', [ProbateCaseController::class, 'executeBeneficiary'])->middleware('permission:probates.edit');
    });

    // Share data endpoints
    Route::prefix('share-positions')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SharePositionController::class, 'index'])->middleware('permission:shares.view');
        Route::get('/{sharePosition}', [\App\Http\Controllers\Api\SharePositionController::class, 'show'])->middleware('permission:shares.view');
        Route::put('/{sharePosition}', [\App\Http\Controllers\Api\SharePositionController::class, 'update'])->middleware('permission:shares.edit');
    });

    Route::prefix('share-lots')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ShareLotController::class, 'index'])->middleware('permission:shares.view');
        Route::get('/{shareLot}', [\App\Http\Controllers\Api\ShareLotController::class, 'show'])->middleware('permission:shares.view');
    });

    Route::prefix('share-transactions')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ShareTransactionController::class, 'index'])->middleware('permission:shares.view');
        Route::get('/{shareTransaction}', [\App\Http\Controllers\Api\ShareTransactionController::class, 'show'])->middleware('permission:shares.view');
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

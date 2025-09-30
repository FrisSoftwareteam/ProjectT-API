<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Authentication Routes (Public)
Route::prefix('auth')->group(function () {
    // Microsoft OAuth Routes
    Route::get('/microsoft/redirect', [AuthController::class, 'redirectToMicrosoft']);
    Route::get('/microsoft/callback', [AuthController::class, 'handleMicrosoftCallback']);
    
    // Simulation Routes (for testing)
    Route::post('/simulate', [AuthController::class, 'simulateLogin']);
    Route::get('/simulation-users', [AuthController::class, 'getSimulationUsers']);
});

// Protected Routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // User info
    Route::get('/user', [AuthController::class, 'me']);
    
    // Auth management
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    
    // Admin Users API Routes
    Route::prefix('admin')->group(function () {
        Route::apiResource('users', AdminUserController::class);
    });

    // Roles API Routes
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index']);
        Route::post('/', [RoleController::class, 'store']);
        Route::get('/{role}', [RoleController::class, 'show']);
        Route::put('/{role}', [RoleController::class, 'update']);
        Route::delete('/{role}', [RoleController::class, 'destroy']);
        
        // Role-specific endpoints
        Route::post('/{role}/permissions', [RoleController::class, 'assignPermissions']);
        Route::delete('/{role}/permissions', [RoleController::class, 'revokePermissions']);
        Route::get('/{role}/users', [RoleController::class, 'users']);
        Route::get('/{role}/available-permissions', [RoleController::class, 'availablePermissions']);
    });

    // Permissions API Routes
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);
        Route::post('/', [PermissionController::class, 'store']);
        Route::post('/bulk', [PermissionController::class, 'bulkCreate']);
        Route::get('/{permission}', [PermissionController::class, 'show']);
        Route::put('/{permission}', [PermissionController::class, 'update']);
        Route::delete('/{permission}', [PermissionController::class, 'destroy']);
        
        // Permission-specific endpoints
        Route::get('/{permission}/roles', [PermissionController::class, 'roles']);
        Route::get('/{permission}/users', [PermissionController::class, 'users']);
        Route::get('/grouped/modules', [PermissionController::class, 'groupedByModule']);
        Route::get('/modules/list', [PermissionController::class, 'modules']);
        Route::get('/actions/list', [PermissionController::class, 'actions']);
    });
});

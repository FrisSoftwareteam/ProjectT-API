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
Route::middleware(['auth:sanctum'])->group(function () {
    // User info
    Route::get('/user', [AuthController::class, 'me']);
    
    // Auth management
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    
    // Admin Users API Routes
    Route::prefix('admin/users')->group(function () {
        // User role and permission management (must be before resource routes)
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
        
        // Role-specific endpoints
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
        
        // Permission-specific endpoints
        Route::get('/{permission}/roles', [PermissionController::class, 'roles']);
        Route::get('/{permission}/users', [PermissionController::class, 'users']);
        Route::get('/grouped/modules', [PermissionController::class, 'groupedByModule']);
        Route::get('/modules/list', [PermissionController::class, 'modules']);
        Route::get('/actions/list', [PermissionController::class, 'actions']);
    });
});

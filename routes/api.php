<?php

use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\CompanyController;
use App\Http\Controllers\Api\Admin\DividendAuditController;
use App\Http\Controllers\Api\Admin\DividendEntitlementController;
use App\Http\Controllers\Api\Admin\DividendExportController;
use App\Http\Controllers\Api\Admin\DividendPaymentController;
use App\Http\Controllers\Api\Admin\DividendValidationController;
use App\Http\Controllers\Api\Admin\InstrumentTypeController;
use App\Http\Controllers\Api\Admin\NibssController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RegisterController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\ShareClassController;
use App\Http\Controllers\Api\Admin\ShareholderCategoryController;
use App\Http\Controllers\Api\Admin\ShareholderController;
use App\Http\Controllers\Api\AuditReportController;
use App\Http\Controllers\Api\BankVerificationController;
use App\Http\Controllers\Api\CautionController;
use App\Http\Controllers\Api\CscsUploadController;
use App\Http\Controllers\Api\IpoOfferController;
use App\Http\Controllers\Api\LegacyMigrationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProbateCaseController;
use App\Http\Controllers\Api\ShareAllocationController;
use App\Http\Controllers\Api\ShareholderMergeController;
use App\Http\Controllers\Api\ShareholderRegisterAccountController;
use App\Http\Controllers\Api\ShareTransferController;
use App\Http\Controllers\Api\SraGuardianController;
use App\Http\Controllers\Api\UserActivityLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Authentication Routes (Public)
Route::prefix('auth')->group(function () {
    Route::middleware(['web'])->group(function () {
        Route::get('/microsoft/redirect', [AuthController::class, 'redirectToMicrosoft']);
        // Explicit routes keep the Microsoft callback suffix stable
        Route::get('/microsoft/callback', [AuthController::class, 'handleMicrosoftCallback']);
        Route::get('/local/microsoft/callback', [AuthController::class, 'handleMicrosoftCallback'])
            ->defaults('target', 'local');
    });

    Route::post('/simulate', [AuthController::class, 'simulateLogin']);
    Route::get('/simulation-users', [AuthController::class, 'getSimulationUsers']);
});

// Protected Routes (require authentication)
Route::middleware(['auth:sanctum', 'activity.log'])->group(function () {
    // User info
    Route::get('/user', [AuthController::class, 'me']);

    // Personal in-app notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('/{notificationId}/read', [NotificationController::class, 'markAsRead']);
        Route::delete('/{notificationId}', [NotificationController::class, 'destroy']);
    });

    // Auth management
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Reporting
    Route::get('/reports/audit', [AuditReportController::class, 'index'])
        ->middleware('permission:reports.audit');

    // Bank Verification Routes
    Route::get('/banks', [BankVerificationController::class, 'bankList']);
    Route::post('/banks/verify', [BankVerificationController::class, 'verify']);

    // =========================================================================
    // CAUTION ACCOUNT MODULE
    // =========================================================================

    // Shareholder-level summary — all cautions across all registers
    Route::get(
        '/shareholders/{shareholder_id}/caution-summary',
        [CautionController::class, 'summary']
    )->middleware('permission:shareholders.view');

    // SRA-level caution operations (register-scoped)
    Route::prefix('sras/{sra_id}/cautions')->group(function () {
        Route::get('/', [CautionController::class, 'index'])
            ->middleware('permission:shareholders.view');
        Route::post('/', [CautionController::class, 'store'])
            ->middleware('permission:shareholders.edit');
        Route::get('/{caution_id}', [CautionController::class, 'show'])
            ->middleware('permission:shareholders.view');
        Route::delete('/{caution_id}', [CautionController::class, 'destroy'])
            ->middleware('permission:shareholders.edit');
        Route::get('/{caution_id}/logs', [CautionController::class, 'logs'])
            ->middleware('permission:shareholders.view');
    });

    // Admin Users API Routes
    Route::prefix('admin/users')->group(function () {
        Route::post('/{adminUser}/profile-picture', [AdminUserController::class, 'uploadProfilePicture'])->middleware('permission:users.edit');
        Route::post('/{adminUser}/roles', [AdminUserController::class, 'assignRoles'])->middleware('permission:users.edit');
        Route::delete('/{adminUser}/roles', [AdminUserController::class, 'revokeRoles'])->middleware('permission:users.edit');
        Route::get('/{adminUser}/roles', [AdminUserController::class, 'getRoles'])->middleware('permission:users.view');
        Route::get('/{adminUser}/roles-with-permissions', [AdminUserController::class, 'getRolesWithPermissions'])
            ->middleware('permission:users.view');
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
        Route::post('/bulk', [ShareholderController::class, 'bulkStore'])->middleware('permission:shareholders.create');
        Route::post('/with-details', [ShareholderController::class, 'storeWithDetails'])->middleware('permission:shareholders.create');
        Route::post('/{shareholder}/profile-picture', [ShareholderController::class, 'uploadProfilePicture'])->middleware('permission:shareholders.edit');
        Route::get('/{shareholder}', [ShareholderController::class, 'show'])->middleware('permission:shareholders.view');
        Route::put('/{shareholder}', [ShareholderController::class, 'update'])->middleware('permission:shareholders.edit');
        Route::delete('/{shareholder}', [ShareholderController::class, 'destroy'])->middleware('permission:shareholders.delete');
        Route::post('/{shareholder}/addresses', [ShareholderController::class, 'addAddress'])->middleware('permission:shareholders.edit');
        Route::put('/{shareholder}/addresses/{address}', [ShareholderController::class, 'updateAddress'])->middleware('permission:shareholders.edit');
        Route::post('/{shareholder}/mandates', [ShareholderController::class, 'addMandate'])->middleware('permission:shareholder_mandates.create');
        Route::put('/{shareholder}/mandates/{mandate}', [ShareholderController::class, 'updateMandate'])->middleware('permission:shareholder_mandates.edit');
        Route::post('/{shareholder}/identities', [ShareholderController::class, 'shareholderIdentityCreate'])->middleware('permission:shareholder_identities.create');
        Route::put('/{shareholder}/identities/{identity}', [ShareholderController::class, 'shareholderIdentityUpdate'])->middleware('permission:shareholder_identities.edit');
        Route::post('/{shareholder}/register-accounts', [ShareholderController::class, 'addRegisterAccount'])->middleware('permission:shareholders.edit');

        // Share posting endpoints (inflow/outflow)
        Route::post('/{shareholder}/shares/allocate', [ShareAllocationController::class, 'allocate'])->middleware('permission:shares.create');
        Route::post('/{shareholder}/shares/dispose', [ShareAllocationController::class, 'dispose'])->middleware('permission:shares.transfer');
    });

    Route::prefix('shareholder-categories')->group(function () {
        Route::get('/', [ShareholderCategoryController::class, 'index'])
            ->middleware('permission:shareholders.view');
        Route::post('/', [ShareholderCategoryController::class, 'store'])
            ->middleware('permission:shareholders.edit');
        Route::get('/{id}', [ShareholderCategoryController::class, 'show'])
            ->middleware('permission:shareholders.view');
        Route::put('/{id}', [ShareholderCategoryController::class, 'update'])
            ->middleware('permission:shareholders.edit');
        Route::patch('/{id}', [ShareholderCategoryController::class, 'update'])
            ->middleware('permission:shareholders.edit');
        Route::delete('/{id}', [ShareholderCategoryController::class, 'destroy'])
            ->middleware('permission:shareholders.edit');
        Route::post('/{id}/restore', [ShareholderCategoryController::class, 'restore'])
            ->middleware('permission:shareholders.edit');
    });

    Route::patch(
        '/shareholder-register-accounts/{id}/category',
        [ShareholderRegisterAccountController::class, 'updateCategory']
    )->middleware('permission:shareholders.edit');

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
        Route::get('/shareholders/{shareholder}/admins', [ProbateCaseController::class, 'adminsForShareholder'])->middleware('permission:probates.view');
        Route::get('/{probateCase}', [ProbateCaseController::class, 'show'])->middleware('permission:probates.view');
        Route::put('/{probateCase}', [ProbateCaseController::class, 'update'])->middleware('permission:probates.edit');
        Route::delete('/{probateCase}', [ProbateCaseController::class, 'destroy'])->middleware('permission:probates.delete');

        // beneficiaries under a probate case
        Route::post('/{probateCase}/beneficiaries', [ProbateCaseController::class, 'addBeneficiary'])->middleware('permission:probates.edit');
        Route::post('/{probateCase}/representatives', [ProbateCaseController::class, 'addRepresentative'])->middleware('permission:probates.edit');
        Route::post('/{probateCase}/distributions', [ProbateCaseController::class, 'distribute'])->middleware('permission:probates.edit');
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
        Route::post('/', [\App\Http\Controllers\Api\ShareTransactionController::class, 'store'])->middleware('permission:shares.edit');
        Route::get('/{shareTransaction}', [\App\Http\Controllers\Api\ShareTransactionController::class, 'show'])->middleware('permission:shares.view');
    });

    Route::post('/share-transfers', [ShareTransferController::class, 'store'])->middleware('permission:shares.transfer');
    Route::post('/shareholders/merge', [ShareholderMergeController::class, 'store'])->middleware('permission:shareholders.edit');

    // CSCS staged reconciliation and maker-checker workflow
    Route::prefix('cscs')->group(function () {
        Route::post('/import', [CscsUploadController::class, 'import'])->middleware(['permission:cscs.upload', 'throttle:5,1']);

        Route::get('/security-mappings', [CscsUploadController::class, 'securityMappings'])->middleware('permission:cscs.view');
        Route::post('/security-mappings', [CscsUploadController::class, 'storeSecurityMapping'])->middleware('permission:cscs.admin');
        Route::patch('/security-mappings/{mappingId}', [CscsUploadController::class, 'updateSecurityMapping'])->middleware('permission:cscs.admin');
        Route::post('/security-mappings/{mappingId}/deactivate', [CscsUploadController::class, 'deactivateSecurityMapping'])->middleware('permission:cscs.admin');
        Route::get('/approval-policy', [CscsUploadController::class, 'approvalPolicy'])->middleware('permission:cscs.view');
        Route::put('/approval-policy', [CscsUploadController::class, 'updateApprovalPolicy'])->middleware('permission:cscs.admin');

        Route::get('/uploads', [CscsUploadController::class, 'index'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}', [CscsUploadController::class, 'show'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/rows', [CscsUploadController::class, 'rows'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/rows/{rowId}', [CscsUploadController::class, 'row'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/master-records', [CscsUploadController::class, 'masterRecords'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/transactions', [CscsUploadController::class, 'transactions'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/transactions/{transactionNumber}', [CscsUploadController::class, 'transaction'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/account-effects', [CscsUploadController::class, 'accountEffects'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/preview', [CscsUploadController::class, 'preview'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/exceptions', [CscsUploadController::class, 'exceptions'])->middleware('permission:cscs.view');
        Route::post('/uploads/{batchId}/exceptions/{exceptionId}/resolve', [CscsUploadController::class, 'resolveException'])->middleware('permission:cscs.reconcile');
        Route::post('/uploads/{batchId}/revalidate', [CscsUploadController::class, 'revalidate'])->middleware('permission:cscs.reconcile');
        Route::post('/uploads/{batchId}/reconcile', [CscsUploadController::class, 'revalidate'])->middleware('permission:cscs.reconcile');
        Route::get('/uploads/{batchId}/reconciliation', [CscsUploadController::class, 'reconciliation'])->middleware('permission:cscs.view');
        Route::post('/uploads/{batchId}/submit', [CscsUploadController::class, 'submit'])->middleware(['permission:cscs.submit', 'throttle:20,1']);
        Route::post('/uploads/{batchId}/query', [CscsUploadController::class, 'raiseQuery'])->middleware('permission:cscs.review');
        Route::post('/uploads/{batchId}/respond-to-query', [CscsUploadController::class, 'respondToQuery'])->middleware('permission:cscs.reconcile');
        Route::post('/uploads/{batchId}/approve', [CscsUploadController::class, 'approve'])->middleware(['permission:cscs.approve', 'throttle:20,1']);
        Route::post('/uploads/{batchId}/reject', [CscsUploadController::class, 'reject'])->middleware(['permission:cscs.approve', 'throttle:20,1']);
        Route::post('/uploads/{batchId}/cancel', [CscsUploadController::class, 'cancel'])->middleware('permission:cscs.submit');
        Route::post('/uploads/{batchId}/post', [CscsUploadController::class, 'post'])->middleware(['permission:cscs.post', 'throttle:10,1']);
        Route::post('/uploads/{batchId}/retry-posting', [CscsUploadController::class, 'post'])->middleware(['permission:cscs.post', 'throttle:10,1']);
        Route::get('/uploads/{batchId}/posting-status', [CscsUploadController::class, 'postingStatus'])->middleware('permission:cscs.view');
        Route::post('/uploads/{batchId}/create-reversal', [CscsUploadController::class, 'createReversal'])->middleware(['permission:cscs.upload', 'throttle:5,1']);
        Route::get('/uploads/{batchId}/related-batches', [CscsUploadController::class, 'relatedBatches'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/events', [CscsUploadController::class, 'events'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/approvals', [CscsUploadController::class, 'approvals'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/snapshots', [CscsUploadController::class, 'snapshots'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/files', [CscsUploadController::class, 'files'])->middleware('permission:cscs.view');
        Route::get('/uploads/{batchId}/files/{fileIndex}/download', [CscsUploadController::class, 'downloadFile'])->middleware('permission:cscs.export');
        Route::get('/uploads/{batchId}/export', [CscsUploadController::class, 'export'])->middleware('permission:cscs.export');
    });

    // Controlled, auditable legacy-data migration workflow
    Route::prefix('legacy-migrations')->group(function () {
        Route::get('/packages', [LegacyMigrationController::class, 'packages'])->middleware('permission:legacy_migrations.view');
        Route::get('/batches', [LegacyMigrationController::class, 'index'])->middleware('permission:legacy_migrations.view');
        Route::post('/batches', [LegacyMigrationController::class, 'create'])->middleware(['permission:legacy_migrations.create', 'throttle:5,1']);
        Route::get('/batches/{batchId}', [LegacyMigrationController::class, 'show'])->middleware('permission:legacy_migrations.view');
        Route::get('/batches/{batchId}/events', [LegacyMigrationController::class, 'events'])->middleware('permission:legacy_migrations.view');
        Route::post('/batches/{batchId}/stage', [LegacyMigrationController::class, 'stage'])->middleware(['permission:legacy_migrations.stage', 'throttle:5,1']);
        Route::post('/batches/{batchId}/reconcile', [LegacyMigrationController::class, 'reconcile'])->middleware('permission:legacy_migrations.reconcile');
        Route::post('/batches/{batchId}/submit', [LegacyMigrationController::class, 'submit'])->middleware('permission:legacy_migrations.submit');
        Route::post('/batches/{batchId}/approve', [LegacyMigrationController::class, 'approve'])->middleware('permission:legacy_migrations.approve');
        Route::post('/batches/{batchId}/publish', [LegacyMigrationController::class, 'publish'])->middleware(['permission:legacy_migrations.publish', 'throttle:5,1']);
        Route::post('/batches/{batchId}/cancel', [LegacyMigrationController::class, 'cancel'])->middleware('permission:legacy_migrations.submit');
        Route::post('/batches/{batchId}/rollback', [LegacyMigrationController::class, 'rollback'])->middleware(['permission:legacy_migrations.rollback', 'throttle:5,1']);
    });

    // IPO / Offer processing
    Route::prefix('offers')->group(function () {
        Route::get('/', [IpoOfferController::class, 'index'])->middleware('permission:shares.view');
        Route::post('/', [IpoOfferController::class, 'store'])->middleware('permission:shares.create');
        Route::post('/{offer}/allotments', [IpoOfferController::class, 'addAllotment'])->middleware('permission:shares.create');
        Route::post('/{offer}/finalize', [IpoOfferController::class, 'finalize'])->middleware('permission:shares.edit');
    });

    /*
    |--------------------------------------------------------------------------
    | Admin Management Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('admin')->group(function () {

        // Company Routes
        Route::prefix('companies')->group(function () {
            Route::get('/', [CompanyController::class, 'index'])
                ->middleware('permission:companies.view');

            Route::post('/', [CompanyController::class, 'store'])
                ->middleware('permission:companies.create');

            Route::get('/{id}', [CompanyController::class, 'show'])
                ->middleware('permission:companies.view');

            Route::get('/{id}/full-context', [CompanyController::class, 'fullContext'])
                ->middleware('permission:companies.view');

            Route::put('/{id}', [CompanyController::class, 'update'])
                ->middleware('permission:companies.edit');

            Route::delete('/{id}', [CompanyController::class, 'destroy'])
                ->middleware('permission:companies.delete');

            Route::post('/{id}/restore', [CompanyController::class, 'restore'])
                ->middleware('permission:companies.restore');

            Route::get('/statistics/overview', [CompanyController::class, 'statistics'])
                ->middleware('permission:companies.view');

            // Validate dividend period uniqueness
            Route::get('/{company_id}/dividend-declarations/validate-period', [DividendValidationController::class, 'validatePeriod'])
                ->middleware('permission:companies.view');
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

            Route::get('/{id}/capital-status', [RegisterController::class, 'capitalStatus'])
                ->middleware('permission:users.view');

            Route::post('/{id}/capital-check', [RegisterController::class, 'capitalCheck'])
                ->middleware('permission:users.view');

            // =================================================================
            // DIVIDEND DECLARATION MANAGEMENT (Nested under registers)
            // =================================================================

            // List Dividend Declarations for a specific register
            Route::get('/{register_id}/dividend-declarations', [DividendEntitlementController::class, 'indexForRegister'])
                ->middleware('permission:users.view');

            // Create Dividend Declaration (Draft) for a specific register
            Route::post('/{register_id}/dividend-declarations', [DividendEntitlementController::class, 'store'])
                ->middleware('role:Super Admin|Admin');
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

            // Tax calculation endpoint
            Route::post('/{id}/calculate-tax', [ShareClassController::class, 'calculateTax'])
                ->middleware('permission:users.view');
        });

        // Instrument Types
        Route::prefix('instrument-types')->group(function () {
            Route::get('/', [InstrumentTypeController::class, 'index'])
                ->middleware('permission:users.view');

            Route::get('/{instrumentType}', [InstrumentTypeController::class, 'show'])
                ->middleware('permission:users.view');

            Route::post('/', [InstrumentTypeController::class, 'store'])
                ->middleware('role:Super Admin');

            Route::put('/{instrumentType}', [InstrumentTypeController::class, 'update'])
                ->middleware('role:Super Admin');

            Route::patch('/{instrumentType}/deactivate', [InstrumentTypeController::class, 'deactivate'])
                ->middleware('role:Super Admin');
        });

        // =================================================================
        // DIVIDEND DECLARATION ROUTES (Standalone operations)
        // =================================================================

        Route::prefix('dividend-declarations')->group(function () {

            // Get Dividend Declaration (Full Context)
            Route::get('/{declaration_id}', [DividendEntitlementController::class, 'show'])
                ->middleware('permission:users.view');

            // Update Dividend Declaration (Draft Only)
            Route::put('/{declaration_id}', [DividendEntitlementController::class, 'update'])
                ->middleware('role:Super Admin|Admin');

            // Cancel Draft Declaration
            Route::delete('/{declaration_id}', [DividendEntitlementController::class, 'destroy'])
                ->middleware('role:Super Admin');

            // =================================================================
            // DIVIDEND WORKFLOW (Maker-Checker)
            // =================================================================

            // Submit Dividend Declaration
            Route::post('/{declaration_id}/submit', [DividendEntitlementController::class, 'submit'])
                ->middleware('role:Super Admin|Admin');

            // Verify Dividend Declaration
            Route::post('/{declaration_id}/verify', [DividendEntitlementController::class, 'verify'])
                ->middleware('role:Super Admin|Admin');

            // Approve Dividend Declaration
            Route::post('/{declaration_id}/approve', [DividendEntitlementController::class, 'approve'])
                ->middleware('role:Super Admin|Admin');

            // Reject Dividend Declaration
            Route::post('/{declaration_id}/reject', [DividendEntitlementController::class, 'reject'])
                ->middleware('role:Super Admin|Admin');

            // Raise query at active approval step
            Route::post('/{declaration_id}/query', [DividendEntitlementController::class, 'raiseQuery'])
                ->middleware('role:Super Admin|Admin');

            // Respond to query (initiator comment)
            Route::post('/{declaration_id}/query/respond', [DividendEntitlementController::class, 'respondQuery'])
                ->middleware('role:Super Admin|Admin');

            // Assign reliever for approval role
            Route::post('/{declaration_id}/delegations', [DividendEntitlementController::class, 'assignDelegation'])
                ->middleware('role:Super Admin|Admin');

            // Archive and resume pre-live declarations
            Route::post('/{declaration_id}/archive', [DividendEntitlementController::class, 'archive'])
                ->middleware('role:Super Admin|Admin');
            Route::post('/{declaration_id}/resume', [DividendEntitlementController::class, 'resume'])
                ->middleware('role:Super Admin|Admin');

            // Go Live action (freeze and generate payment records)
            Route::post('/{declaration_id}/go-live', [DividendEntitlementController::class, 'goLive'])
                ->middleware('role:Super Admin|Admin');

            // =================================================================
            // EXPORTS (Approved Only)
            // =================================================================

            // Export Entitlement File (CSV)
            Route::get('/{declaration_id}/exports/entitlements', [DividendExportController::class, 'entitlements'])
                ->middleware('permission:companies.view');

            // Export Payment File (CSV/XLSX)
            Route::get('/{declaration_id}/exports/payments', [DividendExportController::class, 'payments'])
                ->middleware('permission:companies.view');

            // Export Dividend Summary (PDF)
            Route::get('/{declaration_id}/exports/summary', [DividendExportController::class, 'summary'])
                ->middleware('permission:companies.view');

            // =================================================================
            // DIVIDEND AUDIT LOGS
            // =================================================================

            // Dividend Audit Log
            Route::get('/{declaration_id}/audit-logs', [DividendAuditController::class, 'index'])
                ->middleware('permission:companies.view');

            // =================================================================
            // DIVIDEND PAYMENTS
            // =================================================================

            // List Dividend Payments
            Route::get('/{declaration_id}/payments', [DividendPaymentController::class, 'index'])
                ->middleware('permission:companies.view');

            // =================================================================
            // ENTITLEMENT PREVIEW & COMPUTATION
            // =================================================================

            // Generate Entitlement Preview (Compute)
            Route::post('/{declaration_id}/preview', [DividendEntitlementController::class, 'generatePreview'])
                ->middleware('permission:users.view');

            // Fetch Entitlement Preview (Paginated)
            Route::get('/{declaration_id}/preview', [DividendEntitlementController::class, 'fetchPreview'])
                ->middleware('permission:users.view');
        });

        // Dividend payment actions (standalone)
        Route::post('/dividend-payments/{payment_id}/reissue', [DividendPaymentController::class, 'reissue'])
            ->middleware('role:Super Admin|Admin');

        // NIBSS PAY Routes
        Route::prefix('nibss')->group(function () {
            Route::get('/accounts', [NibssController::class, 'getAccounts'])
                ->middleware('permission:nibss_pay.edit');

            Route::get('/bank-list', [NibssController::class, 'getBankList'])
                ->middleware('permission:nibss_pay.edit');

            Route::post('/schedules/create', [NibssController::class, 'createSchedule'])
                ->middleware('permission:nibss_pay.edit');

            Route::get('/schedules', [NibssController::class, 'getSchedules'])
                ->middleware('permission:nibss_pay.edit');

            Route::post('/accounts', [NibssController::class, 'postAccounts'])
                ->middleware('permission:nibss_pay.edit');

        });
    });
});

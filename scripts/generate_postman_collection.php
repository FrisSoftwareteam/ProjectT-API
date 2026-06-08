<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath.'/vendor/autoload.php';

$routeJson = shell_exec('cd '.escapeshellarg($basePath).' && php artisan route:list --path=api --json');
$routes = json_decode((string) $routeJson, true, flags: JSON_THROW_ON_ERROR);

$jsonPayloads = [
    'AuthController@simulateLogin' => ['email' => 'admin@example.com'],
    'BankVerificationController@verify' => ['bank_code' => '058', 'account_number' => '0123456789'],
    'AdminUserController@store' => ['email' => 'admin@example.com', 'first_name' => 'Jane', 'last_name' => 'Admin', 'department' => 'Operations', 'is_active' => true],
    'AdminUserController@update' => ['email' => 'admin@example.com', 'first_name' => 'Jane', 'last_name' => 'Admin', 'department' => 'Operations', 'is_active' => true],
    'AdminUserController@assignRoles' => ['roles' => ['Admin']],
    'AdminUserController@revokeRoles' => ['roles' => ['Admin']],
    'AdminUserController@assignPermissions' => ['permissions' => [1, 2]],
    'AdminUserController@revokePermissions' => ['permissions' => ['users.view']],
    'RoleController@store' => ['name' => 'Frontend Test Role', 'permissions' => ['shareholders.view']],
    'RoleController@update' => ['name' => 'Frontend Test Role', 'permissions' => ['shareholders.view', 'shares.view']],
    'RoleController@assignPermissions' => ['permissions' => ['shareholders.view', 'shares.view']],
    'RoleController@revokePermissions' => ['permissions' => ['shares.view']],
    'PermissionController@store' => ['name' => 'example.view', 'module' => 'example', 'action' => 'view', 'description' => 'Example permission'],
    'PermissionController@update' => ['name' => 'example.edit', 'module' => 'example', 'action' => 'edit', 'description' => 'Updated example permission'],
    'PermissionController@bulkCreate' => ['permissions' => [['name' => 'example.view', 'module' => 'example', 'action' => 'view', 'description' => 'Example permission']]],
    'ShareholderController@store' => ['holder_type' => 'individual', 'first_name' => 'Ada', 'last_name' => 'Okafor', 'email' => 'ada.okafor@example.com', 'phone' => '08012345678', 'date_of_birth' => '1990-01-15', 'sex' => 'female', 'status' => 'active'],
    'ShareholderController@update' => ['first_name' => 'Ada', 'last_name' => 'Okafor', 'email' => 'ada.okafor@example.com', 'phone' => '08012345678', 'status' => 'active'],
    'ShareholderController@bulkStore' => ['shareholders' => [['holder_type' => 'individual', 'first_name' => 'Ada', 'last_name' => 'Okafor', 'email' => 'ada.bulk@example.com', 'phone' => '08012345001', 'status' => 'active']]],
    'ShareholderController@storeWithDetails' => ['shareholder' => ['holder_type' => 'individual', 'first_name' => 'Ada', 'last_name' => 'Okafor', 'email' => 'ada.details@example.com', 'phone' => '08012345002', 'status' => 'active'], 'addresses' => [['address_line1' => '1 Marina Road', 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria', 'is_primary' => true]], 'mandates' => [['bank_name' => 'Example Bank', 'account_name' => 'Ada Okafor', 'account_number' => '0123456789', 'status' => 'pending']], 'identities' => [['id_type' => 'nin', 'id_value' => '12345678901', 'verified_status' => 'pending']]],
    'ShareholderController@addAddress' => ['shareholder_id' => '{{shareholder}}', 'address_line1' => '1 Marina Road', 'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '100001', 'country' => 'Nigeria', 'is_primary' => true],
    'ShareholderController@updateAddress' => ['address_line1' => '2 Marina Road', 'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '100001', 'country' => 'Nigeria', 'is_primary' => true],
    'ShareholderController@addMandate' => ['shareholder_id' => '{{shareholder}}', 'bank_name' => 'Example Bank', 'account_name' => 'Ada Okafor', 'account_number' => '0123456789', 'bvn' => '12345678901', 'status' => 'pending'],
    'ShareholderController@updateMandate' => ['bank_name' => 'Example Bank', 'account_name' => 'Ada Okafor', 'account_number' => '0123456789', 'bvn' => '12345678901', 'status' => 'active', 'verified_by' => '{{adminUser}}', 'verified_at' => '2026-06-08'],
    'ShareholderController@shareholderIdentityCreate' => ['id_type' => 'nin', 'id_value' => '12345678901', 'verified_status' => 'pending'],
    'ShareholderController@shareholderIdentityUpdate' => ['id_type' => 'nin', 'id_value' => '12345678901', 'verified_status' => 'verified', 'verified_by' => '{{adminUser}}', 'verified_at' => '2026-06-08'],
    'ShareholderController@addRegisterAccount' => ['register_id' => '{{register_id}}', 'shareholder_no' => 'SH-000001', 'chn' => 'C123456789', 'cscs_account_no' => 'CSCS-000001', 'residency_status' => 'resident', 'kyc_level' => 'standard', 'status' => 'active'],
    'ShareAllocationController@allocate' => ['share_class_id' => '{{share_class_id}}', 'quantity' => 1000, 'source_type' => 'allotment', 'lot_ref' => 'LOT-001', 'acquired_at' => '2026-06-08', 'holding_mode' => 'demat', 'register_id' => '{{register_id}}'],
    'ShareAllocationController@dispose' => ['register_id' => '{{register_id}}', 'share_class_id' => '{{share_class_id}}', 'quantity' => 100, 'tx_type' => 'transfer_out', 'tx_ref' => 'DISP-001', 'tx_date' => '2026-06-08', 'close_position_if_zero' => false],
    'UserActivityLogController@store' => ['user_id' => '{{adminUser}}', 'action' => 'frontend_test_action', 'metadata' => ['source' => 'postman']],
    'UserActivityLogController@update' => ['user_id' => '{{adminUser}}', 'action' => 'frontend_test_action_updated', 'metadata' => ['source' => 'postman']],
    'UserActivityLogController@bulkDestroy' => ['ids' => [1, 2]],
    'SraGuardianController@store' => ['sra_id' => '{{sra_id}}', 'guardian_shareholder_id' => '{{shareholder}}', 'guardian_name' => 'Guardian Name', 'guardian_contact' => '08012345678', 'valid_from' => '2026-06-08', 'permissions' => ['receive_dividend_notices'], 'verified_status' => 'pending'],
    'SraGuardianController@update' => ['sra_id' => '{{sra_id}}', 'guardian_shareholder_id' => '{{shareholder}}', 'guardian_name' => 'Guardian Name', 'guardian_contact' => '08012345678', 'valid_from' => '2026-06-08', 'permissions' => ['receive_dividend_notices'], 'verified_status' => 'verified', 'verified_by' => '{{adminUser}}'],
    'ProbateCaseController@addBeneficiary' => ['beneficiary_shareholder_id' => '{{shareholder}}', 'relationship' => 'child', 'share_class_id' => '{{share_class_id}}', 'sra_id' => '{{sra_id}}', 'quantity' => 100],
    'ProbateCaseController@addRepresentative' => ['shareholder_ids' => [2, 3], 'is_primary' => true],
    'ProbateCaseController@distribute' => ['to_shareholder_id' => '{{shareholder}}', 'share_class_id' => '{{share_class_id}}', 'quantity' => 100, 'document_ref' => 'COURT-ORDER-001', 'reason' => 'Approved estate distribution'],
    'SharePositionController@update' => ['quantity' => 1000, 'holding_mode' => 'demat'],
    'ShareTransactionController@store' => ['sra_id' => '{{sra_id}}', 'share_class_id' => '{{share_class_id}}', 'tx_type' => 'allot', 'quantity' => 1000, 'tx_ref' => 'TX-001', 'tx_date' => '2026-06-08'],
    'ShareTransferController@store' => ['from_shareholder_id' => 1, 'to_shareholder_id' => 2, 'share_class_id' => '{{share_class_id}}', 'quantity' => 100, 'document_ref' => 'TRANSFER-DOC-001'],
    'ShareholderMergeController@store' => ['primary_shareholder_id' => 1, 'duplicate_shareholder_id' => 2, 'verification_basis' => 'identity', 'reason' => 'Verified duplicate record'],
    'IpoOfferController@store' => ['company_id' => '{{company_id}}', 'register_id' => '{{register_id}}', 'instrument_type' => 'equity', 'capital_behaviour_type' => 'constant', 'narration' => 'Public offer', 'class_code' => 'ORD', 'approved_units' => 1000000],
    'IpoOfferController@addAllotment' => ['shareholder_id' => '{{shareholder}}', 'quantity' => 1000],
    'CompanyController@store' => ['issuer_code' => 'EXMPL', 'name' => 'Example Plc', 'rc_number' => 'RC123456', 'tin' => 'TIN123456', 'status' => 'active'],
    'CompanyController@update' => ['issuer_code' => 'EXMPL', 'name' => 'Example Plc Updated', 'rc_number' => 'RC123456', 'tin' => 'TIN123456', 'status' => 'active'],
    'RegisterController@store' => ['company_id' => '{{company_id}}', 'name' => 'Ordinary Share Register', 'instrument_type' => 'equity', 'capital_behaviour_type' => 'constant', 'paid_up_capital' => 1000000, 'narration' => 'Primary register', 'is_default' => true, 'status' => 'active'],
    'RegisterController@update' => ['name' => 'Ordinary Share Register', 'instrument_type' => 'equity', 'capital_behaviour_type' => 'constant', 'paid_up_capital' => 1000000, 'narration' => 'Updated register', 'is_default' => true, 'status' => 'active'],
    'ShareClassController@store' => ['register_id' => '{{register_id}}', 'class_code' => 'ORD', 'name' => 'Ordinary Shares', 'currency' => 'NGN', 'par_value' => 0.5, 'description' => 'Ordinary share class', 'withholding_tax_rate' => 10],
    'ShareClassController@update' => ['class_code' => 'ORD', 'name' => 'Ordinary Shares', 'currency' => 'NGN', 'par_value' => 0.5, 'description' => 'Ordinary share class', 'withholding_tax_rate' => 10],
    'ShareClassController@calculateTax' => ['amount' => 10000],
    'DividendEntitlementController@store' => ['period_label' => 'FY 2026', 'description' => 'Final dividend', 'initiator' => 'operations', 'share_class_ids' => [1], 'rate_per_share' => 1.25, 'announcement_date' => '2026-06-08', 'record_date' => '2026-06-30', 'payment_date' => '2026-07-15', 'exclude_caution_accounts' => true, 'require_active_bank_mandate' => true],
    'DividendEntitlementController@update' => ['period_label' => 'FY 2026', 'description' => 'Updated final dividend', 'initiator' => 'operations', 'share_class_ids' => [1], 'rate_per_share' => 1.25, 'announcement_date' => '2026-06-08', 'record_date' => '2026-06-30', 'payment_date' => '2026-07-15', 'exclude_caution_accounts' => true, 'require_active_bank_mandate' => true],
    'DividendEntitlementController@generatePreview' => ['per_page' => 50, 'page' => 1],
    'DividendEntitlementController@reject' => ['reason' => 'Supporting figures require correction'],
    'DividendEntitlementController@raiseQuery' => ['comment' => 'Please confirm the record date'],
    'DividendEntitlementController@respondQuery' => ['comment' => 'Record date has been confirmed'],
    'DividendEntitlementController@assignDelegation' => ['role_code' => 'IT', 'reliever_user_id' => '{{adminUser}}'],
    'DividendPaymentController@reissue' => ['reason' => 'Corrected payment details'],
    'NibssController@createSchedule' => ['title' => 'Dividend Payment Schedule', 'debitBankCode' => '058', 'debitAccountNumber' => '0123456789', 'debitDescription' => 'Dividend payout', 'paymentMode' => 'NIP', 'scheduleType' => 'Nip'],
    'NibssController@postAccounts' => ['accounts' => [['accountNumber' => '0123456789', 'bankCode' => '058', 'amount' => 1000, 'narration' => 'Dividend payment']]],
    'CautionController@store' => ['scope' => 'company', 'caution_type' => 'legal', 'instruction_source' => 'court', 'reason' => 'Court restriction pending review', 'effective_date' => '2026-06-08'],
    'CautionController@destroy' => ['removal_reason' => 'Court restriction lifted'],
];

$multipartPayloads = [
    'CscsUploadController@import' => [
        ['key' => 'register_id', 'value' => '{{register_id}}', 'type' => 'text'],
        ['key' => 'files[]', 'type' => 'file', 'src' => ''],
        ['key' => 'files[]', 'type' => 'file', 'src' => ''],
    ],
    'ProbateCaseController@store' => [
        ['key' => 'shareholder_id', 'value' => '{{shareholder}}', 'type' => 'text'],
        ['key' => 'case_type', 'value' => 'probate', 'type' => 'text'],
        ['key' => 'court_ref', 'value' => 'COURT-REF-001', 'type' => 'text'],
        ['key' => 'grant_date', 'value' => '2026-06-08', 'type' => 'text'],
        ['key' => 'status', 'value' => 'pending', 'type' => 'text'],
        ['key' => 'document', 'type' => 'file', 'src' => ''],
    ],
    'ProbateCaseController@update' => [
        ['key' => 'shareholder_id', 'value' => '{{shareholder}}', 'type' => 'text'],
        ['key' => 'case_type', 'value' => 'probate', 'type' => 'text'],
        ['key' => 'court_ref', 'value' => 'COURT-REF-001', 'type' => 'text'],
        ['key' => 'status', 'value' => 'granted', 'type' => 'text'],
        ['key' => 'document', 'type' => 'file', 'src' => ''],
    ],
];

$queryExamples = [
    'AdminUserController@index' => ['search' => 'Jane', 'is_active' => 'true', 'department' => 'Operations', 'per_page' => '15'],
    'RoleController@index' => ['search' => 'Admin', 'per_page' => '15'],
    'PermissionController@index' => ['module' => 'shareholders', 'action' => 'view', 'per_page' => '15'],
    'ShareholderController@index' => ['search' => 'Ada', 'status' => 'active', 'per_page' => '15'],
    'UserActivityLogController@index' => ['user_id' => '{{adminUser}}', 'action' => 'updated', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'per_page' => '15'],
    'CautionController@index' => ['status' => 'active', 'caution_type' => 'legal'],
    'CscsUploadController@index' => ['status' => 'completed_with_errors', 'register_id' => '{{register_id}}', 'per_page' => '15'],
    'CscsUploadController@rows' => ['status' => 'failed', 'identifier' => 'C123', 'per_page' => '50'],
    'CscsUploadController@exceptions' => ['status' => 'failed', 'per_page' => '50'],
    'NotificationController@index' => ['unread_only' => 'true', 'per_page' => '20'],
    'IpoOfferController@index' => ['status' => 'approved', 'per_page' => '15'],
    'ProbateCaseController@index' => ['per_page' => '15'],
    'SraGuardianController@index' => ['shareholder_id' => '{{shareholder}}', 'sra_id' => '{{sra_id}}', 'verified_status' => 'verified', 'per_page' => '15'],
    'SharePositionController@index' => ['sra_id' => '{{sra_id}}', 'share_class_id' => '{{share_class_id}}', 'per_page' => '15'],
    'ShareLotController@index' => ['sra_id' => '{{sra_id}}', 'share_class_id' => '{{share_class_id}}', 'per_page' => '15'],
    'ShareTransactionController@index' => ['sra_id' => '{{sra_id}}', 'share_class_id' => '{{share_class_id}}', 'tx_type' => 'transfer_in', 'direction' => 'inflow', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'per_page' => '15'],
    'BankVerificationController@bankList' => ['refresh' => 'false'],
    'CompanyController@index' => ['status' => 'active', 'search' => 'Example', 'include_registers' => 'true', 'per_page' => '15'],
    'RegisterController@index' => ['company_id' => '{{company_id}}', 'status' => 'active', 'include_share_classes' => 'true', 'per_page' => '15'],
    'ShareClassController@index' => ['register_id' => '{{register_id}}', 'currency' => 'NGN', 'per_page' => '15'],
    'DividendEntitlementController@indexForRegister' => ['status' => 'DRAFT', 'initiator' => 'operations', 'search' => 'FY 2026', 'per_page' => '15'],
    'DividendValidationController@validatePeriod' => ['period_label' => 'FY 2026', 'exclude_declaration_id' => '{{declaration_id}}'],
    'DividendPaymentController@index' => ['status' => 'failed', 'per_page' => '50'],
];

$publicActions = [
    'AuthController@redirectToMicrosoft',
    'AuthController@handleMicrosoftCallback',
    'AuthController@simulateLogin',
    'AuthController@getSimulationUsers',
];

$folders = [];
$pathVariables = [];

foreach ($routes as $route) {
    $method = explode('|', $route['method'])[0];
    $uri = $route['uri'];
    $action = shortAction($route['action']);
    $folder = folderFor($uri);
    $name = requestName($method, $uri, $action);
    preg_match_all('/\{([^}]+)\}/', $uri, $matches);

    foreach ($matches[1] as $variable) {
        $pathVariables[$variable] = defaultVariableValue($variable);
    }

    $rawUrl = '{{base_url}}/'.preg_replace('/\{([^}]+)\}/', '{{$1}}', $uri);
    $query = [];
    foreach ($queryExamples[$action] ?? [] as $key => $value) {
        $query[] = ['key' => $key, 'value' => (string) $value, 'disabled' => false];
    }

    $request = [
        'method' => $method,
        'header' => [['key' => 'Accept', 'value' => 'application/json']],
        'url' => [
            'raw' => $rawUrl,
            'host' => ['{{base_url}}'],
            'path' => array_map(
                fn (string $segment) => preg_replace('/\{([^}]+)\}/', '{{$1}}', $segment),
                explode('/', $uri)
            ),
        ],
        'description' => descriptionFor($route, $action),
    ];

    if ($query !== []) {
        $request['url']['query'] = $query;
        $request['url']['raw'] .= '?'.implode('&', array_map(fn (array $item) => $item['key'].'='.$item['value'], $query));
    }

    if (in_array($action, $publicActions, true)) {
        $request['auth'] = ['type' => 'noauth'];
    }

    if (isset($multipartPayloads[$action])) {
        $request['body'] = ['mode' => 'formdata', 'formdata' => $multipartPayloads[$action]];
    } elseif (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && isset($jsonPayloads[$action])) {
        $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
        $request['body'] = [
            'mode' => 'raw',
            'raw' => json_encode($jsonPayloads[$action], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'options' => ['raw' => ['language' => 'json']],
        ];
    } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
        $request['body'] = [
            'mode' => 'raw',
            'raw' => '{}',
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    $item = ['name' => $name, 'request' => $request, 'response' => []];
    if ($action === 'AuthController@simulateLogin') {
        $item['event'] = [[
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'const body = pm.response.json();',
                    "if (body.token) { pm.collectionVariables.set('token', body.token); }",
                ],
            ],
        ]];
    }

    $folders[$folder][] = $item;
}

$variables = [
    ['key' => 'base_url', 'value' => 'http://localhost:8000', 'type' => 'string'],
    ['key' => 'token', 'value' => '', 'type' => 'string'],
];
foreach ($pathVariables as $key => $value) {
    $variables[] = ['key' => $key, 'value' => $value, 'type' => 'string'];
}

$collection = [
    'info' => [
        '_postman_id' => '1aa59e6a-c071-48fa-a4cc-projecttapi001',
        'name' => 'ProjectT API - Complete Collection',
        'description' => "Generated from Laravel's registered API routes. Includes all 163 API operations, example payloads, query parameters, route variables, multipart upload examples, and inherited Bearer authentication.\n\nSet `base_url` and `token` before use. OAuth redirect/callback and simulation endpoints override inherited authentication. Empty `{}` bodies indicate endpoints that currently accept no defined payload or whose external integration payload is not yet specified in backend validation.",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => [
        'type' => 'bearer',
        'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
    ],
    'event' => [[
        'listen' => 'prerequest',
        'script' => [
            'type' => 'text/javascript',
            'exec' => [
                "pm.request.headers.upsert({ key: 'Accept', value: 'application/json' });",
            ],
        ],
    ]],
    'variable' => $variables,
    'item' => array_map(
        fn (string $folder, array $items) => ['name' => $folder, 'item' => $items],
        array_keys($folders),
        array_values($folders)
    ),
];

$outputDirectory = $basePath.'/docs/postman';
if (! is_dir($outputDirectory)) {
    mkdir($outputDirectory, 0775, true);
}

$outputPath = $outputDirectory.'/ProjectT-API.postman_collection.json';
file_put_contents($outputPath, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

echo "Generated {$outputPath} with ".count($routes).' requests in '.count($folders).' folders.'.PHP_EOL;

function shortAction(string $action): string
{
    $parts = explode('\\', $action);

    return end($parts);
}

function folderFor(string $uri): string
{
    $parts = explode('/', $uri);
    $first = $parts[1] ?? 'Other';
    $second = $parts[2] ?? null;

    if ($first === 'admin' && $second !== null) {
        return 'Admin - '.headline($second);
    }

    return match ($first) {
        'auth' => 'Authentication',
        'banks' => 'Bank Verification',
        'notifications' => 'Notifications',
        'shareholders' => 'Shareholders',
        'sras' => 'Cautions',
        'user-activity-logs' => 'User Activity Logs',
        'sra-guardians' => 'SRA Guardians',
        'probates' => 'Probate and Estate',
        'share-positions' => 'Share Positions',
        'share-lots' => 'Share Lots',
        'share-transactions' => 'Share Transactions',
        'share-transfers' => 'Share Transfers',
        'cscs' => 'CSCS Imports',
        'offers' => 'IPO Offers',
        'roles' => 'Roles',
        'permissions' => 'Permissions',
        'user' => 'Authentication',
        default => headline($first),
    };
}

function requestName(string $method, string $uri, string $action): string
{
    [$controller, $controllerMethod] = explode('@', $action);
    $resource = preg_replace('/Controller$/', '', $controller);

    return $method.' - '.headline($resource).' - '.headline($controllerMethod);
}

function descriptionFor(array $route, string $action): string
{
    $middleware = array_values(array_filter(
        $route['middleware'],
        fn (string $item) => str_contains($item, 'PermissionMiddleware')
            || str_contains($item, 'RoleMiddleware')
            || str_contains($item, 'Authenticate')
    ));

    $description = "Backend action: `{$action}`.";
    $description .= match ($action) {
        'ShareholderController@shareholderIdentityCreate' => "\n\nThe `shareholder` URL variable identifies the owner. Do not send `shareholder_id` in the request body.",
        'ShareholderController@shareholderIdentityUpdate' => "\n\nThe `shareholder` URL variable identifies the owner and `identity` identifies the identity record. Do not send `shareholder_id` in the request body.",
        default => '',
    };
    [$controllerName, $method] = explode('@', $route['action']);
    if (! method_exists($controllerName, $method)) {
        $description = "WARNING: This route is registered, but `{$action}` is not implemented in its controller and will fail until the backend method is added.\n\n".$description;
    }
    if ($middleware !== []) {
        $description .= "\n\nAccess middleware:\n- ".implode("\n- ", $middleware);
    }

    return $description;
}

function headline(string $value): string
{
    $value = preg_replace('/([a-z])([A-Z])/', '$1 $2', $value);
    $value = str_replace(['-', '_'], ' ', (string) $value);

    return ucwords($value);
}

function defaultVariableValue(string $variable): string
{
    return match ($variable) {
        'notificationId' => '00000000-0000-0000-0000-000000000000',
        default => '1',
    };
}

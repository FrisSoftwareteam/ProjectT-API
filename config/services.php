<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        // Frontend landing pages after OAuth completes
        'frontend_redirect' => env('MICROSOFT_FRONTEND_REDIRECT', 'https://project-t.firstregistrarsapi.com'),
        'frontend_local_redirect' => env('MICROSOFT_FRONTEND_REDIRECT_LOCAL', 'http://localhost:3000'),
        // Set your Azure AD tenant to avoid the default `/common` endpoint.
        // Examples: 'contoso.onmicrosoft.com', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', or 'organizations'.
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url'   => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'nibss_pay' => [
        'auth_url'         => env('NIBSS_PAY_AUTH_URL'),
        'client_id'        => env('NIBSS_PAY_CLIENT_ID'),
        'client_secret'    => env('NIBSS_PAY_CLIENT_SECRET'),
        'api_url'          => env('NIBSS_PAY_API_URL'),
        'subscription_key' => env('NIBSS_PAY_SUBSCRIPTION_KEY'),
    ],

];
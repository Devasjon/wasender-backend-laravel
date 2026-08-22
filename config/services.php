<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    // Added for the Paybill WhatsApp bridge — see
    // app/Http/Controllers/Api/SendWhatsAppController.php
    'wasender' => [
        'key' => env('WASENDER_API_KEY'),
        'shared_secret' => env('WASENDER_SHARED_SECRET'),
    ],

    // Added for Paybill's cloud sync (/api/state) — see
    // app/Http/Middleware/EnsurePaybillAccessToken.php
    'paybill' => [
        'access_token' => env('APP_ACCESS_TOKEN'),
    ],

    // Added for Paybill's receipt/invoice scanning (/api/scan-receipt) — see
    // app/Http/Controllers/Api/ScanReceiptController.php
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
    ],

];

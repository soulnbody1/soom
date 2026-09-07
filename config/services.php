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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],

    'firebase' => [
        'fcm' => [
            'credentials' => env('FCM_CREDENTIALS_PATH') ?: storage_path('app/firebase/credentials.json'),
        ],
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'thinking_level' => env('GEMINI_THINKING_LEVEL', 'minimal'),
        'thinking_budget' => (int) env('GEMINI_THINKING_BUDGET', 0),
    ],

    'fake' => [
        'webhook_secret' => env('AUCTION_PAYMENTS_FAKE_WEBHOOK_SECRET'),
    ],

    'fake_bill' => [
        'webhook_secret' => env('AUCTION_PAYMENTS_FAKE_BILL_WEBHOOK_SECRET'),
    ],

    'ngenius' => [
        'api_key' => env('NGENIUS_API_KEY'),
        'outlet_reference' => env('NGENIUS_OUTLET_REFERENCE'),
        'base_url' => env('NGENIUS_BASE_URL', 'https://api-gateway.sandbox.ngenius-payments.com'),
        'webhook_secret' => env('NGENIUS_WEBHOOK_SECRET'),
    ],

];

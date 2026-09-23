<?php

declare(strict_types=1);

return [

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-sonnet-4-5'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_dims' => (int) env('OPENAI_EMBEDDING_DIMS', 1536),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL', 'http://localhost:6333'),
        'key' => env('QDRANT_API_KEY'),
        'collection' => env('QDRANT_COLLECTION', 'flowzapp'),
    ],

    'deepgram' => [
        'key' => env('DEEPGRAM_API_KEY'),
    ],

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

    // Billing provider is an open decision (doc 03 §12); until chosen, the portal link is a plain URL or absent.
    'billing' => ['portal_url' => env('BILLING_PORTAL_URL'), 'webhook_secret' => env('BILLING_WEBHOOK_SECRET')],
];

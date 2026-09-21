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

    // HireNote ASR / NLP service (services/, FastAPI). The browser talks to the
    // WebSocket directly; Laravel only calls the HTTP endpoints.
    'asr' => [
        'http' => env('ASR_HTTP_URL', 'http://127.0.0.1:8765'),
        'ws' => env('ASR_WS_URL', 'ws://127.0.0.1:8765/asr/stream'),
        // Send the question text as Whisper's initial_prompt (contextual biasing). Switchable for experiments.
        'use_prompt' => (bool) env('ASR_USE_PROMPT', true),
        'timeout' => (int) env('ASR_HTTP_TIMEOUT', 300),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

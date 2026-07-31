<?php

declare(strict_types=1);

return [
    'ai' => [
        'base_url' => env('AI_SERVICE_URL', 'http://ai-service:8001'),
        'token' => env('AI_SERVICE_TOKEN'),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 30),
        'retries' => (int) env('AI_SERVICE_RETRIES', 2),
    ],

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'project_id' => env('FCM_PROJECT_ID'),
        'endpoint' => env('FCM_ENDPOINT', 'https://fcm.googleapis.com/v1/projects/%s/messages:send'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'maps_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'doe' => [
        'feed_url' => env('DOE_FEED_URL'),
    ],

    'exchange_rate' => [
        'key' => env('EXCHANGE_RATE_API_KEY'),
        'base_url' => env('EXCHANGE_RATE_URL', 'https://api.exchangerate.host'),
    ],
];

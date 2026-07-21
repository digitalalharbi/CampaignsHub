<?php

declare(strict_types=1);

// CORS for the decoupled SPA. Credentials are required for Sanctum cookie auth, so the allowed
// origins must be explicit (never "*" with credentials). See ADR 0001.
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://127.0.0.1:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id', 'X-Correlation-Id'],

    'max_age' => 0,

    'supports_credentials' => true,
];

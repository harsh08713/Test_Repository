<?php

/*
|--------------------------------------------------------------------------
| Cross-origin requests (API only)
|--------------------------------------------------------------------------
| A native app does not need CORS and the web screens are same-origin, so nothing is allowed until the owner names the
| origins of a browser client (a PWA on another host): API_CORS_ORIGINS="https://app.example.com,https://m.example.com".
| Credentials (cookies) are never allowed: the API authenticates with a bearer token only.
*/

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_CORS_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Request-Id', 'X-Company-Id', 'X-App-Version'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'Idempotent-Replayed', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 600,

    'supports_credentials' => false,
];

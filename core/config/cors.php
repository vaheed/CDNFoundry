<?php

// Only configured browser origins may call the API. The API authenticates with bearer tokens and never
// with cookies across origins, so credentials are not supported and there is no wildcard.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(preg_split('/[\s,]+/', (string) env('CORS_ALLOWED_ORIGINS', '')) ?: [])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Requested-With'],
    'exposed_headers' => ['X-Request-ID', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => false,
];

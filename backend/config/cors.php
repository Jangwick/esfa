<?php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('SFA_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'Authorization', 'Idempotency-Key', 'X-SFA-Signature'],
    'exposed_headers' => ['Retry-After', 'Content-Disposition'],
    'max_age' => 600,
    'supports_credentials' => false,
];
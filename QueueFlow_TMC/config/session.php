<?php

use Illuminate\Support\Str;

return [
    'driver' => env('SESSION_DRIVER', 'file'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt' => false,
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => 'sessions',
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', Str::slug(env('APP_NAME', 'laravel'), '_').'_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE'),
    'http_only' => true,
    // Lax by default. The API itself is bearer-token (stateless), so this only
    // affects the Blade portal session; keep `lax` unless the portal is served
    // cross-site behind HTTPS, in which case set SESSION_SAME_SITE=none
    // together with SESSION_SECURE_COOKIE=true.
    'same_site' => env('SESSION_SAME_SITE', 'lax'),
    'partitioned' => false,
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    /*
     * Was `['*']` for methods, origins and headers alike. The wildcard is not
     * currently exploitable — `supports_credentials` is false and the only API
     * route authenticates with a Sanctum bearer token, not a cookie — but a
     * wildcard origin means any site can read any unauthenticated `api/*`
     * response, and it would silently become a credentialed hole the moment
     * `supports_credentials` were flipped on.
     *
     * Origins come from CORS_ALLOWED_ORIGINS (comma-separated) and fall back to
     * APP_URL. The front-end is same-origin Blade + Alpine, so nothing in this
     * application needs a cross-origin allowance; an operator integrating an
     * external client adds it explicitly.
     */
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('APP_URL', '')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];

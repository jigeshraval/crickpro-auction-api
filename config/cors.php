<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Laravel's own default (unpublished) config only matches "api/*" —
    | this API deliberately has no "api/" prefix (see routes/api.php +
    | bootstrap/app.php's apiPrefix: ''), so the default silently never
    | applied any CORS headers to v1/* at all. Confirmed via a real browser
    | client (crickpro-auction-app) during Phase B verification: the OPTIONS
    | preflight returned a bare 200 (Laravel's router auto-responding to an
    | unmatched OPTIONS with "Allow: POST", NOT real CORS authorization —
    | curl -I showed no Access-Control-Allow-Origin header at all), and the
    | browser then failed/blocked the follow-up POST.
    |
    */

    'paths' => ['v1/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

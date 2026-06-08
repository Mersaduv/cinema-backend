<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Permissive defaults for local dev and admin SPA calling the API from any origin.
    | For production with credentials, narrow allowed_origins instead of '*'.
    |
    */

    'paths' => ['api/*', 'graphql', 'sanctum/csrf-cookie', '*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86_400,

    'supports_credentials' => false,

];

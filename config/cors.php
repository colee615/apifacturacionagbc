<?php

return [

   /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

   'paths' => ['api/*', 'admin/*', 'login', 'logout'],

   'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

   'allowed_origins' => array_values(array_filter(array_map(
      'trim',
      explode(',', env('CORS_ALLOWED_ORIGINS', 'https://safe.correos.gob.bo,http://localhost:3000,http://127.0.0.1:3000'))
   ))),

   'allowed_origins_patterns' => [],

   'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'Application'],

   'exposed_headers' => ['Authorization'],

   'max_age' => 3600,

   'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
     'agetic' => [
        'base_url' => env('AGETIC_BASE_URL', 'https://sefe.demo.agetic.gob.bo'),
        'token'    => env('AGETIC_TOKEN'),
        'verify'   => env('AGETIC_SSL_VERIFY', true), // true|false
    ],
    'facturacion_api' => [
        'integration_token' => env('FACTURACION_INTEGRATION_TOKEN', env('AGETIC_TOKEN')),
        'integration_usuario_id' => env('FACTURACION_INTEGRATION_USUARIO_ID'),
    ],
    'qhantuy_checkout' => [
        'base_url' => env('QHANTUY_CHECKOUT_BASE_URL', 'https://testingcheckout.qhantuy.com/external-api/v2'),
        'check_payments_url' => env('QHANTUY_CHECK_PAYMENTS_URL', 'https://testingcheckout.qhantuy.com/external-api/check-payments'),
        'cancel_payment_url' => env('QHANTUY_CANCEL_PAYMENT_URL', 'https://testingcheckout.qhantuy.com/external-api/cancel-payment'),
        'token' => env('QHANTUY_CHECKOUT_TOKEN'),
        'appkey' => env('QHANTUY_CHECKOUT_APPKEY'),
        'profile_code' => env('QHANTUY_CHECKOUT_PROFILE_CODE'),
        'callback_url' => env('QHANTUY_CHECKOUT_CALLBACK_URL'),
        'image_method' => env('QHANTUY_CHECKOUT_IMAGE_METHOD', 'URL'),
        'currency_code' => env('QHANTUY_CHECKOUT_CURRENCY_CODE', 'BOB'),
        'connect_timeout' => env('QHANTUY_CHECKOUT_CONNECT_TIMEOUT', 15),
        'timeout' => env('QHANTUY_CHECKOUT_TIMEOUT', 45),
        'ssl_verify' => env('QHANTUY_CHECKOUT_SSL_VERIFY', true),
    ],
];

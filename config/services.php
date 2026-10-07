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

    'clubkonnect' => [
        'client_id' => env('CLUBKONNECT_CLIENT_ID'),
        'api_key' => env('CLUBKONNECT_API_KEY'),
        'base_url' => env('CLUBKONNECT_BASE_URL', 'https://www.clubkonnect.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alternative bill-payment provider
    |--------------------------------------------------------------------------
    | Failover route for vending when ClubKonnect is down, rejects a request, or
    | its float is short. Leave the key empty to run on ClubKonnect alone — the
    | provider reports itself unconfigured and is skipped.
    */
    'payvessel' => [
        'secret_key' => env('PAYVESSEL_SECRET_KEY'),
        'public_key' => env('PAYVESSEL_PUBLIC_KEY'),
        'base_url' => env('PAYVESSEL_BASE_URL', 'https://api.payvessel.com/api/v1'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),

        /*
        |------------------------------------------------------------------
        | Dedicated virtual accounts
        |------------------------------------------------------------------
        | Wema Bank and Titan-Paystack are supported. Requires a registered
        | Nigerian business that has completed Paystack's go-live process.
        */
        'dva_bank' => env('PAYSTACK_DVA_BANK', 'wema-bank'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Support / operations addresses
    |--------------------------------------------------------------------------
    | Previously hardcoded inline in controllers and views (admin notification
    | recipients appeared literally in AirtimeDataController). Keeping them in
    | config means they can be changed per environment without a code deploy.
    */
    'support' => [
        'email' => env('SUPPORT_EMAIL', 'support@reup.com.ng'),
        'ops_emails' => array_filter(explode(',', (string) env('OPS_EMAILS', 'reup.bellahoptions@gmail.com,support@reup.com.ng'))),
        'phone' => env('SUPPORT_PHONE', '+234 907 601 7916'),

        /*
        |------------------------------------------------------------------
        | WhatsApp support
        |------------------------------------------------------------------
        | `wa.me` requires the international format with no plus sign and no
        | leading zero, so a Nigerian number is stored here in full E.164 form
        | (234…, not 0…). AppServiceProvider turns this into the click-to-chat
        | URL at boot, so views never rebuild the format themselves — getting it
        | wrong there fails silently as a dead link rather than an error.
        |
        | A separate key from `phone`: this is the number customers message,
        | which is not necessarily the number printed on invoices.
        */
        'whatsapp' => env('SUPPORT_WHATSAPP', '2347074217206'),
        'whatsapp_message' => env(
            'SUPPORT_WHATSAPP_MESSAGE',
            'Hello ReUp, I need help with my account.'
        ),
    ],

    'analytics' => [
        'ga_id' => env('GOOGLE_ANALYTICS_ID'),
    ],

];

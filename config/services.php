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
    |
    | Docs: https://pairgate.com/developers/introduction. The key is sent as
    | `Authorization: Bearer …` on every call. `test_mode` prefixes each path
    | with /test, which Pairgate answers without charging the wallet — useful for
    | smoke-testing a deploy, never for production.
    |
    | Product-level identifiers (network, disco, plan) are mapped separately, in
    | config/bills.php under `pairgate`.
    */
    'pairgate' => [
        'api_key' => env('PAIRGATE_API_KEY'),
        'base_url' => env('PAIRGATE_BASE_URL', 'https://pairgate.com/api/v1'),
        'test_mode' => (bool) env('PAIRGATE_TEST_MODE', false),

        /*
         * Shown once when HMAC signing is switched on for the API key in the
         * Pairgate dashboard. POST /pairgate/webhook verifies
         * `X-Pairgate-Signature` against it and refuses to act when it is
         * empty, so this has to be set for tokens, PINs and post-acceptance
         * failures to reach the application.
         */
        'webhook_secret' => env('PAIRGATE_WEBHOOK_SECRET'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),

        /*
        |------------------------------------------------------------------
        | Transaction split
        |------------------------------------------------------------------
        | The split that every card checkout is initialised with, as a
        | `split_code` sent to POST /transaction/initialize. Paystack applies
        | the split's own percentage and subaccount at settlement, so this is
        | what routes the platform's share to the right place.
        |
        | It is sent to the gateway, not chosen by the customer, and it applies
        | to *every* Paystack checkout — which is why the default is set here
        | rather than left blank. Clearing it (PAYSTACK_SPLIT_CODE=) disables
        | splitting entirely: settlements then land wholly in the main account.
        |
        | A code that is wrong, deleted or belongs to another account does not
        | fail silently — Paystack rejects the initialisation and the customer
        | sees the funding error, which is the correct failure. See
        | `PaystackService::initialize()`.
        */
        'split_code' => env('PAYSTACK_SPLIT_CODE', 'SPL_YsS8nTY0UJ'),

        /*
        |------------------------------------------------------------------
        | Dedicated virtual accounts
        |------------------------------------------------------------------
        | Wema Bank and Titan-Paystack are supported. Requires a registered
        | Nigerian business that has completed Paystack's go-live process.
        |
        | Note: a transfer into a dedicated virtual account is settled by the
        | split configured when the account was created, and that endpoint takes
        | no per-transaction split code. So `split_code` above governs card
        | checkouts; a DVA's split is set on the Paystack account itself.
        */
        'dva_bank' => env('PAYSTACK_DVA_BANK', 'wema-bank'),
    ],

    /*
    |----------------------------------------------------------------------
    | Bachs — fallback card gateway
    |----------------------------------------------------------------------
    | Used only when Paystack refuses to start a checkout (see
    | WalletController::initiateCardPayment). It is deliberately not a
    | selectable payment method: the customer picks "Card", and the app
    | decides which gateway serves it.
    |
    | Sandbox is the default so a key pasted in without the base URL cannot
    | accidentally charge real cards, and `enabled` defaults to false so the
    | fallback stays dark until someone deliberately switches it on.
    |
    | `payment_method_type` is the corridor Bachs is told to offer. NGN_CARD
    | is the naira card rail; if that corridor is not enabled on the account,
    | Bachs refuses the checkout (CHECKOUT_RESTRICTION_LEAVES_NO_PAYMENT_METHOD
    | / ACCOUNT_NOT_ACTIVATED) and the fallback fails closed to the funding
    | form with an error, rather than showing the customer an empty page.
    | Ask support@bachs.io to confirm the corridor before enabling this.
    */
    'bachs' => [
        'enabled' => env('BACHS_ENABLED', false),
        'secret_key' => env('BACHS_SECRET_KEY'),
        'webhook_secret' => env('BACHS_WEBHOOK_SECRET'),
        'base_url' => env('BACHS_BASE_URL', 'https://sandbox-api.bachs.io'),
        'payment_method_type' => env('BACHS_PAYMENT_METHOD_TYPE', 'NGN_CARD'),
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

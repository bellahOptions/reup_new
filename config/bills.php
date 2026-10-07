<?php

/**
 * Product catalogue, provider routing and spend controls.
 *
 * These lists were hardcoded inside controllers (`CableTvController::providers`,
 * `ElectricityController::discos`) and duplicated in Blade templates. Keeping
 * them here means the picker the customer sees and the `in:` validation rule
 * that guards the request can never disagree.
 */
return [

    /* ---------------------------------------------------------------------
     | Products
     |------------------------------------------------------------------- */
    'products' => [
        'airtime' => 'Airtime',
        'data' => 'Data bundle',
        'cable_tv' => 'Cable TV subscription',
        'electricity' => 'Electricity bill',
        'waec' => 'WAEC e-PIN',
        'jamb' => 'JAMB e-PIN',
        'betting' => 'Betting wallet funding',
    ],

    /* ---------------------------------------------------------------------
     | Providers
     |--------------------------------------------------------------------
     | `provider_order` is the failover sequence. For each purchase the
     | platform walks this list, skipping providers that are unconfigured,
     | do not support the product, or whose float cannot cover the charge, and
     | uses the first one that accepts the request. Only provider-side failures
     | (float, outage, timeout) trigger the next provider; validation errors and
     | duplicate references stop immediately, because retrying those either
     | fails identically or double-charges.
     */
    'provider_order' => array_values(array_filter(
        explode(',', (string) env('BILL_PROVIDER_ORDER', 'clubkonnect,payvessel'))
    )),

    'providers' => [
        'clubkonnect' => [
            'label' => 'ClubKonnect',
            'products' => ['airtime', 'data', 'cable_tv', 'electricity', 'waec', 'jamb', 'betting'],
        ],
        'payvessel' => [
            'label' => 'Payvessel',
            'products' => ['airtime', 'data', 'cable_tv', 'electricity', 'waec', 'jamb', 'betting'],
        ],
    ],

    /** Consult provider float before charging a customer. */
    'check_provider_balance' => (bool) env('BILL_CHECK_PROVIDER_BALANCE', true),

    /** Naira held back so a provider is never spent to exactly zero. */
    'provider_headroom' => (float) env('BILL_PROVIDER_HEADROOM', 500),

    /* ---------------------------------------------------------------------
     | Mobile networks
     |--------------------------------------------------------------------
     | `code` is the upstream identifier, `logo` is served from our own domain.
     | The forms previously hotlinked logos from gstatic, wikimedia and two news
     | sites — those break when the source reorganises its CDN, and they leak the
     | customer's IP and referrer to unrelated third parties.
     */
    'networks' => [
        '01' => ['name' => 'MTN', 'logo' => 'images/networks/mtn.svg', 'colour' => '#FFCC00'],
        '02' => ['name' => 'Glo', 'logo' => 'images/networks/glo.svg', 'colour' => '#00A651'],
        '03' => ['name' => '9mobile', 'logo' => 'images/networks/9mobile.svg', 'colour' => '#006F51'],
        '04' => ['name' => 'Airtel', 'logo' => 'images/networks/airtel.svg', 'colour' => '#E40000'],
    ],

    /* ---------------------------------------------------------------------
     | Cable TV providers
     |------------------------------------------------------------------- */
    'cable_providers' => [
        'dstv' => 'DStv',
        'gotv' => 'GOtv',
        'startimes' => 'StarTimes',
        'showmax' => 'Showmax',
    ],

    /* ---------------------------------------------------------------------
     | Electricity distribution companies
     |------------------------------------------------------------------- */
    'discos' => [
        '01' => 'IKEDC — Ikeja Electric',
        '02' => 'EKEDC — Eko Electricity',
        '03' => 'AEDC — Abuja Electricity',
        '04' => 'PHED — Port Harcourt Electricity',
        '05' => 'KEDCO — Kano Electricity',
        '06' => 'IBEDC — Ibadan Electricity',
        '07' => 'EEDC — Enugu Electricity',
        '08' => 'JED — Jos Electricity',
        '09' => 'KAEDCO — Kaduna Electricity',
        '10' => 'BEDC — Benin Electricity',
        '11' => 'YEDC — Yola Electricity',
        '12' => 'APLE — Aba Power',
    ],

    /* ---------------------------------------------------------------------
     | Betting companies
     |--------------------------------------------------------------------
     | Codes match the upstream identifiers; labels are what customers see.
     | `min` is the bookmaker's own floor for a wallet top-up.
     */
    'betting_providers' => [
        'bet9ja' => ['label' => 'Bet9ja', 'min' => 100],
        'sportybet' => ['label' => 'SportyBet', 'min' => 100],
        '1xbet' => ['label' => '1xBet', 'min' => 100],
        'betking' => ['label' => 'BetKing', 'min' => 100],
        'bangbet' => ['label' => 'BangBet', 'min' => 100],
        'merrybet' => ['label' => 'MerryBet', 'min' => 100],
        'nairabet' => ['label' => 'NairaBet', 'min' => 100],
        'accessbet' => ['label' => 'AccessBet', 'min' => 100],
        'betano' => ['label' => 'Betano', 'min' => 100],
        'premierbet' => ['label' => 'PremierBet', 'min' => 100],
    ],

    /* ---------------------------------------------------------------------
     | Exam PIN pricing
     |------------------------------------------------------------------- */
    'exam_pins' => [
        'waec' => (float) env('PRICE_WAEC_PIN', 3500),

        'jamb' => [
            'utme' => [
                'label' => 'UTME',
                'price' => (float) env('PRICE_JAMB_UTME_PIN', 7700),
            ],
            'de' => [
                'label' => 'Direct Entry',
                'price' => (float) env('PRICE_JAMB_DE_PIN', 5700),
            ],
        ],
    ],

    /* ---------------------------------------------------------------------
     | Convenience fees, in naira. Zero means "no fee".
     |------------------------------------------------------------------- */
    'fees' => [
        'cable_tv' => (float) env('FEE_CABLE_TV', 0),
        'electricity' => (float) env('FEE_ELECTRICITY', 0),
        'exam_pin' => (float) env('FEE_EXAM_PIN', 0),
        'betting' => (float) env('FEE_BETTING', 0),
    ],

    /* ---------------------------------------------------------------------
     | Spend controls
     |--------------------------------------------------------------------
     | Applied before every debit. `platform_daily` bounds total exposure if
     | many accounts are compromised at once; set it to 0 to disable.
     */
    'limits' => [
        'per_transaction' => (float) env('LIMIT_PER_TRANSACTION', 200000),
        'daily_per_user' => (float) env('LIMIT_DAILY_PER_USER', 500000),
        'monthly_per_user' => (float) env('LIMIT_MONTHLY_PER_USER', 5000000),
        'platform_daily' => (float) env('LIMIT_PLATFORM_DAILY', 0),
        'per_minute' => (int) env('LIMIT_PURCHASES_PER_MINUTE', 6),
        'duplicate_window' => (int) env('LIMIT_DUPLICATE_WINDOW', 20),

        /** Service-specific single-transaction caps. */
        'per_service' => [
            'airtime' => (float) env('LIMIT_AIRTIME', 50000),
            'data' => (float) env('LIMIT_DATA', 200000),
            'cable_tv' => (float) env('LIMIT_CABLE_TV', 200000),
            'electricity' => (float) env('LIMIT_ELECTRICITY', 200000),
            'waec' => (float) env('LIMIT_WAEC', 70000),
            'jamb' => (float) env('LIMIT_JAMB', 70000),
            'betting' => (float) env('LIMIT_BETTING', 100000),
        ],
    ],

    /* ---------------------------------------------------------------------
     | Amount ranges per product (single source of truth for the forms)
     |------------------------------------------------------------------- */
    'ranges' => [
        'airtime' => ['min' => 50, 'max' => 50000],
        'data' => ['min' => 50, 'max' => 200000],
        'cable_tv' => ['min' => 100, 'max' => 200000],
        'electricity' => ['min' => 500, 'max' => 200000],
        'betting' => ['min' => 100, 'max' => 100000],
    ],
];

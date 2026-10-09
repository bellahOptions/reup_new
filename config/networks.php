<?php

/**
 * Mobile network (MNO) reference data.
 *
 * The problem this file exists to solve: the same network is called four
 * different things depending on who is speaking.
 *
 *   * a customer says "MTN";
 *   * ClubKonnect identifies it as `01`;
 *   * Pairgate identifies it as `mtn`;
 *   * a catalogue response may say `MTN` or `9MOBILE` in capitals.
 *
 * Every one of those must resolve to a single canonical entry, because the
 * network name is printed on customer receipts and a receipt that says
 * "ClubKonnect" — the upstream API, not the customer's operator — is wrong and
 * has already shipped once.
 *
 * `canonical` is the code stored on a transaction and used to key per-network
 * pricing rules. `name` is what the customer reads.
 */
return [

    /*
    | ---------------------------------------------------------------------
    | Canonical networks
    | ---------------------------------------------------------------------
    | The order here is the display order used by admin screens.
    */
    'networks' => [
        'mtn' => [
            'name' => 'MTN',
            'aliases' => ['mtn', 'mtn nigeria', 'mtnNg'],
        ],
        'airtel' => [
            'name' => 'Airtel',
            'aliases' => ['airtel', 'airtel nigeria', 'zain', 'celtel'],
        ],
        'glo' => [
            'name' => 'Glo',
            'aliases' => ['glo', 'glo mobile', 'globacom', 'globacom nigeria'],
        ],
        '9mobile' => [
            'name' => '9mobile',
            'aliases' => ['9mobile', 'etisalat', 'emts', '9 mobile'],
        ],
    ],

    /*
    | ---------------------------------------------------------------------
    | Provider-specific identifier maps
    | ---------------------------------------------------------------------
    | Keyed by the provider slug used in config/bills.php `providers`.
    | A provider that is not listed here cannot have its codes translated, and
    | its transactions store no network rather than a guessed one.
    */
    'provider_codes' => [
        'clubkonnect' => [
            '01' => 'mtn',
            '02' => 'glo',
            '03' => '9mobile',
            '04' => 'airtel',
        ],

        'pairgate' => [
            'mtn' => 'mtn',
            'glo' => 'glo',
            '9mobile' => '9mobile',
            'airtel' => 'airtel',
        ],
    ],

    /*
    | ---------------------------------------------------------------------
    | Neutral fallback
    | ---------------------------------------------------------------------
    | Shown when a network genuinely cannot be resolved. It must never be a
    | provider name — that is the defect this whole subsystem exists to
    | prevent. See the network-display tests.
    */
    'unavailable_label' => 'Network unavailable',

    /*
    | ---------------------------------------------------------------------
    | Prefix inference
    | ---------------------------------------------------------------------
    | A LAST RESORT display hint only. Nigerian mobile number portability means
    | a prefix does not reliably indicate the serving network, so a prefix guess
    | is never written to a transaction and never shown as fact. It is enabled
    | only for advisory surfaces that label it as a hint.
    */
    'allow_prefix_inference' => (bool) env('NETWORK_ALLOW_PREFIX_INFERENCE', false),

    'prefix_hints' => [
        'mtn' => ['0803', '0806', '0703', '0706', '0813', '0816', '0810', '0814', '0903', '0906', '0913', '0916'],
        'airtel' => ['0802', '0808', '0708', '0812', '0701', '0902', '0901', '0907', '0912'],
        'glo' => ['0805', '0807', '0705', '0815', '0811', '0905', '0915'],
        '9mobile' => ['0809', '0817', '0818', '0908', '0909'],
    ],
];

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
    |
    | ## `name` is the customer-facing label, `key` is the identifier
    |
    | The two are not the same thing and must not be conflated. The *key* is what
    | a transaction stores, what a pricing rule is scoped to and what a provider's
    | code resolves to; it is an identifier and changing it would orphan
    | historical rows and per-network rules. The *name* is branding, and branding
    | changes: 9mobile now trades as **T2mobile**, so the label says so while the
    | key stays `9mobile` and every existing row, rule and provider code keeps
    | resolving exactly as it did.
    |
    | ## `logo` is the brand mark
    |
    | These are the supplied assets, not the placeholder monograms that were
    | rendered before. They live in `public/` because that is where they were
    | provided; `fallback_logo` names the older vector monogram, which the
    | component falls back to if the bitmap fails to load.
    |
    | `NetworkBrand` is the only thing that reads these keys — a template that
    | reaches into this array directly is the duplication this was consolidated to
    | remove. `NetworkBrandTest` asserts every configured path exists on disk, so a
    | mark that would 404 fails the suite rather than the storefront.
    */
    'networks' => [
        'mtn' => [
            'name' => 'MTN',
            'logo' => 'mtn.jpeg',
            'fallback_logo' => 'images/networks/mtn.svg',
            'colour' => '#FFCC00',
            'aliases' => ['mtn', 'mtn nigeria', 'mtnNg'],
        ],
        'airtel' => [
            'name' => 'Airtel',
            'logo' => 'airtel.jpeg',
            'fallback_logo' => 'images/networks/airtel.svg',
            'colour' => '#E40000',
            'aliases' => ['airtel', 'airtel nigeria', 'zain', 'celtel'],
        ],
        'glo' => [
            'name' => 'Glo',
            'logo' => 'glo.png',
            'fallback_logo' => 'images/networks/glo.svg',
            'colour' => '#00A651',
            'aliases' => ['glo', 'glo mobile', 'globacom', 'globacom nigeria'],
        ],
        '9mobile' => [
            /*
             * The current brand. 9mobile was rebranded T2mobile; the canonical key
             * above deliberately stays `9mobile` so stored `network_code` values,
             * provider code maps and pricing rules continue to resolve.
             */
            'name' => 'T2mobile',
            'logo' => 't2.png',
            'fallback_logo' => 'images/networks/9mobile.svg',
            'colour' => '#006F51',
            /*
             * Every spelling that has ever referred to this network, so a
             * provider's wording, a legacy row or a customer's typing all land on
             * the same entry. `9mobile` and `t2` are both listed because both are
             * in live use — the provider still says 9MOBILE, the brand says
             * T2mobile, and a value that resolved to nothing would render as
             * "Network unavailable" on a receipt for a purchase that worked.
             */
            'aliases' => [
                '9mobile', '9 mobile', '9mobile nigeria',
                't2', 't2mobile', 't2 mobile', 't2mobile nigeria',
                'etisalat', 'emts',
            ],
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

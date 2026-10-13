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
    | Nigerian mobile number portability means a prefix does **not** determine the
    | serving network, so a prefix guess is never written to a transaction as a
    | verified fact and is never presented as certain.
    |
    | It is on by default because it is the only detection available: neither
    | integration ReUp holds publishes a number-to-network lookup, so turning this
    | off means detection is simply absent. On, it does two useful things —
    |
    |   1. the page pre-selects the likely network, which is right for the large
    |      majority of unported numbers and saves the customer a step;
    |   2. a purchase whose *selected* network contradicts the prefix is stopped
    |      before the wallet is debited instead of being sent to the wrong network.
    |
    | A ported number is the case this gets wrong, so the customer can always
    | override the guess, and an override is recorded as the customer's own
    | confirmation (`PhoneNumber::SOURCE_CUSTOMER`) rather than as a detection.
    |
    | The cost of a wrong prefix in this list is asymmetric and worth stating: a
    | prefix we do **not** list means detection returns "no candidate", the customer
    | picks, and nothing is auto-charged — which is safe. A prefix mapped to the
    | *wrong* network would mislabel a purchase, so additions here need to be
    | verified against the NCC allocation before they are made.
    */
    'allow_prefix_inference' => (bool) env('NETWORK_ALLOW_PREFIX_INFERENCE', true),

    /*
    | ---------------------------------------------------------------------
    | Prefix hints
    | ---------------------------------------------------------------------
    | The complete set of Nigerian mobile prefixes allocated to the four networks
    | ReUp sells, from the NCC national numbering plan as reproduced in
    | https://en.wikipedia.org/wiki/Telephone_numbers_in_Nigeria.
    |
    | ## Why this list has to be complete, and what happens when it is not
    |
    | A prefix that is **absent** produces "we could not tell which network this
    | number uses" and sends the customer to pick by hand. That is safe but it is a
    | bad answer to give about an ordinary MTN number, and it was the reported
    | fault: `0704`, `0707`, `0904` and `0911` are all common, and all four were
    | missing. Completeness is therefore the fix — a short list reads as a broken
    | feature even though nothing incorrect is ever selected.
    |
    | ## What is deliberately not here
    |
    |   * `0800` and `0700` — NCC toll-free and toll numbers. They are ten digits,
    |     not eleven, so `PhoneNumber` rejects them by length before this list is
    |     consulted.
    |   * `0819`, `0709`, `07027`, `07028`, `07029` — Starcomms and Multi-Links,
    |     which are not networks ReUp sells and are generally no longer operating.
    |     A customer on one of these gets "we could not tell" and chooses; there is
    |     no bundle to sell them either way.
    |   * `07020` (Smile) — a data-only operator, likewise not sold here.
    |   * `07025`, `07026` (formerly Visafone) — five-digit prefixes on the MTN
    |     network. Deliberately omitted rather than mapped by truncation: their first
    |     four digits are `0702`, which is also the start of a five-digit Smile
    |     prefix and of five-digit Multi-Links prefixes, so a four-digit match would
    |     be guessing between three operators. The customer is asked to choose, and
    |     MTN is what they will pick.
    |
    | ## The cost of a wrong entry is asymmetric
    |
    | An absent prefix costs a manual selection. A prefix mapped to the **wrong**
    | network mislabels a purchase and sends a bundle to a network the customer is
    | not on — which spends their money on nothing. Additions therefore need to be
    | checked against the numbering plan, not inferred from the shape of the number.
    */
    'prefix_hints' => [
        'mtn' => [
            '0703', '0704', '0706', '0707',
            '0803', '0806', '0810', '0813', '0814', '0816',
            '0903', '0906', '0913', '0916',
        ],
        'airtel' => [
            '0701', '0708',
            '0802', '0808', '0812',
            '0901', '0902', '0904', '0907', '0911', '0912',
        ],
        'glo' => ['0705', '0805', '0807', '0811', '0815', '0905', '0915'],
        '9mobile' => ['0809', '0817', '0818', '0908', '0909'],
    ],
];

<?php

/**
 * Provider cost assumptions and the policy for using them.
 *
 * ## Why costs are not hard-coded in a controller
 *
 * "ClubKonnect gives me 3% off airtime" is a *commercial assumption about a
 * provider*. It changes when the provider renegotiates, and it differs per
 * network. Hard-coding it into `AirtimeDataController` would mean a code deploy
 * to change a commercial term, no audit trail for the change, and no way to
 * express that MTN's discount is not Airtel's.
 *
 * So the rates live in the `provider_network_costs` table, are edited by a Super
 * Admin, and are stamped with when they were last verified. This file holds only
 * the *policy* for what to do when a cost is missing, stale or unverified.
 *
 * ## The policy
 *
 * `on_unverified_cost` decides what happens when a transaction needs a provider
 * cost and none can be established:
 *
 *   * `refuse` (default, and the only safe setting) — the sale is blocked with a
 *     customer-friendly message. Nothing is invented and no profit is claimed.
 *   * `refuse_pending_review` — as `refuse`, but the block is also flagged for
 *     an administrator, because it usually means a rate needs entering.
 *
 * There is deliberately no "assume a cost and sell anyway" option. A guessed
 * cost makes every downstream profit figure a guess, and the brief is explicit
 * that estimated profit must not be presented as realised profit.
 */
return [

    /*
    | ---------------------------------------------------------------------
    | Verification policy
    | ---------------------------------------------------------------------
    | `max_age_hours` is how long a verified cost stays usable before it must be
    | re-verified. A stale cost is treated as unknown rather than as truth — the
    | provider may have re-priced, and selling on a stale cost is how a margin
    | silently disappears.
    */
    'policy' => [
        'on_unverified_cost' => env('PROVIDER_COST_POLICY', 'refuse'),

        'max_age_hours' => (int) env('PROVIDER_COST_MAX_AGE_HOURS', 168),

        /*
         * Whether a *configured assumption* (as opposed to a provider quote or a
         * verified catalogue price) may be used to price a sale at all. Enabled
         * by default because airtime discounts are genuinely configured rather
         * than quoted, but the resulting profit is recorded as estimated.
         */
        'allow_configured_assumptions' => (bool) env('PROVIDER_COST_ALLOW_ASSUMPTIONS', true),

        /*
         * Whether a stale cost may be used when no fresher figure exists. Off by
         * default: a stale cost is a guess with a timestamp on it.
         */
        'allow_stale' => (bool) env('PROVIDER_COST_ALLOW_STALE', false),
    ],

    /*
    | ---------------------------------------------------------------------
    | Default airtime discount
    | ---------------------------------------------------------------------
    | The seed value for the "we are told ClubKonnect gives 3%" business
    | assumption. It is applied per network when a Super Admin runs the
    | `pricing:seed-airtime-costs` command, and it is NOT read directly on the
    | purchase path — the purchase path reads `provider_network_costs`.
    |
    | It lives here so the assumption is documented and reviewable, and so the
    | seeder has one source of truth. Change the rate in the admin console, not
    | here, once a real invoice has confirmed it.
    */
    'airtime_default_discount_bps' => (int) env('AIRTIME_DISCOUNT_BPS', 300),

    /*
    | Per-network overrides for the seed, in basis points. A network absent from
    | this list is seeded with the default above. The brief is explicit that the
    | 3% must not be assumed identical across networks, so overriding any that
    | differ is expected rather than exceptional.
    */
    'airtime_discount_bps_by_network' => [
        // 'mtn' => 300,
        // 'airtel' => 250,
        // 'glo' => 350,
        // '9mobile' => 300,
    ],

    /*
    | Whether an airtime sale may proceed when the provider discount for that
    | network has never been verified. On by default because the seeded figure is
    | an explicit, admin-visible assumption rather than an invention — but the
    | resulting profit is recorded as estimated and never as realised.
    */
    'verify_airtime_cost' => (bool) env('AIRTIME_VERIFY_COST', false),

    /*
    | ---------------------------------------------------------------------
    | Default data-bundle markup
    | ---------------------------------------------------------------------
    | Applied by `PricingDefaultsSeeder` as a global rule, so a fresh install can
    | sell data before anyone has configured a per-network rule. It is a starting
    | assumption for the operator to replace with real commercial data, not a
    | recommendation — which is exactly why it is a seeded *rule* they can edit
    | rather than a constant in a service class.
    */
    'data_default_rule_name' => 'Data bundles — default markup',

    'data_default_markup_bps' => (int) env('DATA_DEFAULT_MARKUP_BPS', 1000),
];

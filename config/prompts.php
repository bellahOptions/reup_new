<?php

/**
 * Dashboard prompts: the PIN reminder and the promotional modal.
 *
 * ## Why the promotions are configuration and not a database table
 *
 * The existing database home for announcements is `PromotionNotification`, and it
 * is the right place for anything an operator writes and schedules. These are not
 * those. They advertise **features that are always available** — a social boost, the
 * affiliate programme — so their copy is a fact about the product rather than a
 * campaign, and it belongs beside the code that ships the feature. A campaign
 * ("20% off data this Friday") still goes in `PromotionNotification` and still
 * renders in the marquee.
 *
 * ## Every entry names a route, and the route is checked
 *
 * A promotional tile that links nowhere is worse than no tile: the customer clicks,
 * nothing happens, and they learn that the dashboard's buttons are decorative. The
 * `route` key is therefore resolved through `route()` at render time, and a test
 * asserts every entry in this file resolves. The `route_params` key carries what a
 * route needs — the affiliate link needs the customer's own referral code.
 */
return [

    /*
    | ---------------------------------------------------------------------
    | The promotional modal
    | ---------------------------------------------------------------------
    | One of these is chosen at random and may be shown once per window. See
    | `config('prompts.promo.frequency_hours')`.
    |
    | `weight` is respected, so an operator can make one promotion more likely
    | without removing the others. A weight of zero disables an entry without
    | deleting the copy, which is what you want when a feature is temporarily
    | withdrawn.
    */
    'promotions' => [

        'social_boost' => [
            'weight' => 1,
            'icon' => 'rocket-launch',
            'accent' => 'brand',
            'eyebrow' => 'New on ReUp',
            'title' => 'Grow your social media',
            'body' => 'Buy Instagram, TikTok, X and YouTube followers, likes and views '
                . 'from your wallet — the same balance you use for airtime and data.',
            'highlights' => [
                'Any quantity, from 100 to 50,000',
                'Prices and delivery time shown before you pay',
                'Automatic refund if a provider cannot deliver',
            ],
            'cta' => 'Explore Social Boost',
            'route' => 'social-boost.index',
        ],

        'refer_earn' => [
            'weight' => 1,
            'icon' => 'users',
            'accent' => 'emerald',
            'eyebrow' => 'Earn from ReUp',
            'title' => 'Refer a friend, earn on every top-up',
            'body' => 'Share your personal link. When somebody signs up and starts '
                . 'buying, you earn a share of what ReUp makes on their transactions — '
                . 'paid into your wallet.',
            'highlights' => [
                'Your own link and referral code',
                'Track clicks, sign-ups and earnings',
                'Withdraw to your wallet at any time',
            ],
            'cta' => 'Get my referral link',
            'route' => 'affiliate.index',
        ],
    ],

    /*
    | ---------------------------------------------------------------------
    | How often the modal may appear
    | ---------------------------------------------------------------------
    | Hours between showings, per account. The state is a cookie keyed by user id,
    | so it is per-account on a shared browser and needs no migration.
    |
    | Twenty-four hours is the default because the point is to make a customer aware
    | of a feature they have not used, not to interrupt them every session until
    | they do. A promotion shown more often than it is read is a promotion that
    | teaches people to close dialogs without reading them — including the ones that
    | matter.
    */
    'promo_frequency_hours' => (int) env('PROMO_MODAL_FREQUENCY_HOURS', 24),

    /** Length of the dismissal cookie, in minutes. A year. */
    'dismissal_minutes' => 60 * 24 * 365,

    /*
    | ---------------------------------------------------------------------
    | The PIN banner
    | ---------------------------------------------------------------------
    | Shown on the dashboard for as long as the account has no transaction PIN.
    | There is no frequency to configure: this is a security prompt, not a campaign,
    | and it is not dismissed — it disappears when the PIN is set, which is the only
    | thing that should make it disappear.
    */
    'pin_banner' => [
        'title' => 'Set your transaction PIN',
        'body' => 'A 4-digit PIN authorises every purchase you make on ReUp. You do not '
            . 'have one yet, so you would be asked to set it at the point of sale.',
    ],
];

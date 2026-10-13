<?php

/**
 * Which customer-facing services are live, and which are not yet.
 *
 * ## The three states, and why "coming soon" is one of them
 *
 * A service is either **live**, **coming soon**, or **disabled**, and the difference
 * between the last two is entirely about what the customer is told:
 *
 *   * **live** — the service works and its page sells;
 *   * **coming soon** — the page exists, says so, and offers nothing. Used for a
 *     feature that is built and about to be switched on, or one whose provider is
 *     not contracted yet. The page is still worth visiting: it is where the customer
 *     learns the feature is coming, and it is what a link in an email or an
 *     advertisement should land on rather than a 404;
 *   * **disabled** — the page exists but the service is not offered, and there is
 *     nothing to announce. A 503 rather than a 404, because the route is real and
 *     the customer did not mistype it.
 *
 * A 404 for either would be wrong in the same way: it tells a customer who followed a
 * real link that they made a mistake.
 *
 * ## How the state is decided
 *
 *     REUP_SERVICES               the services that are live
 *     REUP_SERVICES_COMING_SOON   the services to announce but not sell
 *     REUP_SERVICES_DISABLED      the services to refuse outright
 *
 * **The default is that everything is live**, and that is deliberate: a deployment
 * that has never heard of these variables must behave exactly as it did before they
 * existed. A flag system whose default is "off" turns a config omission into an
 * outage, and that is the wrong way round — the failure mode of an unset variable
 * should be the previously-working behaviour, not a site with no services on it.
 *
 * Setting `REUP_SERVICES` changes that: it becomes the authoritative live list, and
 * any service not named in it and not named in the other two falls to **coming soon**.
 * That is the opt-in to strict behaviour, and it is what you want in production —
 * it means a new service added to this file cannot go live by accident.
 *
 * ## The keys are ReUp's own, and they are not route names
 *
 * They are stable identifiers, because a route name is a URL decision and a feature
 * key is a commercial one. `Route::has()` is used at boot to check each one is
 * actually wired, so a typo in `REUP_SERVICES` fails loudly rather than silently
 * leaving a service off.
 */
return [

    /*
    | ---------------------------------------------------------------------
    | Settings
    | ---------------------------------------------------------------------
    */
    'enabled' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('REUP_SERVICES', ''))
    ))),

    'coming_soon' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('REUP_SERVICES_COMING_SOON', ''))
    ))),

    'disabled' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('REUP_SERVICES_DISABLED', ''))
    ))),

    /*
    | ---------------------------------------------------------------------
    | The services
    | ---------------------------------------------------------------------
    | `route` is the landing page used to test whether the service is wired, and is
    | what a "coming soon" link points at. `label` is what the customer reads.
    |
    | `reason` is shown on a coming-soon page when there is something specific and
    | useful to say — a provider being contracted, a launch window. Left out, the
    | page falls back to the standard copy, which is better than inventing a date
    | nobody has committed to.
    |
    | `categories` maps the `service_categories` slugs whose products belong to this
    | service. It is how a switched-off service stops being purchasable: the
    | catalogue asks `ServiceGate::forProduct()`, which reads this map. A category
    | is inherited by every product inside it, so a bundle added tomorrow is gated
    | without anyone remembering to gate it.
    */
    'services' => [

        'airtime' => [
            'label' => 'Airtime',
            'route' => 'airtime-data.index',
            'categories' => ['airtime'],
        ],

        'data' => [
            'label' => 'Data bundles',
            'route' => 'airtime-data.index',
            'categories' => ['data'],
        ],

        'social_boost' => [
            'label' => 'Social Boost',
            'route' => 'social-boost.index',
            'categories' => ['social-boost'],
        ],

        'cable_tv' => [
            'label' => 'Cable TV',
            'route' => 'cable-tv.index',
            'categories' => ['cable-tv'],
        ],

        'electricity' => [
            'label' => 'Electricity',
            'route' => 'electricity.index',
            'categories' => ['electricity'],
        ],

        'waec_pin' => [
            'label' => 'WAEC result checker',
            'route' => 'waec-pin.index',
            'categories' => ['education'],
        ],

        'jamb_pin' => [
            'label' => 'JAMB e-PIN',
            'route' => 'jamb-pin.index',
            'categories' => ['education'],
        ],

        'betting' => [
            'label' => 'Betting top-up',
            'route' => 'betting.index',
        ],
    ],

    /*
    | ---------------------------------------------------------------------
    | Copy
    | ---------------------------------------------------------------------
    | Kept here rather than in the view so the wording can be reviewed beside the
    | switch that reveals it, and so a test can assert it without rendering a page.
    */
    'copy' => [
        'coming_soon_title' => 'Coming soon',
        'coming_soon_heading' => ':service is on its way',
        'coming_soon_body' => 'We are finishing this service and will switch it on here. '
            . 'Nothing is available to buy yet, and you have not been charged anything.',
        'coming_soon_cta' => 'Explore what is available now',
        'disabled_title' => 'Not available',
        'disabled_heading' => ':service is not available',
        'disabled_body' => 'This service is not offered at the moment. '
            . 'Nothing has been charged, and no order was created.',
    ],
];

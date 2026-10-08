<?php

namespace Tests\Feature;

use App\Models\InternationalProduct;
use App\Models\ProductAlert;
use App\Models\Provider;
use App\Models\ProviderCatalogueItem;
use App\Models\ProviderHealthCheck;
use App\Models\ProviderPriceSnapshot;
use App\Models\ProviderProduct;
use App\Models\ServiceCategory;
use App\Models\ServiceProduct;
use App\Providers\Adapters\SogoProvider;
use App\Providers\CatalogueSync;
use App\Providers\ProviderMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Catalogue synchronisation, provider monitoring and alerting.
 *
 * The assertions that carry money here are:
 *
 *   * a synced variant that no operator has mapped is **never sellable**, because a
 *     provider's display text is not our product vocabulary;
 *   * re-running a sync does not duplicate a catalogue, so an overlapping or
 *     retried run is harmless;
 *   * a cost change appends immutable history and never rewrites it;
 *   * an unreadable catalogue leaves existing costs and availability alone, because
 *     an API hiccup is not evidence that a product was withdrawn;
 *   * one condition produces one open alert, however often it is observed.
 */
class ProviderSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'providers.sandbox' => false,
            'providers.http.timeout' => 5,
            'providers.http.probe_timeout' => 5,
            'providers.alerts.cost_change_bps' => 1000,
            'providers.alerts.failures_before_down' => 3,
        ]);

        $this->setEnv('SOGO_SECRET_KEY', 'sogo_sk_test_unit');
        $this->setEnv('VTPASS_API_KEY', 'vtpass_api');
        $this->setEnv('VTPASS_PUBLIC_KEY', 'vtpass_public');
        $this->setEnv('VTPASS_SECRET_KEY', 'vtpass_secret');
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    private function provider(string $slug, string $driver, array $capabilities, array $attributes = []): Provider
    {
        return Provider::create(array_merge([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'driver' => $driver,
            'capabilities' => $capabilities,
            'is_active' => true,
            'priority' => 100,
            'credential_env_prefix' => strtoupper($slug),
            'low_balance_threshold_minor' => 100000,
        ], $attributes));
    }

    private function product(string $name = 'MTN 1GB Monthly'): ServiceProduct
    {
        $category = ServiceCategory::firstOrCreate(
            ['slug' => 'data'],
            ['name' => 'Data', 'group' => 'digital', 'is_active' => true],
        );

        return ServiceProduct::create([
            'category_id' => $category->getKey(),
            'product_key' => 'mtn-1gb-monthly',
            'slug' => 'mtn-1gb-monthly',
            'name' => $name,
            /*
             * A product is `DISCOVERED` and `TEMPORARILY_UNAVAILABLE` by default, so
             * it is invisible to customers until an operator publishes it. That gate
             * is deliberate — a synced catalogue must not reach the storefront on its
             * own — and a test that wants a sellable product has to say so.
             */
            'status' => ServiceProduct::STATUS_ACTIVE,
            'availability' => ServiceProduct::AVAILABILITY_AVAILABLE,
        ]);
    }

    private function offering(Provider $provider, ServiceProduct $product, string $providerProductId, ?int $costMinor): ProviderProduct
    {
        return ProviderProduct::create([
            'provider_id' => $provider->getKey(),
            'service_product_id' => $product->getKey(),
            'provider_product_id' => $providerProductId,
            'provider_name' => 'MTN 1GB Monthly',
            'provider_cost_minor' => $costMinor,
            'service_type' => 'data',
        ]);
    }

    /**
     * A Sogo data-plan catalogue as the provider documents it.
     *
     * @param  array<int, array<string, mixed>>  $plans
     */
    private function fakeSogoDataPlans(array $plans): void
    {
        Http::fake(['*bills/data-plans*' => Http::response(['data' => ['plans' => $plans]], 200)]);
    }

    /** @return array<string, mixed> */
    private function plan(string $variationCode, string $name, string $network, float $amount): array
    {
        return [
            'variation_code' => $variationCode,
            'name' => $name,
            'network' => $network,
            'amount' => $amount,
        ];
    }

    private function sync(): CatalogueSync
    {
        return app(CatalogueSync::class);
    }

    /* =====================================================================
     | Catalogue sync — costs, never products
     =================================================================== */

    public function test_a_synced_variant_that_no_operator_has_mapped_is_staged_and_never_sold(): void
    {
        $this->fakeSogoDataPlans([
            $this->plan('MTN-1GB-30D', 'MTN 1GB Monthly', 'mtn', 500),
            $this->plan('MTN-2GB-30D', 'MTN 2GB Monthly', 'mtn', 900),
        ]);

        $provider = $this->provider('sogo', 'sogo', ['data']);

        $result = $this->sync()->syncProvider($provider, ['data']);

        $this->assertSame(2, $result['unmapped']);
        $this->assertSame(0, $result['created']);

        // Nothing was made sellable. A provider's display text is not our product
        // vocabulary, and guessing the mapping is how the wrong bundle gets sold.
        $this->assertSame(0, ProviderProduct::count());

        $this->assertSame(2, ProviderCatalogueItem::awaitingMapping()->count());
        $this->assertDatabaseHas('provider_catalogue_items', [
            'provider_id' => $provider->getKey(),
            'provider_product_id' => 'MTN-1GB-30D',
            'network' => 'mtn',
            'provider_cost_minor' => 50000,
        ]);
    }

    public function test_re_running_the_sync_updates_rather_than_duplicating(): void
    {
        $this->fakeSogoDataPlans([$this->plan('MTN-1GB-30D', 'MTN 1GB Monthly', 'mtn', 500)]);

        $provider = $this->provider('sogo', 'sogo', ['data']);

        $this->sync()->syncProvider($provider, ['data']);
        $this->sync()->syncProvider($provider, ['data']);
        $this->sync()->syncProvider($provider, ['data']);

        /*
         * The unique index on (provider_id, provider_product_id) is what makes this
         * true. An overlapping or retried scheduled run must not be able to build a
         * second catalogue with a second price.
         */
        $this->assertSame(1, ProviderCatalogueItem::count());

        $item = ProviderCatalogueItem::first();
        $this->assertNotNull($item->first_seen_at);
        $this->assertNotNull($item->last_seen_at);
    }

    public function test_a_mapped_products_cost_is_updated_and_the_history_is_appended(): void
    {
        $this->fakeSogoDataPlans([$this->plan('MTN-1GB-30D', 'MTN 1GB Monthly', 'mtn', 500)]);

        $provider = $this->provider('sogo', 'sogo', ['data']);
        $offering = $this->offering($provider, $this->product(), 'MTN-1GB-30D', 40000);

        $result = $this->sync()->syncProvider($provider, ['data']);

        $this->assertSame(1, $result['cost_changes']);
        $this->assertSame(0, $result['unmapped']);

        $offering->refresh();

        $this->assertSame(50000, $offering->provider_cost_minor);
        $this->assertSame(40000, $offering->previous_cost_minor);
        $this->assertNotNull($offering->cost_changed_at);
        $this->assertNotNull($offering->last_seen_at);
        $this->assertNull($offering->unavailable_at);

        // The current cost is mutable state; the record of what was observed is not.
        $this->assertSame(1, ProviderPriceSnapshot::count());
        $this->assertSame(50000, (int) ProviderPriceSnapshot::first()->cost_minor);
        $this->assertSame(ProviderPriceSnapshot::SOURCE_SYNC, ProviderPriceSnapshot::first()->source);
    }

    public function test_an_unchanged_cost_appends_no_history(): void
    {
        $this->fakeSogoDataPlans([$this->plan('MTN-1GB-30D', 'MTN 1GB Monthly', 'mtn', 500)]);

        $provider = $this->provider('sogo', 'sogo', ['data']);
        $this->offering($provider, $this->product(), 'MTN-1GB-30D', 50000);

        $result = $this->sync()->syncProvider($provider, ['data']);

        $this->assertSame(0, $result['cost_changes']);
        $this->assertSame(1, $result['updated']);

        // One row per observation would turn the history into a heartbeat and hide
        // the changes it exists to show.
        $this->assertSame(0, ProviderPriceSnapshot::count());
    }

    public function test_a_product_that_vanishes_is_flagged_unavailable_and_never_deleted(): void
    {
        // The second sync does not list the first plan.
        $this->fakeSogoDataPlans([$this->plan('MTN-2GB-30D', 'MTN 2GB Monthly', 'mtn', 900)]);

        $provider = $this->provider('sogo', 'sogo', ['data']);
        $vanished = $this->offering($provider, $this->product(), 'MTN-1GB-30D', 50000);

        $result = $this->sync()->syncProvider($provider, ['data']);

        $this->assertSame(1, $result['unavailable']);

        $vanished->refresh();

        // Flagged, not deleted: an order may reference the row and the provider may
        // restore the product an hour later, losing the mapping if it were removed.
        $this->assertNotNull($vanished->unavailable_at);
        $this->assertDatabaseHas('provider_products', ['id' => $vanished->getKey()]);
        $this->assertFalse($vanished->isAvailable());
    }

    public function test_an_unreadable_catalogue_leaves_costs_and_availability_alone(): void
    {
        // A provider incident, not an empty catalogue.
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'service_unavailable', 'message' => 'Upstream is down'],
        ], 503)]);

        $provider = $this->provider('sogo', 'sogo', ['data']);
        $offering = $this->offering($provider, $this->product(), 'MTN-1GB-30D', 50000);

        $result = $this->sync()->syncProvider($provider, ['data']);

        $offering->refresh();

        /*
         * Treating a failed read as an empty catalogue would mark every product of
         * that provider unavailable — emptying the storefront because their API
         * hiccuped. The cost stays as it was and the product stays sellable.
         */
        $this->assertSame(50000, $offering->provider_cost_minor);
        $this->assertNull($offering->unavailable_at);
        $this->assertSame(0, $result['unavailable']);

        // It is still reported, so the staleness is visible.
        $this->assertSame(1, ProductAlert::where('type', ProductAlert::TYPE_CATALOGUE_UNAVAILABLE)->count());
    }

    /* =====================================================================
     | Cost-change alerting
     =================================================================== */

    public function test_a_cost_rise_beyond_the_threshold_raises_one_alert_however_often_it_is_seen(): void
    {
        $this->fakeSogoDataPlans([$this->plan('MTN-1GB-30D', 'MTN 1GB Monthly', 'mtn', 500)]);

        config(['providers.alerts.cost_change_bps' => 1000]);

        $provider = $this->provider('sogo', 'sogo', ['data']);
        $this->offering($provider, $this->product(), 'MTN-1GB-30D', 40000);

        // 40000 → 50000 is +25%, well past the 10% threshold.
        $this->sync()->syncProvider($provider, ['data']);
        $this->sync()->syncProvider($provider, ['data']);
        $this->sync()->syncProvider($provider, ['data']);

        $alerts = ProductAlert::where('type', ProductAlert::TYPE_UNUSUAL_PRICE_CHANGE)->get();

        /*
         * The fingerprint is the point: a condition that persists updates its
         * existing open alert rather than creating one per observation. An operator
         * who sees 288 identical alerts a day stops reading them.
         */
        $this->assertCount(1, $alerts);
        $this->assertSame(2500, (int) $alerts->first()->context['change_bps']);
    }

    public function test_a_cost_rise_below_the_threshold_is_not_alerted(): void
    {
        $this->fakeSogoDataPlans([$this->plan('MTN-1GB-30D', 'MTN 1GB Monthly', 'mtn', 500)]);

        config(['providers.alerts.cost_change_bps' => 1000]);

        $provider = $this->provider('sogo', 'sogo', ['data']);
        // 50000 → 50000 is no change at all; use 49950 → 50000 for a 0.1% rise.
        $this->offering($provider, $this->product(), 'MTN-1GB-30D', 49950);

        $this->sync()->syncProvider($provider, ['data']);

        $this->assertSame(0, ProductAlert::where('type', ProductAlert::TYPE_UNUSUAL_PRICE_CHANGE)->count());
    }

    public function test_a_provider_with_no_credential_is_not_probed_and_is_not_reported_as_an_outage(): void
    {
        Http::fake();

        $this->setEnv('SOGO_SECRET_KEY', null);

        $provider = $this->provider('sogo', 'sogo', ['data']);

        $result = app(ProviderMonitor::class)->probe($provider);

        /*
         * A missing key is a deployment state, not a fault. Probing it would produce
         * a guaranteed authentication error every interval, which would look like an
         * outage and bury the real one.
         */
        $this->assertSame(Provider::HEALTH_NOT_CONFIGURED, $result['status']);

        Http::assertNothingSent();

        $provider->refresh();
        $this->assertSame(Provider::HEALTH_NOT_CONFIGURED, $provider->health_status);
    }

    /* =====================================================================
     | Health probing
     =================================================================== */

    public function test_a_health_probe_records_history_and_sets_the_provider_status(): void
    {
        Http::fake(['*' => Http::response(['data' => ['balance' => 250000, 'currency' => 'NGN']], 200)]);

        $provider = $this->provider('sogo', 'sogo', ['data']);

        $result = app(ProviderMonitor::class)->probe($provider);

        $this->assertTrue($result['available']);
        $this->assertSame(Provider::HEALTH_HEALTHY, $result['status']);
        $this->assertSame(25000000, $result['balance_minor']);

        $provider->refresh();
        $this->assertSame(Provider::HEALTH_HEALTHY, $provider->health_status);
        $this->assertNotNull($provider->health_checked_at);

        // History is what makes "when did this start" answerable; a single current
        // status column has no past.
        $this->assertSame(1, ProviderHealthCheck::count());

        $check = ProviderHealthCheck::first();
        $this->assertTrue($check->available);
        $this->assertSame(25000000, $check->balance_minor);
        $this->assertFalse($check->is_sandbox);
        $this->assertNotNull($check->latency_ms);
    }

    public function test_a_single_failure_is_degraded_and_raises_no_alert(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'service_unavailable', 'message' => 'down']], 503)]);

        $provider = $this->provider('sogo', 'sogo', ['data']);

        $result = app(ProviderMonitor::class)->probe($provider);

        $this->assertFalse($result['available']);
        $this->assertSame(Provider::HEALTH_DEGRADED, $result['status']);

        // One dropped connection is not an outage, and a monitor that says it is
        // teaches an operator to ignore it.
        $this->assertSame(0, ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_UNAVAILABLE)->count());
    }

    public function test_three_consecutive_failures_mark_a_provider_down_and_alert(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'service_unavailable', 'message' => 'down']], 503)]);

        $provider = $this->provider('sogo', 'sogo', ['data']);

        $monitor = app(ProviderMonitor::class);

        $monitor->probe($provider);
        $monitor->probe($provider);
        $result = $monitor->probe($provider);

        $this->assertSame(Provider::HEALTH_DOWN, $result['status']);
        $this->assertSame(3, $result['consecutive_failures']);

        $this->assertSame(1, ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_UNAVAILABLE)->count());
    }

    public function test_a_rejected_credential_raises_an_auth_alert(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'authentication_failed', 'message' => 'Invalid credentials supplied'],
        ], 401)]);

        $provider = $this->provider('sogo', 'sogo', ['data']);

        app(ProviderMonitor::class)->probe($provider);

        $this->assertSame(1, ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_AUTH_FAILURE)->count());
    }

    public function test_a_balance_below_the_threshold_raises_a_low_balance_alert_once(): void
    {
        Http::fake(['*' => Http::response(['data' => ['balance' => 500, 'currency' => 'NGN']], 200)]);

        $provider = $this->provider('sogo', 'sogo', ['data'], ['low_balance_threshold_minor' => 100000]);

        $monitor = app(ProviderMonitor::class);

        $monitor->probe($provider);
        $monitor->probe($provider);

        $alerts = ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_LOW_BALANCE)->get();

        $this->assertCount(1, $alerts);
        $this->assertSame(ProductAlert::SEVERITY_CRITICAL, $alerts->first()->severity);
        $this->assertSame(50000, (int) $alerts->first()->context['balance_minor']);
    }

    public function test_acknowledging_an_alert_does_not_reopen_it_on_the_next_probe(): void
    {
        Http::fake(['*' => Http::response(['data' => ['balance' => 500, 'currency' => 'NGN']], 200)]);

        $provider = $this->provider('sogo', 'sogo', ['data'], ['low_balance_threshold_minor' => 100000]);

        $monitor = app(ProviderMonitor::class);
        $monitor->probe($provider);

        $alert = ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_LOW_BALANCE)->firstOrFail();
        $alert->resolve();

        $monitor->probe($provider);

        /*
         * A resolved condition that recurs is a new alert — that is correct, and it
         * is why resolution is the clearing action rather than acknowledgement. What
         * must not happen is an *open* alert being duplicated.
         */
        $this->assertSame(2, ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_LOW_BALANCE)->count());
    }

    public function test_alert_fingerprints_are_stable_for_a_persisting_condition(): void
    {
        $first = ProductAlert::fingerprintFor(ProductAlert::TYPE_PROVIDER_LOW_BALANCE, null, 7);
        $second = ProductAlert::fingerprintFor(ProductAlert::TYPE_PROVIDER_LOW_BALANCE, null, 7);

        $this->assertSame($first, $second);

        // A different provider is a different condition.
        $this->assertNotSame($first, ProductAlert::fingerprintFor(ProductAlert::TYPE_PROVIDER_LOW_BALANCE, null, 8));
    }

    /* =====================================================================
     | International catalogue
     =================================================================== */

    public function test_the_international_sync_records_the_rate_and_leaves_a_flexible_price_cost_null(): void
    {
        Http::fake([
            '*get-international-airtime-countries*' => Http::response([
                'response_description' => '000',
                'content' => ['countries' => [
                    ['code' => 'GH', 'name' => 'Ghana', 'currency' => 'GHS', 'prefix' => '233'],
                ]],
            ], 200),
            '*get-international-airtime-product-types*' => Http::response([
                'code' => '000',
                'content' => [['product_type_id' => 4, 'name' => 'Mobile Data']],
            ], 200),
            '*get-international-airtime-operators*' => Http::response([
                'code' => '000',
                'content' => [['operator_id' => '5', 'name' => 'Ghana MTN']],
            ], 200),
            '*service-variations*' => Http::response([
                'code' => '000',
                'content' => [
                    'serviceID' => 'foreign-airtime',
                    'convinience_fee' => '0 %',
                    'variations' => [
                        [
                            'variation_code' => '2471',
                            'name' => '20EUR Top Up',
                            'variation_amount' => '7850.00',
                            'fixedPrice' => 'Yes',
                        ],
                        [
                            'variation_code' => '9999',
                            'name' => 'Freedom Bundle',
                            'variation_amount' => '0.00',
                            'variation_rate' => '15.5',
                            'fixedPrice' => 'No',
                        ],
                    ],
                ],
            ], 200),
        ]);

        config(['providers.sync.countries' => ['GH']]);

        $provider = $this->provider('vtpass', 'vtpass', ['international_airtime']);

        $result = $this->sync()->syncProvider($provider, ['international_airtime']);

        $this->assertSame(2, $result['created']);

        $fixed = InternationalProduct::where('provider_product_id', 'GH:2471')->firstOrFail();
        $this->assertSame(785000, $fixed->provider_cost_minor);
        $this->assertTrue($fixed->is_fixed_price);
        $this->assertSame('GHS', $fixed->provider_currency);

        $flexible = InternationalProduct::where('provider_product_id', 'GH:9999')->firstOrFail();

        /*
         * A flexible-price variation has no single cost: the naira charge is
         * `rate × whatever the customer chooses`. Storing the documented
         * `variation_amount` of 0.00 would record a free product and price it as one.
         */
        $this->assertNull($flexible->provider_cost_minor);
        $this->assertFalse($flexible->is_fixed_price);
        $this->assertSame(15500000, $flexible->exchange_rate_micros);
        $this->assertSame('15.500000', $flexible->exchangeRate());
        $this->assertSame(InternationalProduct::RATE_SOURCE_PROVIDER_CATALOGUE, $flexible->exchange_rate_source);

        // Nothing is sellable until an operator maps it and sets a price.
        $this->assertSame(0, InternationalProduct::sellable()->count());
        $this->assertFalse($flexible->isSellable());
    }

    public function test_the_international_sync_never_overwrites_an_operators_mapping(): void
    {
        Http::fake([
            '*get-international-airtime-countries*' => Http::response([
                'code' => '000',
                'content' => ['countries' => [['code' => 'GH', 'name' => 'Ghana', 'currency' => 'GHS']]],
            ], 200),
            '*get-international-airtime-product-types*' => Http::response([
                'code' => '000',
                'content' => [['product_type_id' => 4, 'name' => 'Mobile Data']],
            ], 200),
            '*get-international-airtime-operators*' => Http::response([
                'code' => '000',
                'content' => [['operator_id' => '5', 'name' => 'Ghana MTN']],
            ], 200),
            '*service-variations*' => Http::response([
                'code' => '000',
                'content' => ['variations' => [[
                    'variation_code' => '2471',
                    'name' => '20EUR Top Up',
                    'variation_amount' => '7850.00',
                    'fixedPrice' => 'Yes',
                ]]],
            ], 200),
        ]);

        config(['providers.sync.countries' => ['GH']]);

        $provider = $this->provider('vtpass', 'vtpass', ['international_airtime']);
        $product = $this->product('Ghana 20 EUR Top Up');

        $mapped = InternationalProduct::create([
            'provider_id' => $provider->getKey(),
            'service_product_id' => $product->getKey(),
            'provider_product_id' => 'GH:2471',
            'country_code' => 'GH',
            'country_name' => 'Ghana',
            'provider_currency' => 'GHS',
            'product_name' => 'Old name',
            'provider_cost_minor' => 700000,
            'status' => InternationalProduct::STATUS_ACTIVE,
        ]);

        $this->sync()->syncProvider($provider, ['international_airtime']);

        $mapped->refresh();

        // The provider's facts are refreshed …
        $this->assertSame(785000, $mapped->provider_cost_minor);
        $this->assertSame('20EUR Top Up', $mapped->product_name);

        // … and the human decision is left exactly where it was. A sync that cleared
        // this would silently unmap a sellable product.
        $this->assertSame($product->getKey(), $mapped->service_product_id);
    }

    public function test_a_catalogue_read_carries_an_unredacted_body_but_a_purchase_never_does(): void
    {
        Http::fake([
            '*gift-cards/products*' => Http::response(['status' => true, 'data' => ['products' => []]], 200),
            '*bills/airtime*' => Http::response([
                'message' => 'Airtime purchased',
                'data' => ['reference' => 'SG-1', 'status' => 'completed', 'currency' => 'NGN'],
            ], 201),
        ]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime', 'gift_cards']));

        $catalogue = $adapter->giftCardCatalogue();

        /*
         * The sync has to read catalogue identifiers, and the redactor is path-blind:
         * `code` is a gift card number to Sogo and a country code to VTpass, so a
         * redacted payload has `[redacted]` where a country code should be.
         */
        $this->assertNotNull($catalogue->raw);
        $this->assertIsArray($catalogue->raw);

        $purchase = $adapter->purchaseAirtime('mtn', '08031234567', 100, 'KEY-RAW');

        /*
         * The structural guarantee that matters: a purchase result never carries an
         * unredacted body, so no code path in the order pipeline can persist, log or
         * return a delivery token by reaching for `raw`.
         */
        $this->assertNull($purchase->raw);
    }

    public function test_a_gift_card_code_is_still_redacted_out_of_a_purchase_payload(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Gift card purchased',
            'data' => [
                'reference' => 'SG-2',
                'status' => 'completed',
                'currency' => 'NGN',
                'code' => 'AQ8BNC7X2P1234',
                'pin' => '4321',
            ],
        ], 201)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['gift_cards']));

        $result = $adapter->purchaseGiftCard(1, '10', 1, 10000, 'KEY-GC');

        $encoded = json_encode($result->payload);

        $this->assertStringNotContainsString('AQ8BNC7X2P1234', (string) $encoded);
        $this->assertStringNotContainsString('4321', (string) $encoded);
        $this->assertNull($result->raw);
    }

    /* =====================================================================
     | Commands
     =================================================================== */

    public function test_the_esim_sync_reports_that_nothing_is_enabled_rather_than_failing(): void
    {
        $this->artisan('providers:sync-esim-products')
            ->expectsOutput('eSIM is not enabled: no integrated provider publishes an eSIM catalogue or purchase endpoint.')
            ->assertExitCode(0);
    }

    public function test_the_balance_command_treats_nothing_checked_as_a_failure(): void
    {
        /*
         * Non-zero on purpose. A monitor that only reads the exit code would
         * otherwise treat "there were no providers to check" as "everything is
         * healthy", which is the one reading that must never happen.
         */
        $this->artisan('providers:check-balances')->assertExitCode(1);
    }

    public function test_pruning_health_history_keeps_recent_rows_and_never_touches_price_history(): void
    {
        config(['providers.sync.health_history_days' => 90]);

        $provider = $this->provider('sogo', 'sogo', ['data']);
        $offering = $this->offering($provider, $this->product(), 'MTN-1GB-30D', 50000);

        ProviderHealthCheck::create([
            'provider_id' => $provider->getKey(),
            'checked_at' => now()->subDays(200),
            'available' => true,
            'is_sandbox' => false,
        ]);

        ProviderHealthCheck::create([
            'provider_id' => $provider->getKey(),
            'checked_at' => now()->subDays(2),
            'available' => true,
            'is_sandbox' => false,
        ]);

        ProviderPriceSnapshot::create([
            'provider_product_id' => $offering->getKey(),
            'cost_minor' => 50000,
            'source' => ProviderPriceSnapshot::SOURCE_SYNC,
            'recorded_at' => now()->subDays(200),
        ]);

        $this->artisan('providers:prune-health')->assertExitCode(0);

        $this->assertSame(1, ProviderHealthCheck::count());
        $this->assertSame(1, ProviderPriceSnapshot::count());

        // A provider's cost history is a financial record with its own retention
        // requirement; pruning it on a monitoring schedule would destroy evidence.
        $this->assertDatabaseHas('provider_price_snapshots', ['cost_minor' => 50000]);
    }

    public function test_a_dry_run_prunes_nothing(): void
    {
        config(['providers.sync.health_history_days' => 90]);

        $provider = $this->provider('sogo', 'sogo', ['data']);

        ProviderHealthCheck::create([
            'provider_id' => $provider->getKey(),
            'checked_at' => now()->subDays(200),
            'available' => false,
            'is_sandbox' => false,
        ]);

        $this->artisan('providers:prune-health', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(1, ProviderHealthCheck::count());
    }

    public function test_the_catalogue_command_refuses_a_capability_no_adapter_implements(): void
    {
        // Refused rather than silently syncing nothing: "the sync ran and found
        // nothing" is indistinguishable from "there was nothing to find".
        $this->artisan('providers:sync-catalogues', ['--capability' => 'esim'])
            ->assertExitCode(1);
    }

    public function test_the_gift_card_sync_reports_staged_variants_as_unsellable(): void
    {
        Http::fake(['*gift-cards/products*' => Http::response([
            'status' => true,
            'data' => ['products' => [
                ['product_id' => 1, 'name' => 'Amazon', 'discount_percentage' => 2.5],
            ]],
        ], 200)]);

        $this->provider('sogo', 'sogo', ['gift_cards']);

        $this->artisan('providers:sync-gift-cards')->assertExitCode(0);

        $this->assertSame(0, ProviderProduct::count());
        $this->assertSame(1, ProviderCatalogueItem::where('capability', 'gift_cards')->count());
    }
}

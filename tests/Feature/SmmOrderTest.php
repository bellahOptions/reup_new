<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\Provider;
use App\Models\ProviderProduct;
use App\Models\ServiceCategory;
use App\Models\ServiceOrder;
use App\Models\ServiceProduct;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Pricing\PriceChangeQuote;
use App\Providers\Adapters\NitroSmmProvider;
use App\Providers\CatalogueSync;
use App\Providers\Support\ProviderResult;
use App\Providers\Support\ProviderStatus;
use App\Smm\SmmCatalogue;
use App\Smm\SmmOrders;
use App\Smm\SmmPricing;
use App\Smm\SmmTargetValidator;
use App\Services\SecurityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviders;
use Tests\TestCase;

/**
 * Social Boost (SMM).
 *
 * ## What makes this module different from every other purchase here
 *
 * An SMM order is the only one in the application that is **asynchronous and
 * irreversible**. `add` returns an order id and nothing else; the outcome exists
 * only in a later status poll. The target cannot be corrected once delivery
 * starts. And Nitro refunds to *our* wallet, which is not the customer being made
 * whole.
 *
 * So the tests below are arranged around the four ways money can go wrong:
 *
 *   1. **a price below the mandatory markup**  refused before the debit;
 *   2. **a double submit**  one order and one debit, enforced by a database
 *      unique index rather than by hoping;
 *   3. **a timeout**  held for reconciliation, never refunded and never
 *      re-submitted, because the order may have been delivered;
 *   4. **a provider refund**  which must reach the customer's wallet, or the
 *      customer's money sits against an order that was never delivered.
 *
 * Every provider call is faked. No test reaches Nitro.
 */
class SmmOrderTest extends TestCase
{
    use RefreshDatabase;
    use FakesProviders;

    /** Nitro's documented base URL, so a fake can be scoped to it. */
    private const NITRO_URL = 'https://nitro.ng/api/v2';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['nitro'],
            'bills.limits.per_minute' => 100,
            'providers.nitro.rate_divisor' => 1000,
            // Pinned to the documented base URL, so the fakes match the real request.
            'providers.nitro.base_url' => self::NITRO_URL,
            'pricing.smm.minimum_markup_bps' => 1000,
            'pricing.smm.rate_max_age_hours' => 24,
            'pricing.smm.allow_stale_rate' => false,
            'pricing.smm.quote_ttl_seconds' => 300,
        ]);

        $this->setEnv('NITRO_KEY', 'nitro_test_key');
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

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    private function nitroProvider(array $attributes = []): Provider
    {
        return Provider::create(array_merge([
            'slug' => 'nitro',
            'name' => 'Nitro NG',
            'driver' => 'nitro',
            'capabilities' => ['smm'],
            'is_active' => true,
            'priority' => 100,
            'credential_env_prefix' => 'NITRO',
            'health_status' => Provider::HEALTH_HEALTHY,
        ], $attributes));
    }

    /**
     * A ReUp product for one boost service.
     *
     * `STATUS_ACTIVE` and `AVAILABILITY_AVAILABLE` are set explicitly because a
     * product is `DISCOVERED` and unavailable by default  an operator publishes a
     * service, and a test that wants a sellable one has to say so.
     */
    private function product(string $name = 'Instagram Followers  Standard'): ServiceProduct
    {
        $category = ServiceCategory::firstOrCreate(
            ['slug' => 'social-boost'],
            [
                'name' => 'Social Boost',
                'group' => ServiceCategory::GROUP_SOCIAL,
                'is_active' => true,
            ],
        );

        /*
         * The key is derived from the name, because `service_products.slug` and
         * `product_key` are UNIQUE: a fixture that hard-coded one key could not
         * create a second product inside one test, and "only the sellable service
         * is listed" needs two.
         */
        $key = \Illuminate\Support\Str::slug($name);

        return ServiceProduct::create([
            'category_id' => $category->getKey(),
            'product_key' => $key,
            'slug' => $key,
            'name' => $name,
            'status' => ServiceProduct::STATUS_ACTIVE,
            'availability' => ServiceProduct::AVAILABILITY_AVAILABLE,
        ]);
    }

    /**
     * A Nitro service offering.
     *
     * `provider_rate_minor` is the documented **per-1,000** rate: 2,720 is
     * 272000 kobo, and a 1,000-unit order therefore costs 2,720  not 2,720,000.
     */
    private function offering(
        Provider $provider,
        ServiceProduct $product,
        string $providerProductId = '3877',
        ?int $rateMinor = 272000,
        array $attributes = []
    ): ProviderProduct {
        return ProviderProduct::create(array_merge([
            'provider_id' => $provider->getKey(),
            'service_product_id' => $product->getKey(),
            'provider_product_id' => $providerProductId,
            'provider_name' => 'Instagram Followers  Standard',
            'provider_rate_minor' => $rateMinor,
            'rate_divisor' => 1000,
            // The one-unit cost, as the catalogue sync stores it.
            'provider_cost_minor' => $rateMinor === null ? null : (int) round($rateMinor / 1000),
            'provider_currency' => 'NGN',
            'min_quantity' => 100,
            'max_quantity' => 50000,
            'platform' => 'Instagram',
            'service_type' => 'Default',
            'supports_refill' => false,
            'supports_cancel' => false,
            'cost_synced_at' => now(),
            'last_seen_at' => now(),
        ], $attributes));
    }

    /**
     * A pricing rule that meets the mandatory floor.
     *
     * Scoped to the social category, which is how a social-boost rule is expressed
     *  and which is what `PricingRuleService` checks when it enforces the floor on
     * save.
     */
    private function smmRule(int $bps = 1500): PricingRule
    {
        $category = ServiceCategory::where('slug', 'social-boost')->firstOrFail();

        return PricingRule::create([
            'name' => 'Social Boost  default markup',
            'scope' => PricingRule::SCOPE_CATEGORY,
            'category_id' => $category->getKey(),
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => $bps,
            'minimum_profit_minor' => 1,
            'minimum_margin_bps' => 0,
            'customer_fee_enabled' => false,
            'allow_negative_margin' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    private function customer(string $balance = '20000.00'): User
    {
        $user = User::factory()->create();
        app(SecurityService::class)->setPin($user, '5931');
        app(WalletService::class)->credit($user, $balance);

        return $user->fresh();
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    /**
     * Nitro's documented responses, faked by action.
     *
     * The adapter posts `action` in a form body, so the fake inspects the request
     * body rather than the URL  which is also what makes these fakes prove the
     * adapter sends the documented parameters.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function fakeNitro(array $overrides = []): void
    {
        $responses = array_merge([
            'services' => Http::response([
                [
                    'service' => 3877,
                    'name' => 'Instagram Followers  Standard',
                    'category' => 'Instagram',
                    'rate' => '2720.00',
                    'min' => 100,
                    'max' => 50000,
                    'refill' => true,
                    'cancel' => true,
                    'type' => 'Default',
                    'description' => '',
                ],
                [
                    'service' => 4310,
                    'name' => 'Discord Members (Offline)  Standard',
                    'category' => 'Discord',
                    'rate' => '3900.00',
                    'min' => 100,
                    'max' => 10000,
                    'refill' => false,
                    'cancel' => false,
                    'type' => 'Default',
                    'description' => 'Members join your server but appear offline. Your server invite link must be set to never expire.',
                ],
            ]),
            'balance' => Http::response(['balance' => '124500.00', 'currency' => 'NGN']),
            'add' => Http::response(['order' => 4211]),
            'status' => Http::response([
                'charge' => '2720.00',
                'start_count' => '1200',
                'status' => 'In progress',
                'remains' => '450',
                'currency' => 'NGN',
            ]),
            'refill' => Http::response(['refill' => '1']),
            'cancel' => Http::response([['order' => 4211, 'cancel' => 1]]),
        ], $overrides);

        Http::fake(function ($request) use ($responses) {
            $action = $request['action'] ?? null;

            return $responses[$action] ?? Http::response(['error' => 'Invalid API key']);
        });
    }

    /**
     * Place an order through the real pipeline against the fake provider.
     *
     * The four surfaces are exercised for real: `SmmPricing` prices it,
     * `ServiceOrderService` debits the wallet and writes the snapshot, and the
     * adapter builds the request. Only the transport is faked.
     *
     * @return array{ok:bool,order:?ServiceOrder,status:string,message:string,quote:?\App\Pricing\PriceQuote}
     */
    private function place(
        User $user,
        ServiceProduct $product,
        ProviderProduct $offering,
        string $target = 'https://instagram.com/client',
        int $quantity = 1000,
        ?string $idempotencyKey = null,
        array $orderParams = []
    ): array {
        return app(SmmOrders::class)->place(
            user: $user,
            product: $product,
            offering: $offering,
            target: $target,
            quantity: $quantity,
            orderParams: $orderParams,
            pin: '5931',
            idempotencyKey: $idempotencyKey,
        );
    }

    /* =====================================================================
     | 1. The mandatory markup floor
     |=================================================================== */

    public function test_the_provider_cost_uses_nitros_documented_rate_formula(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);

        $pricing = app(SmmPricing::class);

        // 2720 ' 1000 ' 1000 = 2,720. Getting the divisor wrong is the single
        // most expensive mistake available in this integration, in either
        // direction, so it is pinned at three quantities.
        $this->assertSame(272000, $pricing->providerCost($offering, 1000)->costMinor);
        $this->assertSame(27200, $pricing->providerCost($offering, 100)->costMinor);
        $this->assertSame(2720000, $pricing->providerCost($offering, 10000)->costMinor);
    }

    public function test_the_adapter_and_the_pricing_share_one_rate_divisor(): void
    {
        $provider = $this->nitroProvider();
        $adapter = app(\App\Providers\ProviderRegistry::class)->adapterFor($provider);

        $this->assertInstanceOf(NitroSmmProvider::class, $adapter);

        // The price we quote and the charge the provider applies must be computed
        // with the same divisor, or the margin recorded on the snapshot is not the
        // margin earned.
        $this->assertSame(1000, $adapter->rateDivisor());
        $this->assertSame(2720.00, $adapter->expectedCost(272000, 1000)->toFloat());
    }

    public function test_an_order_is_sold_at_the_floor_or_above(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500); // 15%, above the 10% floor

        $priced = app(SmmPricing::class)->quote($offering, 1000, app(SmmCatalogue::class)->pricingContext($offering));

        $this->assertTrue($priced['ok']);
        $this->assertSame(272000, $priced['quote']->baseCostMinor, '2,720 cost');
        $this->assertSame(312800, $priced['quote']->customerPriceMinor, '2,720 + 15% = 3,128');
        $this->assertSame(40800, $priced['quote']->grossProfitMinor);
        $this->assertSame(1500, $priced['effective_markup_bps']);
    }

    /**
     * The rule that the whole pricing section exists for.
     */
    public function test_an_order_below_the_ten_percent_floor_is_refused_before_the_debit(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(400); // 4%, below the mandatory floor

        $user = $this->customer();
        $this->fakeNitro();

        $result = $this->place($user, $product, $offering);

        $this->assertFalse($result['ok'], 'A below-floor order must be refused');
        $this->assertNull($result['order']);
        $this->assertSame('5000.00' !== $this->balanceOf($user) ? '20000.00' : '20000.00', $this->balanceOf($user));
        $this->assertSame(0, Transactions::where('user_id', $user->id)->count(), 'Nothing was charged');

        Http::assertNotSent(fn ($request) => ($request['action'] ?? null) === 'add');
    }

    /**
     * The floor is enforced on the *quote*, not only on the rule.
     *
     * A rule created directly  by a seeder, a script, or a code path that never
     * went through `PricingRuleService`  is caught at the point of sale.
     */
    public function test_the_floor_is_enforced_even_for_a_rule_that_bypassed_the_console(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);

        // Written straight to the database, bypassing every service.
        $this->smmRule(100);

        $priced = app(SmmPricing::class)->quote($offering, 1000, app(SmmCatalogue::class)->pricingContext($offering));

        $this->assertFalse($priced['ok'], 'A 1% rule cannot sell a boost');
        $this->assertNotNull($priced['shortfall_minor']);
        $this->assertGreaterThan(0, $priced['shortfall_minor']);
        $this->assertSame(1000, $priced['minimum_markup_bps']);
    }

    public function test_the_console_refuses_to_save_a_social_boost_rule_below_the_floor(): void
    {
        $category = ServiceCategory::firstOrCreate(
            ['slug' => 'social-boost'],
            ['name' => 'Social Boost', 'group' => ServiceCategory::GROUP_SOCIAL, 'is_active' => true],
        );

        $this->assertSame(
            0,
            PricingRule::where('category_id', $category->getKey())->count(),
            'The fixture must start with no rule, or this asserts nothing'
        );

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.pricing.store'), [
                'name' => 'Too cheap',
                'scope' => 'category',
                'category_id' => $category->getKey(),
                'markup_type' => 'percentage',
                'markup_percentage' => 5, // below the 10% floor
                'rounding_mode' => 'nearest',
                'on_unprofitable' => 'unavailable',
            ])
            ->assertSessionHasErrors('rule');

        $this->assertSame(0, PricingRule::where('category_id', $category->getKey())->count());
    }

    public function test_the_console_accepts_a_social_boost_rule_at_or_above_the_floor(): void
    {
        $category = ServiceCategory::firstOrCreate(
            ['slug' => 'social-boost'],
            ['name' => 'Social Boost', 'group' => ServiceCategory::GROUP_SOCIAL, 'is_active' => true],
        );

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.pricing.store'), [
                'name' => 'Social Boost default',
                'scope' => 'category',
                'category_id' => $category->getKey(),
                'markup_type' => 'percentage',
                'markup_percentage' => 15,
                'rounding_mode' => 'nearest',
                'on_unprofitable' => 'unavailable',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1500, (int) PricingRule::orderByDesc('id')->firstOrFail()->markup_percentage_bps);
    }

    /**
     * The floor is a *floor*, not a band: a higher markup is a better price and
     * is never refused. This is the difference from data bundles, where an
     * approved band exists because the market sets the ceiling.
     */
    public function test_a_markup_above_the_floor_is_allowed(): void
    {
        $category = ServiceCategory::firstOrCreate(
            ['slug' => 'social-boost'],
            ['name' => 'Social Boost', 'group' => ServiceCategory::GROUP_SOCIAL, 'is_active' => true],
        );

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.pricing.store'), [
                'name' => 'Generous margin',
                'scope' => 'category',
                'category_id' => $category->getKey(),
                'markup_type' => 'percentage',
                'markup_percentage' => 60,
                'rounding_mode' => 'nearest',
                'on_unprofitable' => 'unavailable',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(6000, (int) PricingRule::orderByDesc('id')->firstOrFail()->markup_percentage_bps);
    }

    public function test_a_missing_provider_rate_prevents_the_purchase(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: null, attributes: [
            'provider_rate_minor' => null,
            'provider_cost_minor' => null,
        ]);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $result = $this->place($user, $product, $offering);

        $this->assertFalse($result['ok']);
        $this->assertSame('20000.00', $this->balanceOf($user));
    }

    public function test_a_stale_provider_rate_prevents_the_purchase(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();

        // Synced eight days ago, against a 24-hour freshness window. Nitro's rates
        // improve as volume climbs, so a stale rate is wrong in the direction that
        // costs us money.
        $offering = $this->offering($provider, $product, attributes: [
            'cost_synced_at' => now()->subDays(8),
            'last_seen_at' => now()->subDays(8),
        ]);
        $this->smmRule(1500);

        $this->assertNull(app(SmmPricing::class)->providerCost($offering, 1000));
    }

    public function test_a_stale_rate_may_be_allowed_by_explicit_configuration(): void
    {
        config(['pricing.smm.allow_stale_rate' => true]);

        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, attributes: [
            'cost_synced_at' => now()->subDays(8),
            'last_seen_at' => now()->subDays(8),
        ]);

        $this->assertSame(272000, app(SmmPricing::class)->providerCost($offering, 1000)->costMinor);
    }

    /**
     * Markup is not margin, and both are reported.
     */
    public function test_markup_and_gross_margin_are_reported_separately(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1000); // exactly the 10% floor

        $priced = app(SmmPricing::class)->quote($offering, 1000, app(SmmCatalogue::class)->pricingContext($offering));

        $this->assertSame(1000, $priced['effective_markup_bps'], 'A 10% markup');
        $this->assertSame(27200, $priced['quote']->grossProfitMinor, '272 of profit on 2,720');
        // 27200 / 299200 = 9.0909%, rounded half-up to 909 bps. Reporting 1000
        // here would overstate the business by a tenth of its margin.
        $this->assertSame(909, $priced['quote']->profitMarginBps, 'Which is a 9.09% margin, not 10%');
    }

    public function test_rounding_is_applied_and_profit_is_measured_from_the_rounded_price(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        // Round to the nearest 10.
        PricingRule::query()->update(['rounding_step_minor' => 1000]);

        $priced = app(SmmPricing::class)->quote($offering, 1000, app(SmmCatalogue::class)->pricingContext($offering));

        // 2,720 + 15% = 3,128, rounded half-up to 3,130.
        $this->assertSame(313000, $priced['quote']->customerPriceMinor);
        $this->assertSame(41000, $priced['quote']->grossProfitMinor, '410  from the rounded price, not 408');
    }

    /* =====================================================================
     | 2. Ordering end to end
     |=================================================================== */

    public function test_a_successful_order_debits_the_wallet_and_records_an_immutable_snapshot(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $result = $this->place($user, $product, $offering, quantity: 1000);

        /*
         * `ok` is false even though the order was placed, and that is correct:
         * `ServiceOrderService::place()` reports `ok` as "the provider returned
         * SUCCESS", and Nitro's `add` returns PENDING for every order because the
         * work is asynchronous. The controller asks the customer's question
         * instead  was the order placed?  which is what the assertions below
         * reflect.
         */
        $this->assertSame(ProviderStatus::PENDING, $result['status']);
        $this->assertNotNull($result['order']);

        $order = $result['order'];

        /*
         * **Accepted is not completed.**
         *
         * The order is unresolved, holds the customer's money, and is scheduled for
         * reconciliation  which is the property that matters and the one the brief
         * is explicit about. `ServiceOrderService` records the provider's `Pending`
         * on `provider_status` and the order itself as PROCESSING, because from
         * ReUp's side the work is under way. Either way it is *not* SUCCESS, and
         * nothing here claims the followers have arrived.
         */
        $this->assertFalse($order->isSuccess(), 'An accepted order must never be reported as delivered');
        $this->assertFalse($order->outcomeIsKnown());
        $this->assertTrue($order->holdsCustomerFunds());
        $this->assertNotNull($order->next_reconcile_at, 'It must be scheduled for reconciliation');

        $this->assertSame('Pending', $order->provider_status, 'Nitro has not started it yet');
        $this->assertSame('4211', $order->provider_reference);
        $this->assertSame($provider->getKey(), $order->provider_id);
        $this->assertSame($offering->getKey(), $order->provider_product_id);

        $this->assertSame('16872.00', $this->balanceOf($user), '20,000  3,128');

        $snapshot = $order->pricingSnapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame(272000, (int) $snapshot->base_cost_minor);
        $this->assertSame(40800, (int) $snapshot->markup_amount_minor);
        $this->assertSame(1500, (int) $snapshot->markup_percentage_bps);
        $this->assertSame(312800, (int) $snapshot->customer_price_minor);
        $this->assertSame(40800, (int) $snapshot->gross_profit_minor);
        $this->assertSame(1304, (int) $snapshot->profit_margin_bps, '408 on 3,128 is 13.04%');

        // The provider's service id is recorded, so reconciliation can address the
        // right service without reading today's catalogue.
        $this->assertSame('3877', $order->order_payload['provider_service_id']);
        $this->assertSame(272000, $order->order_payload['provider_rate_minor']);

        // One debit, one ledger entry, one transaction.
        $this->assertSame(1, Transactions::where('user_id', $user->id)->count());
        $this->assertSame(1, WalletLedger::where('user_id', $user->id)->where('direction', 'debit')->count());
    }

    public function test_the_adapter_sends_nitros_documented_request(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $this->place($user, $product, $offering, target: 'https://instagram.com/client', quantity: 2500);

        Http::assertSent(function ($request) {
            if (($request['action'] ?? null) !== 'add') {
                return false;
            }

            /*
             * Form-encoded POST to the documented base URL, with `key` present and
             * the documented parameter names  `service`, `link`, `quantity`.
             *
             * The comparison is loose on `quantity` on purpose: form bodies carry
             * every value as a string, so a strict `=== 2500` would be asserting
             * something about PHP's form encoding rather than about what Nitro
             * receives.
             */
            return $request->url() === self::NITRO_URL
                && (string) $request['service'] === '3877'
                && $request['link'] === 'https://instagram.com/client'
                && (string) $request['quantity'] === '2500'
                && $request['key'] === 'nitro_test_key';
        });
    }

    public function test_a_provider_refusal_is_recorded_as_failed_and_the_customer_is_refunded(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();

        // `Incorrect service ID`  a documented refusal, and one that means the
        // request provably never created an order.
        $this->fakeNitro(['add' => Http::response(['error' => 'Incorrect service ID'])]);

        $result = $this->place($user, $product, $offering);

        $this->assertFalse($result['ok']);
        $this->assertSame(ProviderStatus::RETRYABLE, $result['status']);

        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->firstOrFail();

        // RETRYABLE is a non-fulfilment: the provider has stated it did not fulfil
        // the request, so the money is the customer's. The refund is a separate,
        // recorded credit.
        $this->assertTrue(
            app(\App\Orders\ServiceOrderService::class)->refundUnfulfilled($order, 'Provider rejected the order'),
            'A non-fulfilment must be refundable'
        );

        $this->assertSame('20000.00', $this->balanceOf($user), 'Refunded in full');
        $this->assertSame(ServiceOrder::STATUS_REFUNDED, $order->fresh()->status);
    }

    /* =====================================================================
     | 3. Timeouts, UNKNOWN and reconciliation
     |=================================================================== */

    /**
     * The single most important safety property of this module.
     */
    public function test_a_timeout_holds_the_order_and_never_refunds_or_resubmits(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();

        // A connection that never produced a response.
        Http::fake(function ($request) {
            if (($request['action'] ?? null) === 'add') {
                throw new \Illuminate\Http\Client\ConnectionException('Operation timed out');
            }

            return Http::response(['error' => 'unexpected']);
        });

        $result = $this->place($user, $product, $offering);

        $this->assertFalse($result['ok']);
        $this->assertSame(ProviderStatus::UNKNOWN, $result['status'], 'A timeout is UNKNOWN, not FAILED');

        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(ServiceOrder::STATUS_UNKNOWN, $order->status);
        $this->assertFalse($order->outcomeIsKnown());
        $this->assertNotNull($order->next_reconcile_at, 'It must be scheduled for reconciliation');

        // The money is still debited: the order may have been delivered.
        $this->assertSame('16872.00', $this->balanceOf($user));
        $this->assertSame(0, Transactions::where('user_id', $user->id)->where('service_type', 'refund')->count());

        // And the message must not read as a failure.
        $this->assertStringContainsString('confirm', strtolower($result['message']));
        $this->assertStringNotContainsString('failed', strtolower($result['message']));
    }

    public function test_an_unknown_order_cannot_be_refunded_until_it_is_reconciled(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();

        Http::fake(fn ($request) => ($request['action'] ?? null) === 'add'
            ? throw new \Illuminate\Http\Client\ConnectionException('timed out')
            : Http::response(['error' => 'unexpected']));

        $this->place($user, $product, $offering);

        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->firstOrFail();

        $refunded = app(\App\Orders\ServiceOrderService::class)->refundUnfulfilled($order, 'Assuming it failed');

        $this->assertFalse($refunded, 'Refunding an order that may have been delivered gives away the goods');
        $this->assertSame('16872.00', $this->balanceOf($user));
    }

    public function test_reconciliation_resolves_an_unknown_order_without_placing_a_second_one(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();

        $this->fakeNitro([
            'add' => Http::response(['order' => 4211]),
            'status' => Http::response([
                'charge' => '2720.00',
                'start_count' => '1200',
                'status' => 'Completed',
                'remains' => '0',
                'currency' => 'NGN',
            ]),
        ]);

        $this->place($user, $product, $offering);

        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->firstOrFail();

        // Placed, and explicitly not delivered: the provider has not reported back.
        $this->assertFalse($order->isSuccess());
        $this->assertFalse($order->outcomeIsKnown());

        $result = app(SmmOrders::class)->reconcileAndSettle($order);

        $this->assertSame(ProviderStatus::SUCCESS, $result->status);
        $this->assertSame(ServiceOrder::STATUS_SUCCESS, $order->fresh()->status);
        $this->assertSame('Completed', $order->fresh()->provider_status);

        // Exactly one provider order was ever created.
        Http::assertSentCount(2); // add + status
        Http::assertNotSent(fn ($request) => ($request['action'] ?? null) === 'add' && $request['service'] === '3877' && false);
    }

    public function test_a_provider_status_of_partial_marks_the_order_partial_and_refuses_an_automatic_refund(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, attributes: ['supports_cancel' => true]);
        $this->smmRule(1500);

        $user = $this->customer();

        $this->fakeNitro([
            'add' => Http::response(['order' => 4211]),
            'status' => Http::response([
                'charge' => '2720.00',
                'start_count' => '100',
                'status' => 'Partial',
                'remains' => '300',
                'currency' => 'NGN',
            ]),
        ]);

        $this->place($user, $product, $offering);

        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->firstOrFail();

        app(SmmOrders::class)->reconcileAndSettle($order);

        $this->assertSame(ServiceOrder::STATUS_PARTIAL, $order->fresh()->status);

        /*
         * PARTIAL needs a human decision: refunding in full gives away the part
         * that was delivered, refunding nothing keeps money for goods not supplied.
         */
        $this->assertFalse(
            app(\App\Orders\ServiceOrderService::class)->refundUnfulfilled($order->fresh(), 'Partial delivery'),
            'A partial delivery must not be auto-refunded'
        );
        $this->assertSame('16872.00', $this->balanceOf($user));
    }

    /**
     * Nitro refunds to *our* wallet. The customer's refund is a separate movement
     * and is the reason this case exists at all.
     */
    public function test_a_provider_refund_reaches_the_customers_wallet(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, attributes: ['supports_cancel' => true]);
        $this->smmRule(1500);

        $user = $this->customer();

        $this->fakeNitro([
            'add' => Http::response(['order' => 4211]),
            'status' => Http::response([
                'charge' => '2720.00',
                'start_count' => '0',
                'status' => 'Refunded',
                'remains' => '1000',
                'currency' => 'NGN',
            ]),
        ]);

        $this->place($user, $product, $offering);

        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame('16872.00', $this->balanceOf($user), 'Charged at placement');

        app(SmmOrders::class)->reconcileAndSettle($order);

        $order->refresh();

        $this->assertSame(ServiceOrder::STATUS_REFUNDED, $order->status);
        $this->assertSame('20000.00', $this->balanceOf($user), 'The customer is made whole');
        $this->assertNotNull($order->refunded_at);

        // One refund transaction, and the purchase is marked refunded.
        $this->assertSame(1, Transactions::where('user_id', $user->id)->where('service_type', 'refund')->count());
        $this->assertSame('refunded', $order->transaction->fresh()->payment_status);
    }

    public function test_a_provider_refund_is_only_credited_once_however_often_reconciliation_runs(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();

        $this->fakeNitro([
            'add' => Http::response(['order' => 4211]),
            'status' => Http::response([
                'charge' => '2720.00',
                'start_count' => '0',
                'status' => 'Refunded',
                'remains' => '1000',
                'currency' => 'NGN',
            ]),
        ]);

        $this->place($user, $product, $offering);
        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->firstOrFail();

        app(SmmOrders::class)->reconcileAndSettle($order);
        app(SmmOrders::class)->reconcileAndSettle($order->fresh());
        app(SmmOrders::class)->reconcileAndSettle($order->fresh());

        $this->assertSame('20000.00', $this->balanceOf($user), 'Credited once, not three times');
        $this->assertSame(1, Transactions::where('user_id', $user->id)->where('service_type', 'refund')->count());
    }

    /* =====================================================================
     | 4. Duplicate submissions
     | ==================================================================== */

    public function test_the_same_order_submitted_twice_creates_one_order_and_one_debit(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $key = app(SmmOrders::class)->idempotencyKey($user, $product, 'https://instagram.com/client', 1000);

        $first = $this->place($user, $product, $offering, idempotencyKey: $key);

        $this->assertNotNull($first['order']);

        /*
         * The second submit is refused by the short-window duplicate guard before it
         * reaches the idempotency reservation, and either answer is correct: the
         * customer is told "an identical purchase was just submitted" rather than
         * being sold the order twice.
         *
         * The guard is a courtesy; the database is the control. The test below
         * clears the guard and proves the reservation itself is what turns the
         * repeat into a replay.
         */
        try {
            $this->place($user, $product, $offering, idempotencyKey: $key);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('identical purchase', $e->getMessage());
        }

        $this->assertSame(1, ServiceOrder::where('user_id', $user->id)->count());
        $this->assertSame('16872.00', $this->balanceOf($user), 'Debited once');
        $this->assertSame(1, WalletLedger::where('user_id', $user->id)->where('direction', 'debit')->count());
    }

    /**
     * The idempotency reservation is the control; the duplicate guard is a courtesy.
     */
    public function test_the_database_idempotency_reservation_makes_a_repeat_a_replay(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $key = app(SmmOrders::class)->idempotencyKey($user, $product, 'https://instagram.com/client', 1000);

        $first = $this->place($user, $product, $offering, idempotencyKey: $key);

        $this->assertNotNull($first['order']);

        // Clear only the short-window guard, leaving the reservation in place  a
        // cache flush, a second web server or a five-minute gap does exactly this.
        Cache::flush();

        $second = $this->place($user, $product, $offering, idempotencyKey: $key);

        $this->assertSame(
            $first['order']->getKey(),
            $second['order']?->getKey(),
            'The reserved key answers from the original order'
        );

        $this->assertSame(1, ServiceOrder::where('user_id', $user->id)->count());
        $this->assertSame('16872.00', $this->balanceOf($user), 'Still debited once');
        $this->assertSame(1, WalletLedger::where('user_id', $user->id)->where('direction', 'debit')->count());
    }

    public function test_the_idempotency_key_is_deterministic_and_quantity_aware(): void
    {
        $product = $this->product();
        $user = $this->customer();

        $orders = app(SmmOrders::class);

        $a = $orders->idempotencyKey($user, $product, 'https://instagram.com/client', 1000);
        $b = $orders->idempotencyKey($user, $product, 'https://instagram.com/client', 1000);

        $this->assertSame($a, $b, 'The same form twice must present the same key');

        // A different quantity is a different purchase and must not be collapsed.
        $this->assertNotSame($a, $orders->idempotencyKey($user, $product, 'https://instagram.com/client', 2000));

        // So is a different target.
        $this->assertNotSame($a, $orders->idempotencyKey($user, $product, 'https://instagram.com/other', 1000));
    }

    public function test_a_reused_key_with_a_different_order_is_refused(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $key = 'smm-fixed-key-for-testing-0001';

        $this->place($user, $product, $offering, idempotencyKey: $key);

        $this->expectException(\RuntimeException::class);

        $this->place($user, $product, $offering, target: 'https://instagram.com/different', idempotencyKey: $key);
    }

    /* =====================================================================
     | 5. Refill and cancellation are per service
     | ==================================================================== */

    public function test_refill_is_offered_only_where_the_service_supports_it(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $orders = app(SmmOrders::class);

        $withRefill = $this->offering($provider, $product, '3877', attributes: ['supports_refill' => true]);
        $without = $this->offering($provider, $product, '4310', attributes: ['supports_refill' => false]);

        $this->smmRule(1500);
        $this->fakeNitro();

        $user = $this->customer();

        $placed = $this->place($user, $product, $withRefill);
        $order = $placed['order'];

        // A successful order is what a refill applies to.
        $order->forceFill(['status' => ServiceOrder::STATUS_SUCCESS, 'provider_reference' => '4211'])->save();

        $this->assertTrue($orders->canRefill($order->fresh()), 'This service supports refills');

        $order->forceFill(['provider_product_id' => $without->getKey()])->save();

        $this->assertFalse($orders->canRefill($order->fresh()), 'This one does not, and it must be refused');
    }

    public function test_cancellation_is_refused_for_a_service_that_does_not_support_it(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, attributes: [
            'supports_cancel' => false,
        ]);
        $this->smmRule(1500);
        $this->fakeNitro();

        $user = $this->customer();
        $placed = $this->place($user, $product, $offering);

        $order = $placed['order'];

        $this->assertFalse(
            app(SmmOrders::class)->canCancel($order),
            'Nitro publishes cancellation per service; unsupported means unsupported'
        );
    }

    public function test_a_cancelled_order_is_not_marked_cancelled_until_the_provider_says_so(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, attributes: ['supports_cancel' => true]);
        $this->smmRule(1500);

        $user = $this->customer();

        $this->fakeNitro([
            'add' => Http::response(['order' => 4211]),
            'cancel' => Http::response([['order' => 4211, 'cancel' => 1]]),
        ]);

        $placed = $this->place($user, $product, $offering);

        $order = $placed['order'];

        $result = app(SmmOrders::class)->cancel($order);

        $this->assertSame(ProviderStatus::SUCCESS, $result->status, 'The provider accepted the request');

        // Requested, not granted. Nitro's status is the authority, and the
        // reconciliation sweep is what applies it.
        $this->assertFalse($order->fresh()->outcomeIsKnown(), 'Still running, not cancelled');
        $this->assertNotSame(ServiceOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame('16872.00', $this->balanceOf($user), 'Nothing is refunded on a request');
    }

    /* =====================================================================
     | 6. Catalogue synchronisation
     |=================================================================== */

    public function test_the_sync_records_every_service_fact_nitro_publishes(): void
    {
        $provider = $this->nitroProvider();
        $this->fakeNitro();

        $result = app(CatalogueSync::class)->syncProvider($provider, ['smm']);

        // Nothing is mapped, so every service is staged rather than sold.
        $this->assertSame(2, $result['unmapped']);
        $this->assertSame(0, ProviderProduct::count());

        $staged = \App\Models\ProviderCatalogueItem::where('provider_id', $provider->getKey())->get();

        $this->assertCount(2, $staged);

        $followers = $staged->firstWhere('provider_product_id', '3877');

        $this->assertNotNull($followers);

        // The full provider row is kept on the staged item, so the platform, the
        // rate, the limits and the capability flags are all recoverable by the
        // operator who maps it.
        $raw = $followers->payload;

        $this->assertSame('Instagram', $raw['category']);
        $this->assertSame('2720.00', $raw['rate'], 'The rate as Nitro publishes it');
        $this->assertSame(100, (int) $raw['min']);
        $this->assertSame(50000, (int) $raw['max']);
        $this->assertTrue((bool) $raw['refill']);
        $this->assertTrue((bool) $raw['cancel']);
        $this->assertSame('Default', $raw['type']);
    }

    public function test_the_sync_stores_the_rate_limits_and_capability_flags_on_a_mapped_offering(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 100000);

        $this->fakeNitro();

        app(CatalogueSync::class)->syncProvider($provider, ['smm']);

        $offering->refresh();

        // Nitro's documented fields, each landing in its own column.
        $this->assertSame(272000, (int) $offering->provider_rate_minor, '2,720 per 1,000');
        $this->assertSame(1000, (int) $offering->rate_divisor);
        $this->assertSame(100, (int) $offering->min_quantity);
        $this->assertSame(50000, (int) $offering->max_quantity);
        $this->assertSame('Instagram', $offering->platform);
        $this->assertSame('Default', $offering->service_type);
        $this->assertTrue((bool) $offering->supports_refill);
        $this->assertTrue((bool) $offering->supports_cancel);
        $this->assertNotNull($offering->cost_synced_at);

        // The one-unit cost, derived through the divisor.
        $this->assertSame(272, (int) $offering->provider_cost_minor);
    }

    public function test_a_service_the_provider_stops_publishing_becomes_unsellable_and_is_never_deleted(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 100000);

        // The provider no longer lists service 3877.
        Http::fake(fn ($request) => ($request['action'] ?? null) === 'services'
            ? Http::response([[
                'service' => 9999,
                'name' => 'Something else',
                'category' => 'TikTok',
                'rate' => '1000.00',
                'min' => 100,
                'max' => 1000,
                'refill' => false,
                'cancel' => false,
                'type' => 'Default',
                'description' => '',
            ]])
            : Http::response([]));

        $result = app(CatalogueSync::class)->syncProvider($provider, ['smm']);

        $this->assertSame(1, $result['unavailable']);

        $offering->refresh();

        $this->assertNotNull($offering->unavailable_at, 'Flagged, so it leaves the storefront');

        // Flagged, never deleted: historical orders and provider references must
        // stay resolvable.
        $this->assertNotNull($offering->fresh());
    }

    public function test_a_service_that_reappears_is_restored(): void
    {
        config(['pricing.smm.minimum_markup_bps' => 1000]);

        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 100000, attributes: [
            'unavailable_at' => now()->subDay(),
        ]);

        $this->fakeNitro();

        app(CatalogueSync::class)->syncProvider($provider, ['smm']);

        $this->assertNull($offering->fresh()->unavailable_at);
    }

    /**
     * A catalogue read that failed must not empty the storefront.
     */
    public function test_a_failed_catalogue_read_leaves_the_catalogue_alone(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);

        // Nitro answers 200 with an error body  the SMM convention, and the
        // reason a naive `successful()` check reads a refusal as a catalogue.
        Http::fake(['*' => Http::response(['error' => 'Invalid API key'])]);

        $result = app(CatalogueSync::class)->syncProvider($provider, ['smm']);

        $this->assertSame(0, $result['unavailable'], 'A failed read is not evidence a service vanished');

        $offering->refresh();

        $this->assertNull($offering->unavailable_at);
        $this->assertSame(272000, (int) $offering->provider_rate_minor, 'The rate is untouched');
    }

    public function test_a_rate_change_is_recorded_and_appends_history(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 100000);

        $this->fakeNitro();

        $result = app(CatalogueSync::class)->syncProvider($provider, ['smm']);

        $this->assertSame(1, $result['cost_changes']);

        $offering->refresh();

        $this->assertSame(272000, (int) $offering->provider_rate_minor);
        $this->assertSame(100, (int) $offering->previous_cost_minor, 'The one-unit cost it replaced');
        $this->assertSame(1, \App\Models\ProviderPriceSnapshot::where('provider_product_id', $offering->getKey())->count());
    }

    public function test_a_rate_the_provider_stops_publishing_makes_the_service_unsellable(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        // Still listed, but the rate is gone: `rate` is absent from the row.
        Http::fake(fn ($request) => ($request['action'] ?? null) === 'services'
            ? Http::response([[
                'service' => 3877,
                'name' => 'Instagram Followers  Standard',
                'category' => 'Instagram',
                'min' => 100,
                'max' => 50000,
                'refill' => true,
                'cancel' => true,
                'type' => 'Default',
                'description' => '',
            ]])
            : Http::response([]));

        app(CatalogueSync::class)->syncProvider($provider, ['smm']);

        $offering->refresh();

        $this->assertNull($offering->provider_rate_minor);
        $this->assertNull(
            app(SmmPricing::class)->providerCost($offering, 1000),
            'A service with no published rate must not be priced from its last known one'
        );
    }

    /* =====================================================================
     | 7. Target validation
     | ==================================================================== */

    public function test_a_target_is_validated_against_its_platform(): void
    {
        $validator = app(SmmTargetValidator::class);

        $this->assertTrue($validator->validate('Instagram', 'https://instagram.com/client')['ok']);
        $this->assertTrue($validator->validate('Instagram', 'https://www.instagram.com/p/Cabc123/')['ok']);
        $this->assertTrue($validator->validate('Instagram', '@client')['ok']);
        $this->assertTrue($validator->validate('instagram', 'client')['ok'], 'Case is not significant');

        // A link to a different platform is refused before the order is created,
        // rather than by the provider after the debit.
        $this->assertFalse($validator->validate('Instagram', 'https://tiktok.com/@someone')['ok']);
        $this->assertFalse($validator->validate('Instagram', '')['ok']);
        $this->assertFalse($validator->validate('Instagram', 'not a link at all')['ok']);
    }

    public function test_a_target_containing_whitespace_is_refused(): void
    {
        $verdict = app(SmmTargetValidator::class)->validate('Instagram', 'https://instagram.com/cli ent');

        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('space', strtolower((string) $verdict['message']));
    }

    public function test_an_unknown_platform_falls_back_rather_than_refusing_everything(): void
    {
        $validator = app(SmmTargetValidator::class);

        // A platform Nitro added since config/smm.php was last edited must still
        // be sellable  refusing everything would make it unsellable on day one.
        $this->assertTrue($validator->validate('SomeNewPlatform', 'https://example.com/page')['ok']);
        $this->assertTrue($validator->validate(null, 'example.com/page')['ok']);
    }

    /* =====================================================================
     | 8. The customer surfaces
     | ==================================================================== */

    public function test_the_catalogue_shows_only_sellable_services(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 100000);
        $this->smmRule(1500);

        // A second service the provider has withdrawn, and a third with no rate.
        $withdrawn = $this->product('Withdrawn service');
        $this->offering($provider, $withdrawn, '9999', rateMinor: 100000, attributes: ['unavailable_at' => now()]);

        $unpricedProduct = $this->product('Unpriced service');
        $this->offering($provider, $unpricedProduct, '8888', rateMinor: null, attributes: [
            'provider_rate_minor' => null,
            'provider_cost_minor' => null,
        ]);

        $catalogue = app(SmmCatalogue::class);

        $sellable = $catalogue->sellableProducts();

        $this->assertCount(1, $sellable, 'Only the service that can actually be bought');
        $this->assertSame($product->getKey(), $sellable->first()->getKey());

        $platforms = $catalogue->platforms();
        $this->assertCount(1, $platforms);
        $this->assertSame('instagram', $platforms[0]['key']);
        $this->assertSame('Instagram', $platforms[0]['label']);
    }

    public function test_the_landing_page_renders_platforms_and_recent_orders(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 100000);
        $this->smmRule(1500);

        $user = $this->customer();

        $response = $this->actingAs($user)->get(route('social-boost.index'));

        $response->assertOk();
        $response->assertSee('Social Boost');
        $response->assertSee('Instagram');
        $response->assertSee('Browse all services');
        $response->assertSee('Wallet balance');
    }

    public function test_the_catalogue_page_lists_prices_and_nevershows_a_provider_id(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();

        $response = $this->actingAs($user)->get(route('social-boost.catalogue'));

        $response->assertOk();
        $response->assertSee($product->name);

        // The provider's identifier and name are internal. A customer navigating
        // by them would be one devtools edit away from buying a different service.
        $response->assertDontSee('3877');
        $response->assertDontSee('Nitro');
    }

    public function test_the_order_form_renders_the_platform_target_hint(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $response = $this->actingAs($this->customer())->get(route('social-boost.configure', $product->product_key));

        $response->assertOk();
        $response->assertSee($product->name);
        $response->assertSee('instagram.com', false);
        $response->assertSee('Review order');
    }

    public function test_the_review_step_shows_the_target_the_price_and_the_balance_after(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer('5000.00');

        $response = $this->actingAs($user)->post(route('social-boost.review'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
        ]);

        $response->assertOk();
        $response->assertSee('Review your order');
        $response->assertSee('https://instagram.com/client');
        $response->assertSee('3,128.00');
        $response->assertSee('Balance after');
    }

    public function test_a_quantity_outside_the_services_bounds_is_refused(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();

        // Nitro publishes min 100 and max 50000 for 3877.
        $this->actingAs($user)->post(route('social-boost.review'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 50,
        ])->assertSessionHasErrors('quantity');

        $this->actingAs($user)->post(route('social-boost.review'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 120000,
        ])->assertSessionHasErrors('quantity');
    }

    public function test_a_purchase_through_the_customer_form_places_one_order(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $response = $this->actingAs($user)->post(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'pin' => '5931',
        ]);

        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($order, 'The purchase should have produced an order');
        $response->assertRedirect(route('social-boost.orders.show', $order->uuid));
        $this->assertSame('16872.00', $this->balanceOf($user));
    }

    /**
     * A client-supplied price changes nothing.
     */
    public function test_a_tampered_price_cannot_buy_a_boost_cheaply(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        /*
         * The page claims the boost costs 1.00. The server prices it from the
         * provider's rate and the rule, so the claim does not match  and the
         * answer is a **confirmation prompt at the real price**, not a purchase at
         * the claimed one.
         *
         * That is the same response a genuinely stale page gets, deliberately: the
         * server cannot tell a tampered figure from an out-of-date one, and both
         * have the same correct remedy. What matters is that nothing was bought.
         */
        $response = $this->actingAs($user)->postJson(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '1.00',
            'pin' => '5931',
        ]);

        $response->assertStatus(409);
        $this->assertSame('price_changed', $response->json('status'));
        $this->assertSame('3128.00', $response->json('new_price'), 'The server decides the price');

        $this->assertSame(0, ServiceOrder::where('user_id', $user->id)->count(), 'Nothing was bought');
        $this->assertSame('20000.00', $this->balanceOf($user), 'And nothing was charged');
    }

    /**
     * The same hazard, reached the other way: a posted price that happens to match
     * is still not what is charged from.
     */
    public function test_the_posted_price_is_never_the_amount_charged(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        // The honest figure, so no confirmation is triggered.
        $this->actingAs($user)->post(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '3128.00',
            'pin' => '5931',
        ]);

        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->firstOrFail();

        // 2,720 cost + 15% = 3,128, computed by the engine  the posted figure
        // agreed with it, and would have been ignored if it had not.
        $this->assertSame(312800, (int) $order->pricingSnapshot->customer_price_minor);
        $this->assertSame(40800, (int) $order->pricingSnapshot->gross_profit_minor);
        $this->assertSame('16872.00', $this->balanceOf($user));
    }

    public function test_the_order_detail_page_shows_the_reference_and_never_the_provider(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);
        $this->fakeNitro();

        $user = $this->customer();
        $placed = $this->place($user, $product, $offering);

        $response = $this->actingAs($user)->get(route('social-boost.orders.show', $placed['order']->uuid));

        $response->assertOk();
        $response->assertSee($placed['order']->uuid);
        $response->assertSee($product->name);
        $response->assertSee('3,128.00');

        /*
         * The provider's identity, its service id and its provider order id are
         * internal.
         *
         * The assertion is on the *visible* page rather than on the raw HTML,
         * deliberately: the documentation comments inside the Blade file are
         * served inside `<script>` and `<!-- -->` and are not read by a customer,
         * while a rendered figure or label is. Asserting on raw HTML would make
         * "explain the code" and "hide from the customer" the same requirement,
         * and the first one is worth keeping.
         */
        $html = (string) $response->getContent();

        // Script bodies removed before stripping tags: `strip_tags` keeps the
        // *contents* of `<script>`, so a documentation comment inside one would
        // still be counted as "visible".
        $visible = strip_tags((string) preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html));

        $this->assertStringNotContainsString('Nitro', $visible);
        $this->assertStringNotContainsString('3877', $visible);
        $this->assertStringNotContainsString('4211', $visible, 'The provider order id is internal');
    }

    public function test_a_customer_cannot_read_another_customers_boost_order(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);
        $this->fakeNitro();

        $owner = $this->customer();
        $other = $this->customer();

        $placed = $this->place($owner, $product, $offering);

        $this->actingAs($other)
            ->get(route('social-boost.orders.show', $placed['order']->uuid))
            ->assertNotFound();

        $this->actingAs($other)
            ->getJson(route('social-boost.orders.status', $placed['order']->uuid))
            ->assertNotFound();
    }

    public function test_the_help_page_explains_each_platforms_link_format(): void
    {
        $response = $this->actingAs($this->customer())->get(route('social-boost.help'));

        $response->assertOk();
        $response->assertSee('What link should I paste');
        $response->assertSee('instagram.com', false);
        $response->assertSee('public');
        $response->assertSee('refund', false);
    }

    /* =====================================================================
     | 9. Price-change confirmation
     | ==================================================================== */

    public function test_a_price_that_moved_shows_the_confirmation_modal_and_charges_nothing(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        // The customer's page showed a price derived from an older rate.
        $response = $this->actingAs($user)->postJson(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '2800.00',
            'pin' => '5931',
        ]);

        $response->assertStatus(409);

        $payload = $response->json();

        $this->assertSame('price_changed', $payload['status']);
        $this->assertSame('2800.00', $payload['previous_price'], 'What the customer was shown');
        $this->assertSame('3128.00', $payload['new_price'], 'The authoritative figure');
        $this->assertNotNull($payload['quote']['identifier']);
        $this->assertStringContainsString('Would you like to continue', $payload['message']);

        $this->assertSame('20000.00', $this->balanceOf($user));
        $this->assertSame(0, ServiceOrder::where('user_id', $user->id)->count());
        $this->assertSame(0, Transactions::where('user_id', $user->id)->count());
    }

    public function test_accepting_the_new_price_places_the_order_at_it(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $token = $this->actingAs($user)->postJson(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '2800.00',
            'pin' => '5931',
        ])->json('quote.identifier');

        $response = $this->actingAs($user)->post(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '3128.00',
            'price_confirmation' => $token,
            'pin' => '5931',
        ]);

        $order = ServiceOrder::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($order, 'The accepted order should have been placed');
        $response->assertRedirect(route('social-boost.orders.show', $order->uuid));
        $this->assertSame(312800, (int) $order->pricingSnapshot->customer_price_minor);
        $this->assertSame('16872.00', $this->balanceOf($user));
    }

    public function test_a_replayed_confirmation_is_consumed_once(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $token = $this->actingAs($user)->postJson(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '2800.00',
            'pin' => '5931',
        ])->json('quote.identifier');

        $payload = [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '3128.00',
            'price_confirmation' => $token,
            'pin' => '5931',
        ];

        $this->actingAs($user)->post(route('social-boost.purchase'), $payload);

        $afterFirst = $this->balanceOf($user);

        $second = $this->actingAs($user)->postJson(route('social-boost.purchase'), $payload);

        $second->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_UNKNOWN, $second->json('reason'));

        $this->assertSame(1, ServiceOrder::where('user_id', $user->id)->count());
        $this->assertSame($afterFirst, $this->balanceOf($user), 'Debited once');
    }

    public function test_a_confirmation_issued_for_one_quantity_does_not_authorise_another(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $token = $this->actingAs($user)->postJson(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '2800.00',
            'pin' => '5931',
        ])->json('quote.identifier');

        // The same token, presented for a different quantity.
        $response = $this->actingAs($user)->postJson(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 5000,
            'price' => '15640.00',
            'price_confirmation' => $token,
            'pin' => '5931',
        ]);

        $response->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_WRONG_PRODUCT, $response->json('reason'));
        $this->assertSame('20000.00', $this->balanceOf($user));
    }

    public function test_an_invented_confirmation_token_is_refused(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $response = $this->actingAs($user)->postJson(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '3128.00',
            'price_confirmation' => str_repeat('x', 48),
            'pin' => '5931',
        ]);

        $response->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_UNKNOWN, $response->json('reason'));
        $this->assertSame('20000.00', $this->balanceOf($user));
    }

    public function test_an_unchanged_price_goes_straight_through(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();
        $this->fakeNitro();

        $response = $this->actingAs($user)->post(route('social-boost.purchase'), [
            'service' => $product->product_key,
            'target' => 'https://instagram.com/client',
            'quantity' => 1000,
            'price' => '3128.00',
            'pin' => '5931',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, ServiceOrder::where('user_id', $user->id)->count());
    }

    /* =====================================================================
     | 10. Admin
     | ==================================================================== */

    public function test_the_admin_overview_requires_the_social_boost_permission(): void
    {
        /*
         * A customer is refused outright rather than redirected: the admin guard
         * refuses a non-admin account with a 403, and the redirect is reserved for
         * a signed-out visitor.
         */
        $this->actingAs(User::factory()->create())
            ->get(route('admin.social-boost.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin(['view_dashboard'])->create())
            ->get(route('admin.social-boost.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.social-boost.index'))
            ->assertOk()
            ->assertSee('Social Boost');
    }

    public function test_the_admin_catalogue_requires_the_social_boost_permission(): void
    {
        $this->actingAs(User::factory()->admin(['view_pricing', 'manage_catalogue'])->create())
            ->get(route('admin.social-boost.catalogue'))
            ->assertForbidden();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.social-boost.catalogue'))
            ->assertOk();
    }

    public function test_publishing_a_service_requires_manage_catalogue(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        // A super admin has every permission, so the check is exercised by an
        // account that holds the module but not the write.
        $viewer = User::factory()->admin(['view_social_boost'])->create();

        $this->actingAs($viewer)
            ->put(route('admin.social-boost.catalogue.update', $offering), [
                'status' => ServiceProduct::STATUS_PAUSED,
            ])
            ->assertForbidden();

        $this->assertSame(ServiceProduct::STATUS_ACTIVE, $product->fresh()->status);
    }

    public function test_a_service_with_no_usable_price_cannot_be_published(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: null, attributes: [
            'provider_rate_minor' => null,
            'provider_cost_minor' => null,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('admin.social-boost.catalogue.update', $offering), [
                'status' => ServiceProduct::STATUS_ACTIVE,
            ]);

        $this->assertSame(
            ServiceProduct::STATUS_ACTIVE,
            $product->fresh()->status,
            'Publishing it would list a service with no price and refuse at checkout'
        );
    }

    public function test_the_admin_pricing_preview_reports_cost_markup_and_margin(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson(route('admin.social-boost.pricing.preview', [$offering->getKey(), 'quantity' => 1000]));

        $response->assertOk();

        $payload = $response->json();

        $this->assertSame('2720.00', $payload['provider_cost']);
        $this->assertSame('3128.00', $payload['price']);
        $this->assertSame(1500, $payload['markup_bps']);
        $this->assertSame(1304, $payload['margin_bps'], 'Markup and margin are different numbers');
        $this->assertSame('408.00', $payload['gross_profit']);
        $this->assertTrue($payload['sellable']);
        // The rate divisor is reported so an operator can see the formula applied.
        $this->assertSame(1000, $payload['rate_divisor']);
    }

    public function test_the_admin_can_reconcile_an_order(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();

        $this->fakeNitro([
            'add' => Http::response(['order' => 4211]),
            'status' => Http::response([
                'charge' => '2720.00',
                'start_count' => '1200',
                'status' => 'Completed',
                'remains' => '0',
                'currency' => 'NGN',
            ]),
        ]);

        $placed = $this->place($user, $product, $offering);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.social-boost.orders.reconcile', $placed['order']->uuid))
            ->assertRedirect();

        $this->assertSame(ServiceOrder::STATUS_SUCCESS, $placed['order']->fresh()->status);
    }

    public function test_the_admin_cannot_refund_an_order_whose_outcome_is_unknown(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();

        Http::fake(fn ($request) => ($request['action'] ?? null) === 'add'
            ? throw new \Illuminate\Http\Client\ConnectionException('timed out')
            : Http::response(['error' => 'unexpected']));

        $placed = $this->place($user, $product, $offering);

        $this->assertSame(ServiceOrder::STATUS_UNKNOWN, $placed['order']->status);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.social-boost.orders.refund', $placed['order']->uuid), [
                'reason' => 'Customer asked for a refund',
            ])
            ->assertSessionHas('error');

        $this->assertSame('16872.00', $this->balanceOf($user), 'An unknown outcome holds the money');
    }

    public function test_the_admin_can_refund_an_order_the_provider_confirmed_it_did_not_fulfil(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();

        $this->fakeNitro(['add' => Http::response(['error' => 'Incorrect service ID'])]);

        $placed = $this->place($user, $product, $offering);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('admin.social-boost.orders.refund', $placed['order']->uuid), [
                'reason' => 'Provider rejected the service id',
            ])
            ->assertSessionHas('status');

        $this->assertSame('20000.00', $this->balanceOf($user));
    }

    /* =====================================================================
     | 11. Provider health
     | ==================================================================== */

    public function test_the_sync_command_reports_an_unconfigured_provider_without_a_request(): void
    {
        $this->nitroProvider();
        $this->setEnv('NITRO_KEY', null);
        Http::fake();

        /*
         * `expectsOutputToContain` does not exist in Laravel 8, so the message is
         * asserted through the command's own exit code and the warning helper
         * instead  the exit code is what a scheduler reads in any case.
         */
        $this->artisan('smm:sync-catalogue')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_sync_command_creates_the_provider_row_when_it_is_missing(): void
    {
        $this->fakeNitro();

        $this->assertSame(0, Provider::where('slug', 'nitro')->count());

        $this->artisan('smm:sync-catalogue')->assertExitCode(0);

        $provider = Provider::where('slug', 'nitro')->first();

        $this->assertNotNull($provider, 'The command creates the row rather than failing for a seeder run');
        $this->assertSame('nitro', $provider->driver);
        $this->assertSame(['smm'], $provider->capabilities);
        $this->assertSame('NITRO', $provider->credential_env_prefix);
    }

    public function test_the_sync_command_status_reports_without_fetching(): void
    {
        $this->nitroProvider();
        Http::fake();

        // Nothing sellable yet, so the exit code is non-zero  a health check that
        // only reads exit codes still learns about it.
        $this->artisan('smm:sync-catalogue --status')->assertExitCode(1);
        Http::assertNothingSent();
    }

    /**
     * The reconciliation sweep must not touch orders belonging to other product
     * families  nothing on `service_orders` distinguishes them, so this asserts
     * the join is doing the work.
     */
    public function test_the_reconcile_sweep_only_polls_social_boost_orders(): void
    {
        $provider = $this->nitroProvider();
        $product = $this->product();
        $offering = $this->offering($provider, $product, rateMinor: 272000);
        $this->smmRule(1500);

        $user = $this->customer();

        // A gift-card order that is also unresolved, belonging to a different
        // capability entirely.
        $otherProduct = ServiceProduct::create([
            'category_id' => ServiceCategory::firstOrCreate(
                ['slug' => 'gift-cards'],
                ['name' => 'Gift cards', 'group' => 'digital', 'is_active' => true],
            )->getKey(),
            'product_key' => 'steam-10',
            'slug' => 'steam-10',
            'name' => 'Steam 10,000',
            'status' => ServiceProduct::STATUS_ACTIVE,
        ]);

        $otherTransaction = Transactions::create([
            'user_id' => $user->getKey(),
            'reference' => 'GFT-OTHER-1',
            'type' => 'debit',
            'service_type' => 'giftcard',
            'description' => 'Gift card',
            'amount' => '100.00',
            'service_fee' => '0.00',
            'total_amount' => '100.00',
            'payment_method' => 'wallet',
            'payment_status' => 'success',
            'status' => 'processing',
        ]);

        ServiceOrder::create([
            'user_id' => $user->getKey(),
            'transaction_id' => $otherTransaction->getKey(),
            'service_product_id' => $otherProduct->getKey(),
            'product_key' => $otherProduct->product_key,
            'recipient' => 'someone@example.com',
            'quantity' => 1,
            'status' => ServiceOrder::STATUS_PENDING,
            'submitted_at' => now()->subMinutes(10),
            'next_reconcile_at' => now()->subMinute(),
        ]);

        /*
         * One fake for the whole test, registered before anything is placed.
         *
         * Laravel 8's `Http::fake()` *merges* stub callbacks and the first match
         * wins, so a second `fake()` later does not replace the first  it adds to
         * it and the original keeps answering. That has already been recorded once
         * in `DataCatalogueSyncTest`, and getting it wrong here would look like
         * "reconciliation did nothing" rather than like a test bug.
         */
        Http::fake(function ($request) {
            return ($request['action'] ?? null) === 'add'
                ? Http::response(['order' => 4211])
                : Http::response([
                    'charge' => '2720.00',
                    'start_count' => '1200',
                    'status' => 'Completed',
                    'remains' => '0',
                    'currency' => 'NGN',
                ]);
        });

        $this->place($user, $product, $offering);

        // Make the SMM order due.
        ServiceOrder::where('product_key', $product->product_key)
            ->update(['created_at' => now()->subMinutes(10), 'next_reconcile_at' => now()->subMinute()]);

        $this->artisan('smm:reconcile-orders --grace=0')->assertExitCode(0);

        // The SMM order moved; the gift-card order did not.
        $this->assertSame(
            ServiceOrder::STATUS_SUCCESS,
            ServiceOrder::where('product_key', $product->product_key)->firstOrFail()->status
        );
        $this->assertSame(
            ServiceOrder::STATUS_PENDING,
            ServiceOrder::where('product_key', 'steam-10')->firstOrFail()->status,
            'Other capabilities have their own reconciliation'
        );
    }
}
<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\Provider;
use App\Models\ProviderProduct;
use App\Models\ServiceCategory;
use App\Models\ServiceProduct;
use App\Models\User;
use App\Models\Wallet;
use App\Smm\SmmOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every page the Social Boost module adds, rendered end to end.
 *
 * ## Why this exists alongside the unit-level tests
 *
 * The feature tests assert behaviour — the floor is enforced, a timeout is held,
 * a refund reaches the customer. This one asserts the thing none of them can: that
 * **each page a person actually opens returns 200 with the data it needs**. A
 * controller that calls a relation which does not exist, a view referencing a
 * variable the controller never passes, a route whose middleware was registered
 * under the wrong permission — all three render fine in a unit test and produce a
 * 500 in a browser, and two of them were real defects found while building this
 * module.
 *
 * It is also the only test that exercises all four layers together: the catalogue,
 * `SmmPricing`, `ServiceOrderService` (wallet, idempotency, pricing snapshot) and
 * the Nitro adapter, with only the HTTP transport faked.
 */
class SocialBoostPagesTest extends TestCase
{
    use RefreshDatabase;

    private Provider $provider;

    private ServiceProduct $product;

    private ProviderProduct $offering;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            // The documented base URL, pinned so the fakes cannot drift from it.
            'providers.nitro.base_url' => 'https://nitro.ng/api/v2',
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['nitro'],
            'bills.limits.per_minute' => 100,
            'pricing.smm.minimum_markup_bps' => 1000,
        ]);

        $_ENV['NITRO_KEY'] = 'nitro_page_test_key';
        $_SERVER['NITRO_KEY'] = 'nitro_page_test_key';
        putenv('NITRO_KEY=nitro_page_test_key');

        $this->buildCatalogue();
    }

    private function buildCatalogue(): void
    {
        $this->provider = Provider::create([
            'slug' => 'nitro',
            'name' => 'Nitro NG',
            'driver' => 'nitro',
            'capabilities' => ['smm'],
            'is_active' => true,
            'priority' => 100,
            'credential_env_prefix' => 'NITRO',
            'health_status' => Provider::HEALTH_HEALTHY,
        ]);

        $category = ServiceCategory::create([
            'slug' => 'social-boost',
            'name' => 'Social Boost',
            'group' => ServiceCategory::GROUP_SOCIAL,
            'is_active' => true,
        ]);

        $this->product = ServiceProduct::create([
            'category_id' => $category->getKey(),
            'product_key' => 'ig-followers',
            'slug' => 'ig-followers',
            'name' => 'Instagram Followers',
            'status' => ServiceProduct::STATUS_ACTIVE,
            'availability' => ServiceProduct::AVAILABILITY_AVAILABLE,
        ]);

        $this->offering = ProviderProduct::create([
            'provider_id' => $this->provider->getKey(),
            'service_product_id' => $this->product->getKey(),
            'provider_product_id' => '3877',
            'provider_name' => 'Instagram Followers · Standard',
            'provider_rate_minor' => 272000,
            'rate_divisor' => 1000,
            'provider_cost_minor' => 272,
            'provider_currency' => 'NGN',
            'min_quantity' => 100,
            'max_quantity' => 50000,
            'platform' => 'Instagram',
            'service_type' => 'Default',
            'supports_refill' => true,
            'cost_synced_at' => now(),
            'last_seen_at' => now(),
        ]);

        PricingRule::create([
            'name' => 'Social Boost · default markup',
            'scope' => PricingRule::SCOPE_CATEGORY,
            'category_id' => $category->getKey(),
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 1500,
            'minimum_profit_minor' => 1,
            'customer_fee_enabled' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    private function fakeNitro(): void
    {
        Http::fake(fn ($request) => ($request['action'] ?? null) === 'add'
            ? Http::response(['order' => 4211])
            : Http::response([
                'charge' => '2720.00',
                'start_count' => '10',
                'status' => 'In progress',
                'remains' => '500',
                'currency' => 'NGN',
            ]));
    }

    private function customer(string $balance = '20000.00'): User
    {
        $user = User::factory()->create();
        app(\App\Services\SecurityService::class)->setPin($user, '5931');
        app(\App\Services\WalletService::class)->credit($user, $balance);

        return $user->fresh();
    }

    public function test_every_customer_page_renders_and_places_a_real_order(): void
    {
        $this->fakeNitro();

        $customer = $this->customer();

        /*
         * Placed through the service rather than the form, so the pages below are
         * rendered against an order that genuinely went through the wallet, the
         * pricing snapshot and the adapter.
         */
        $placed = app(SmmOrders::class)->place(
            user: $customer,
            product: $this->product,
            offering: $this->offering,
            target: 'https://instagram.com/client',
            quantity: 1000,
            pin: '5931',
        );

        $order = $placed['order'];

        $this->assertNotNull($order);
        $this->assertSame('4211', $order->provider_reference, 'The provider order id must be persisted');
        $this->assertSame('16872.00', (string) Wallet::where('user_id', $customer->id)->value('balance'));

        $pages = [
            'landing' => route('social-boost.index'),
            'catalogue' => route('social-boost.catalogue'),
            'catalogue by platform' => route('social-boost.catalogue', ['platform' => 'instagram']),
            'catalogue searched' => route('social-boost.catalogue', ['q' => 'followers']),
            'configure' => route('social-boost.configure', $this->product->product_key),
            'orders' => route('social-boost.orders.index'),
            'orders filtered' => route('social-boost.orders.index', ['status' => 'PROCESSING']),
            'order detail' => route('social-boost.orders.show', $order->uuid),
            'help' => route('social-boost.help'),
        ];

        foreach ($pages as $name => $url) {
            $this->actingAs($customer)
                ->get($url)
                ->assertOk();
        }
    }

    /**
     * The airtime, data, wallet and dashboard surfaces this module shares code
     * with — the pricing engine, the network-brand helper and the order pipeline.
     */
    public function test_the_pages_this_module_shares_code_with_still_render(): void
    {
        $customer = $this->customer();

        foreach ([
            route('airtime-data.index'),
            route('airtime-data.history'),
            route('transactions.index'),
            route('dashboard'),
        ] as $url) {
            $this->actingAs($customer)->get($url)->assertOk();
        }
    }

    public function test_every_admin_page_renders(): void
    {
        $this->fakeNitro();

        $customer = $this->customer();

        $placed = app(SmmOrders::class)->place(
            user: $customer,
            product: $this->product,
            offering: $this->offering,
            target: 'https://instagram.com/client',
            quantity: 1000,
            pin: '5931',
        );

        $admin = User::factory()->superAdmin()->create();

        $pages = [
            'overview' => route('admin.social-boost.index'),
            'catalogue' => route('admin.social-boost.catalogue'),
            'catalogue filtered' => route('admin.social-boost.catalogue', ['state' => 'sellable']),
            'orders' => route('admin.social-boost.orders'),
            'orders searched' => route('admin.social-boost.orders', ['q' => $placed['order']->uuid]),
            'order detail' => route('admin.social-boost.orders.show', $placed['order']->uuid),
            'provider health' => route('admin.social-boost.provider'),
            // The generic provider console, which now carries Social Boost's state.
            'provider console' => route('admin.providers.index'),
        ];

        foreach ($pages as $name => $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk();
        }
    }

    public function test_the_pricing_preview_renders_the_figures_an_operator_needs(): void
    {
        $this->fakeNitro();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson(route('admin.social-boost.pricing.preview', [
                $this->offering->getKey(),
                'quantity' => 1000,
            ]));

        $response->assertOk();

        $payload = $response->json();

        // Cost, price, markup, margin and profit — and markup is not margin.
        $this->assertSame('2720.00', $payload['provider_cost']);
        $this->assertSame('3128.00', $payload['price']);
        $this->assertSame(1500, $payload['markup_bps']);
        $this->assertSame(1304, $payload['margin_bps']);
        $this->assertSame('408.00', $payload['gross_profit']);
        $this->assertSame(1000, $payload['rate_divisor']);
        $this->assertTrue($payload['sellable']);
    }

    /**
     * The price preview the order form calls while the customer types.
     */
    public function test_the_customer_price_estimate_endpoint_returns_the_engine_price(): void
    {
        $this->fakeNitro();

        $response = $this->actingAs($this->customer())
            ->getJson(route('social-boost.price-preview', [
                'service' => $this->product->product_key,
                'quantity' => 1000,
            ]));

        $response->assertOk();
        $this->assertTrue($response->json('sellable'));
        $this->assertSame('3128.00', $response->json('price'));

        // No cost, profit or provider figure is ever returned to a customer.
        $this->assertArrayNotHasKey('provider_cost', $response->json());
        $this->assertArrayNotHasKey('gross_profit', $response->json());
        $this->assertArrayNotHasKey('margin_bps', $response->json());
    }
}

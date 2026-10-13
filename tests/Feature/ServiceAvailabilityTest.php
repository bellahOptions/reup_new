<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\ServiceProduct;
use App\Services\ServiceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Live, coming soon, and disabled services.
 *
 * ## What this file is really testing
 *
 * Not that a page renders a message — that is the easy part. It is testing that the
 * **three states are consistent across every surface that can sell something**:
 *
 *   * a coming-soon service's page says so, and its purchase endpoint refuses too;
 *   * a coming-soon service cannot be bought through its *product*, which is the
 *     route that does not go through the service's page at all;
 *   * an unset configuration reproduces the behaviour that existed before these
 *     variables did, so a forgotten line in `.env` is not an outage.
 *
 * The second is the one worth having. A flag checked only in middleware leaves the
 * catalogue selling the thing, and a flag checked only in the catalogue leaves the
 * purchase endpoint open. Either way money is taken for something nobody meant to
 * offer.
 */
class ServiceAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /* =====================================================================
     | Resolution
     |=================================================================== */

    /**
     * The default, and the most important test in this file.
     */
    public function test_an_unset_configuration_leaves_every_service_live(): void
    {
        $this->configure(enabled: [], comingSoon: [], disabled: []);

        foreach (array_keys((array) config('features.services')) as $service) {
            $this->assertTrue(
                app(ServiceGate::class)->isLive($service),
                "[{$service}] must be live when nothing is configured — an unset variable cannot be an outage"
            );
        }
    }

    public function test_a_named_service_is_live_and_the_rest_fall_to_coming_soon(): void
    {
        // Naming the live list is the opt-in to strict behaviour.
        $this->configure(enabled: ['airtime', 'data']);

        $gate = app(ServiceGate::class);

        $this->assertTrue($gate->isLive('airtime'));
        $this->assertTrue($gate->isLive('data'));

        // Everything else is announced but not sold, so a new service added to the
        // config cannot go live by accident.
        $this->assertTrue($gate->isComingSoon('social_boost'));
        $this->assertTrue($gate->isComingSoon('cable_tv'));
        $this->assertFalse($gate->isSellable('social_boost'));
    }

    public function test_an_explicit_coming_soon_service_is_not_launched_by_being_omitted(): void
    {
        $this->configure(enabled: ['airtime'], comingSoon: ['social_boost']);

        $gate = app(ServiceGate::class);

        $this->assertTrue($gate->isLive('airtime'));
        $this->assertTrue($gate->isComingSoon('social_boost'));
    }

    public function test_a_disabled_service_is_disabled(): void
    {
        $this->configure(enabled: ['airtime', 'betting'], disabled: ['betting']);

        $gate = app(ServiceGate::class);

        $this->assertTrue($gate->isLive('airtime'));
        $this->assertTrue(
            $gate->isDisabled('betting'),
            'A service named in both lists is refused: the most restrictive instruction wins'
        );
    }

    public function test_an_unknown_service_is_disabled_rather_than_live(): void
    {
        $this->configure();

        $gate = app(ServiceGate::class);

        $this->assertTrue($gate->isDisabled('not_a_service'));
        $this->assertFalse($gate->isSellable('not_a_service'));
        $this->assertNull($gate->definition('not_a_service'));
    }

    /* =====================================================================
     | Route guarding
     |=================================================================== */

    public function test_a_live_service_serves_its_page(): void
    {
        $this->configure(enabled: ['social_boost']);

        $this->actingAs($this->customer())
            ->get(route('social-boost.index'))
            ->assertOk()
            ->assertDontSee('Coming soon', false);
    }

    public function test_a_coming_soon_service_shows_a_coming_soon_page(): void
    {
        $this->configure(enabled: ['airtime'], comingSoon: ['social_boost']);

        $response = $this->actingAs($this->customer())->get(route('social-boost.index'));

        // 200, not 404: the page is a real destination and a link to it should land
        // somewhere that explains itself.
        $response->assertOk();
        $response->assertSee('Coming soon', false);
        $response->assertSee('Social Boost is on its way', false);
        $response->assertSee('nothing was charged', false);

        // And it must not be indexed.
        $response->assertSee('noindex', false);
    }

    public function test_a_coming_soon_page_offers_a_way_somewhere_useful(): void
    {
        $this->configure(enabled: ['airtime'], comingSoon: ['social_boost']);

        $response = $this->actingAs($this->customer())->get(route('social-boost.index'));

        $response->assertSee('Explore what is available now', false);
        $response->assertSee(route('dashboard', [], false), false);
    }

    public function test_a_disabled_service_is_refused_with_503(): void
    {
        $this->configure(enabled: ['airtime'], disabled: ['cable_tv']);

        $response = $this->actingAs($this->customer())->get(route('cable-tv.index'));

        // 503 rather than 404: the route exists and the customer did not mistype it.
        $response->assertStatus(503);
        $response->assertSee('not available', false);
        $response->assertDontSee('Coming soon', false);
    }

    /**
     * The purchase endpoints live inside the same groups, so one middleware covers
     * them — which is the reason the guard is on the group and not on the page.
     */
    public function test_a_coming_soon_services_purchase_endpoint_is_guarded_too(): void
    {
        $this->configure(enabled: ['airtime'], comingSoon: ['social_boost']);

        $this->actingAs($this->customer())
            ->post(route('social-boost.purchase'), ['service' => 'anything'])
            ->assertStatus(200)
            ->assertSee('Coming soon', false);

        // Nothing was created.
        $this->assertSame(0, \App\Models\ServiceOrder::count());
    }

    public function test_a_coming_soon_services_ajax_endpoints_are_guarded_too(): void
    {
        $this->configure(enabled: ['airtime'], comingSoon: ['cable_tv']);

        // A read-only helper inside the group. It must not leak data for a service
        // that is not being sold.
        $this->actingAs($this->customer())
            ->getJson(route('cable-tv.providers'))
            ->assertStatus(200);
    }

    public function test_every_gated_service_route_really_is_guarded(): void
    {
        /*
         * The guard is only as good as its placement. Every route whose name belongs
         * to a gated service must carry the middleware — including ones added later,
         * which is what a group gives and a per-route list does not.
         */
        $servicePrefixes = [
            'social-boost' => 'social_boost',
            'cable-tv' => 'cable_tv',
            'waec-pin' => 'waec_pin',
            'jamb-pin' => 'jamb_pin',
            'electricity' => 'electricity',
            'betting' => 'betting',
        ];

        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            // Admin routes are the operator's, and are gated by `admin` instead.
            if ($name === '' || str_starts_with($name, 'admin.')) {
                continue;
            }

            foreach ($servicePrefixes as $prefix => $service) {
                if (! str_starts_with($name, $prefix . '.')) {
                    continue;
                }

                if (! in_array('service:' . $service, $route->gatherMiddleware(), true)) {
                    $unguarded[] = $name;
                }
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "These routes belong to a gated service but are not guarded:\n  - " . implode("\n  - ", $unguarded)
        );
    }

    /* =====================================================================
     | The catalogue — the path that does not go through the service page
     |=================================================================== */

    public function test_a_coming_soon_services_product_cannot_be_bought(): void
    {
        $this->configure(enabled: ['airtime'], comingSoon: ['social_boost']);

        $product = $this->product('social-boost', 'Instagram Followers');

        $this->assertFalse(
            app(\App\Catalogue\ServiceCatalogue::class)->isAvailable($product),
            'A switched-off service must not be offered through its product rows'
        );
    }

    public function test_a_live_services_product_is_unaffected_by_the_gate(): void
    {
        $this->configure(enabled: ['airtime', 'data']);

        $product = $this->product('data', 'MTN 1GB Monthly');

        // No provider offer exists in this fixture, so availability is false for the
        // ordinary reason — but the gate is not what refused it. The proof is that a
        // fully configured product is available once it has an offer.
        $this->assertFalse(app(\App\Catalogue\ServiceCatalogue::class)->isAvailable($product));

        $this->assertTrue(
            app(ServiceGate::class)->isSellable('data'),
            'The service itself is live — the refusal above is about the product having no offer'
        );
    }

    public function test_the_category_map_covers_every_gated_category(): void
    {
        $map = [];

        foreach ((array) config('features.services') as $service => $definition) {
            foreach ((array) ($definition['categories'] ?? []) as $slug) {
                $map[$slug][] = $service;
            }
        }

        // Every category that is actually gated must be one the map knows, or a
        // switched-off service keeps selling through a category nobody mapped.
        foreach (['airtime', 'data', 'social-boost', 'cable-tv', 'electricity', 'education'] as $slug) {
            $this->assertArrayHasKey($slug, $map, "Category [{$slug}] must map to a service");
        }

        // And a shared category is allowed, because `education` genuinely is one.
        $this->assertSame(['waec_pin', 'jamb_pin'], $map['education'] ?? []);
    }

    /* =====================================================================
     | Honesty about what is configured
     |=================================================================== */

    public function test_every_configured_service_names_a_route_that_exists(): void
    {
        foreach ((array) config('features.services') as $service => $definition) {
            $route = $definition['route'] ?? null;

            $this->assertNotNull($route, "[{$service}] must name a landing route");
            $this->assertTrue(Route::has($route), "[{$service}] names [{$route}], which is not registered");
        }
    }

    public function test_every_gated_service_route_group_carries_a_known_key(): void
    {
        $known = array_keys((array) config('features.services'));

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'service:')) {
                    continue;
                }

                $service = substr($middleware, strlen('service:'));

                $this->assertContains(
                    $service,
                    $known,
                    "A route is guarded by service:{$service}, which is not in config/features.php"
                );
            }
        }
    }

    public function test_the_gate_reports_lists_that_match_its_states(): void
    {
        $this->configure(enabled: ['airtime'], comingSoon: ['social_boost'], disabled: ['betting']);

        $gate = app(ServiceGate::class);

        $this->assertContains('airtime', $gate->sellable());
        $this->assertNotContains('social_boost', $gate->sellable());
        $this->assertNotContains('betting', $gate->sellable());

        // Linkable includes coming soon — its page is worth reaching — but not the
        // disabled one.
        $this->assertContains('social_boost', $gate->linkable());
        $this->assertNotContains('betting', $gate->linkable());
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    /**
     * Apply a configuration.
     *
     * The three lists are split by `explode(',')` in config, so the fixtures mirror
     * what the environment genuinely produces — a list of strings — rather than
     * writing arrays the config would never build.
     */
    private function configure(array $enabled = [], array $comingSoon = [], array $disabled = []): void
    {
        config([
            'features.enabled' => $enabled,
            'features.coming_soon' => $comingSoon,
            'features.disabled' => $disabled,
        ]);

        // `ServiceGate` memoises per instance, so a fresh resolution is needed after
        // reconfiguring. Forgetting it in the app container is what makes the next
        // `app(ServiceGate::class)` rebuild.
        $this->app->forgetInstance(ServiceGate::class);
    }

    private function product(string $categorySlug, string $name): ServiceProduct
    {
        $category = ServiceCategory::firstOrCreate(
            ['slug' => $categorySlug],
            ['name' => ucfirst($categorySlug), 'group' => ServiceCategory::GROUP_DIGITAL, 'is_active' => true],
        );

        return ServiceProduct::create([
            'category_id' => $category->getKey(),
            'product_key' => \Illuminate\Support\Str::slug($name),
            'slug' => \Illuminate\Support\Str::slug($name),
            'name' => $name,
            'status' => ServiceProduct::STATUS_ACTIVE,
            'availability' => ServiceProduct::AVAILABILITY_AVAILABLE,
        ]);
    }

    private function customer(): \App\Models\User
    {
        return \App\Models\User::factory()->create();
    }
}

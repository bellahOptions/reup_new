<?php

namespace Tests\Feature;

use App\Models\ProductAlert;
use App\Models\Provider;
use App\Models\ProviderHealthCheck;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Super Admin provider controls.
 *
 * The assertions that matter here are about *authority* and about *not lying*:
 *
 *   * a support or moderator account must not be able to change routing, because
 *     routing decides where a customer's money goes and which provider is asked to
 *     fulfil it;
 *   * two providers must not both be primary for one capability, because that makes
 *     the routing order depend on row ids rather than on a decision;
 *   * resolving an alert must re-arm the monitor rather than permanently silencing a
 *     recurring condition.
 */
class AdminProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'providers.sandbox' => false,
            'providers.http.timeout' => 5,
            'providers.http.probe_timeout' => 5,
            'providers.alerts.failures_before_down' => 3,
        ]);

        /*
         * A probe is deliberately not attempted for a provider with no credential — it
         * would produce a guaranteed authentication error every interval, which looks
         * like an outage and buries the real one. These tests therefore have to
         * configure one before they can observe a probe at all.
         */
        $this->setEnv('SOGO_SECRET_KEY', 'sogo_sk_test_unit');
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

    /**
     * A Super Admin.
     *
     * `is_super_admin` is deliberately left false and the permission list is set
     * explicitly. `User::hasPermission()` short-circuits to true for a super admin, so
     * a test that set the flag would pass whatever `Permissions::SUPER_ADMIN_ONLY`
     * contained — including if `manage_providers` were removed from it. Setting the list
     * is what makes these tests fail when the permission registry changes.
     */
    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $admin->forceFill([
            'is_super_admin' => false,
            'admin_permissions' => Permissions::SUPER_ADMIN_ONLY,
        ])->save();

        return $admin->fresh();
    }

    /** An administrator without any provider permission — the support/moderator case. */
    private function moderator(): User
    {
        $user = User::factory()->create(['is_admin' => true]);

        $user->forceFill([
            'is_super_admin' => false,
            'admin_permissions' => ['view_users'],
        ])->save();

        return $user->fresh();
    }

    private function provider(string $slug, array $capabilities, array $attributes = []): Provider
    {
        return Provider::create(array_merge([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'driver' => 'sogo',
            'capabilities' => $capabilities,
            'is_active' => true,
            'priority' => 100,
            'credential_env_prefix' => 'SOGO',
        ], $attributes));
    }

    /* =====================================================================
     | Authority
     =================================================================== */

    public function test_a_customer_cannot_reach_the_provider_console(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.providers.index'))->assertForbidden();
    }

    public function test_a_non_super_admin_administrator_cannot_change_routing(): void
    {
        $provider = $this->provider('sogo', ['data']);

        // An administrator without `manage_providers`: the support and moderator case.
        $this->actingAs($this->moderator())
            ->put(route('admin.providers.update', $provider), ['priority' => 1])
            ->assertForbidden();

        // The route middleware is the control; the controller re-checks so a route
        // registered without it is not thereby a routing-change endpoint.
        $this->assertSame(100, $provider->fresh()->priority);
    }

    public function test_a_super_admin_can_change_routing(): void
    {
        $provider = $this->provider('sogo', ['data']);

        $this->actingAs($this->admin())
            ->put(route('admin.providers.update', $provider), [
                'is_active' => '1',
                'priority' => 5,
                'low_balance_threshold_minor' => 250000,
            ])
            ->assertRedirect(route('admin.providers.show', $provider));

        $provider->refresh();

        $this->assertSame(5, $provider->priority);
        $this->assertSame(250000, $provider->low_balance_threshold_minor);
        $this->assertTrue($provider->is_active);
    }

    public function test_a_provider_can_be_switched_off_without_being_deleted(): void
    {
        $provider = $this->provider('sogo', ['data']);

        $this->actingAs($this->admin())
            ->put(route('admin.providers.update', $provider), ['is_active' => '0', 'priority' => 100])
            ->assertRedirect();

        $provider->refresh();

        /*
         * Switching off is how a provider is decommissioned: the row stays so that
         * historical orders, cost snapshots and alerts remain resolvable, and the
         * registry simply stops selecting it.
         */
        $this->assertFalse($provider->is_active);
        $this->assertDatabaseHas('providers', ['id' => $provider->getKey()]);
        $this->assertSame([], app(\App\Providers\ProviderRegistry::class)->candidatesFor('data'));
    }

    /* =====================================================================
     | Routing integrity
     =================================================================== */

    public function test_two_providers_cannot_both_be_primary_for_the_same_capability(): void
    {
        $existing = $this->provider('sogo', ['data'], ['is_primary' => true, 'priority' => 10]);
        $second = $this->provider('sogo-two', ['data'], ['priority' => 20]);

        $this->actingAs($this->admin())
            ->put(route('admin.providers.update', $second), ['is_primary' => '1', 'priority' => 20])
            ->assertSessionHasErrors('is_primary');

        /*
         * `is_primary` is a tie-breaker inside the priority ordering. Two primaries
         * would make the winner depend on the database's row order, which is to say on
         * nothing anybody chose.
         */
        $this->assertFalse($second->fresh()->is_primary);
        $this->assertTrue($existing->fresh()->is_primary);
    }

    public function test_two_providers_may_both_be_primary_for_different_capabilities(): void
    {
        $this->provider('sogo', ['data'], ['is_primary' => true]);
        $gift = $this->provider('sogo-gift', ['gift_cards']);

        $this->actingAs($this->admin())
            ->put(route('admin.providers.update', $gift), ['is_primary' => '1'])
            ->assertRedirect();

        $this->assertTrue($gift->fresh()->is_primary);
    }

    public function test_a_manual_check_probes_the_provider_and_reports_the_result(): void
    {
        Http::fake(['*' => Http::response(['data' => ['balance' => 500000, 'currency' => 'NGN']], 200)]);

        $provider = $this->provider('sogo', ['data']);

        $this->actingAs($this->admin())
            ->post(route('admin.providers.check', $provider))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, ProviderHealthCheck::count());
        $this->assertSame(Provider::HEALTH_HEALTHY, $provider->fresh()->health_status);
    }

    /* =====================================================================
     | Alerts
     =================================================================== */

    public function test_resolving_an_alert_allows_the_condition_to_be_raised_again(): void
    {
        Http::fake(['*' => Http::response(['data' => ['balance' => 100, 'currency' => 'NGN']], 200)]);

        $provider = $this->provider('sogo', ['data'], ['low_balance_threshold_minor' => 100000]);

        $monitor = app(\App\Providers\ProviderMonitor::class);
        $monitor->probe($provider);

        $alert = ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_LOW_BALANCE)->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('admin.providers.alerts.resolve', $alert))
            ->assertRedirect();

        $this->assertNotNull($alert->fresh()->resolved_at);

        /*
         * The database enforces one *open* alert per condition, not one alert ever. If
         * resolution were permanent, a provider that ran low on float once could never
         * alert on it again — and the dashboard would look healthy while it happened.
         */
        $monitor->probe($provider);

        $this->assertSame(2, ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_LOW_BALANCE)->count());
        $this->assertSame(1, ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_LOW_BALANCE)->whereNull('resolved_at')->count());
    }

    public function test_acknowledging_an_alert_does_not_resolve_it(): void
    {
        Http::fake(['*' => Http::response(['data' => ['balance' => 100, 'currency' => 'NGN']], 200)]);

        $provider = $this->provider('sogo', ['data'], ['low_balance_threshold_minor' => 100000]);

        app(\App\Providers\ProviderMonitor::class)->probe($provider);

        $alert = ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_LOW_BALANCE)->firstOrFail();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.providers.alerts.acknowledge', $alert))
            ->assertRedirect();

        $alert->refresh();

        $this->assertNotNull($alert->acknowledged_at);
        $this->assertSame($admin->getKey(), $alert->acknowledged_by);

        // Acknowledged means "I have seen this", not "this is fixed": the condition is
        // still open, and re-probing must not create a sibling alert for it.
        $this->assertNull($alert->resolved_at);
        $this->assertTrue($alert->isOpen());

        app(\App\Providers\ProviderMonitor::class)->probe($provider);

        $this->assertSame(1, ProductAlert::where('type', ProductAlert::TYPE_PROVIDER_LOW_BALANCE)->count());
    }

    public function test_a_regular_administrator_cannot_acknowledge_alerts(): void
    {
        $provider = $this->provider('sogo', ['data']);

        $alert = ProductAlert::create([
            'kind' => ProductAlert::KIND_ALERT,
            'provider_id' => $provider->getKey(),
            'type' => ProductAlert::TYPE_PROVIDER_LOW_BALANCE,
            'severity' => ProductAlert::SEVERITY_CRITICAL,
            'title' => 'Low float',
            'message' => 'Top up.',
        ]);

        $this->actingAs($this->moderator())
            ->post(route('admin.providers.alerts.resolve', $alert))
            ->assertForbidden();

        $this->assertNull($alert->fresh()->resolved_at);
    }
}

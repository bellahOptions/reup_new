<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\PricingRuleVersion;
use App\Models\Provider;
use App\Models\ServiceCategory;
use App\Models\ServiceProduct;
use App\Models\User;
use App\Pricing\PricingEngine;
use App\Pricing\PricingRuleService;
use App\Support\Money;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Super Admin pricing controls: authorisation, versioning, audit, preview and the
 * bulk path.
 *
 * The two things that must hold:
 *
 *   * a normal administrator cannot see or change pricing, because cost and
 *     margin are commercially sensitive and pricing is a financial control;
 *   * every change is versioned and attributed, so "what was the markup when this
 *     order was priced" is answerable.
 */
class AdminPricingTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function plainAdmin(): User
    {
        // An `admin` role without any of the commercial permissions.
        return User::factory()->admin([
            'view_dashboard', 'view_users', 'view_transactions',
        ])->create();
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    private function globalRule(array $attributes = []): PricingRule
    {
        return PricingRule::create(array_merge([
            'name' => 'Global default',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 2000,
            'is_active' => true,
            'priority' => 100,
        ], $attributes));
    }

    /* =====================================================================
     | Authorisation
     |=================================================================== */

    public function test_a_super_admin_can_open_the_pricing_console(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.pricing.index'))
            ->assertOk();
    }

    public function test_a_plain_administrator_cannot_open_the_pricing_console(): void
    {
        /*
         * `view_pricing` is in Permissions::SUPER_ADMIN_ONLY and is absent from
         * the `admin` role defaults, so an existing administrator does not
         * inherit it. This is the control the brief asks for: normal
         * administrators must not automatically gain pricing or profit access.
         */
        $this->actingAs($this->plainAdmin())
            ->get(route('admin.pricing.index'))
            ->assertForbidden();
    }

    public function test_a_plain_administrator_cannot_see_profit_analytics(): void
    {
        $this->actingAs($this->plainAdmin())
            ->get(route('admin.profit.index'))
            ->assertForbidden();
    }

    public function test_a_customer_cannot_reach_the_pricing_console(): void
    {
        $this->actingAs($this->customer())
            ->get(route('admin.pricing.index'))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_from_the_pricing_console(): void
    {
        $this->get(route('admin.pricing.index'))->assertRedirect();
    }

    public function test_a_plain_administrator_cannot_create_a_pricing_rule(): void
    {
        $this->actingAs($this->plainAdmin())
            ->post(route('admin.pricing.store'), [
                'name' => 'Sneaky markup',
                'scope' => 'global',
                'markup_type' => 'percentage',
                'markup_percentage' => 99,
                'rounding_mode' => 'nearest',
                'on_unprofitable' => 'unavailable',
            ])
            ->assertForbidden();

        $this->assertSame(0, PricingRule::count());
    }

    public function test_a_plain_administrator_cannot_change_an_existing_rule(): void
    {
        $rule = $this->globalRule();

        $this->actingAs($this->plainAdmin())
            ->put(route('admin.pricing.update', $rule), [
                'name' => $rule->name,
                'scope' => 'global',
                'markup_type' => 'percentage',
                'markup_percentage' => 500,
                'rounding_mode' => 'nearest',
                'on_unprofitable' => 'unavailable',
            ])
            ->assertForbidden();

        $this->assertSame(2000, $rule->fresh()->markup_percentage_bps);
    }

    public function test_a_plain_administrator_cannot_delete_a_rule(): void
    {
        $rule = $this->globalRule();

        $this->actingAs($this->plainAdmin())
            ->delete(route('admin.pricing.destroy', $rule))
            ->assertForbidden();

        $this->assertDatabaseHas('pricing_rules', ['id' => $rule->getKey()]);
    }

    public function test_the_commercial_permissions_are_not_granted_to_the_admin_role_by_default(): void
    {
        foreach (Permissions::SUPER_ADMIN_ONLY as $permission) {
            $this->assertNotContains(
                $permission,
                Permissions::defaultsForRole('admin'),
                "[{$permission}] must not be a default for the admin role."
            );
        }
    }

    /* =====================================================================
     | Versioning and audit
     | =================================================================== */

    public function test_creating_a_rule_writes_its_first_version(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.pricing.store'), [
            'name' => 'SMM default',
            'scope' => 'global',
            'markup_type' => 'percentage',
            'markup_percentage' => 20,
            'rounding_mode' => 'nearest',
            'on_unprofitable' => 'unavailable',
            'reason' => 'Initial margin policy',
        ])->assertRedirect(route('admin.pricing.rules'));

        $rule = PricingRule::where('name', 'SMM default')->firstOrFail();

        $this->assertSame(2000, $rule->markup_percentage_bps);
        $this->assertSame(1, PricingRuleVersion::where('pricing_rule_id', $rule->getKey())->count());

        // The privileged action is audited.
        $this->assertDatabaseHas('admin_logs', [
            'user_id' => $admin->getKey(),
            'action' => 'pricing_rule_created',
        ]);
    }

    public function test_changing_a_rule_records_the_previous_state_and_the_diff(): void
    {
        /*
         * The rule is created through the service, because that is what writes
         * version 1. A rule created by a seeder or a direct `create()` has no
         * earlier state to record, and the first edit produces version 1
         * describing the state *before* that edit.
         */
        $admin = $this->superAdmin();

        $rule = app(PricingRuleService::class)->create([
            'name' => 'Global default',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 2000,
            'is_active' => true,
            'priority' => 100,
        ], $admin, 'Initial rule');

        $this->actingAs($admin)->put(route('admin.pricing.update', $rule), [
            'name' => $rule->name,
            'scope' => 'global',
            'markup_type' => 'percentage',
            'markup_percentage' => 30,
            'rounding_mode' => 'nearest',
            'on_unprofitable' => 'unavailable',
            'reason' => 'Provider raised its rates',
        ])->assertRedirect(route('admin.pricing.rules'));

        $rule->refresh();

        $this->assertSame(3000, $rule->markup_percentage_bps);
        $this->assertSame($admin->getKey(), $rule->updated_by);

        $version = PricingRuleVersion::where('pricing_rule_id', $rule->getKey())
            ->orderByDesc('version')
            ->firstOrFail();

        $this->assertSame(2, $version->version);
        $this->assertSame('Provider raised its rates', $version->reason);
        $this->assertSame($admin->getKey(), $version->changed_by);

        // The diff names the field and both values.
        $changes = $version->changes;
        $this->assertArrayHasKey('markup_percentage_bps', $changes);
        $this->assertSame(2000, (int) $changes['markup_percentage_bps']['from']);
        $this->assertSame(3000, (int) $changes['markup_percentage_bps']['to']);

        // The version row keeps the state as it was *before* the change, so the
        // history is interpretable without reconstructing it from the diffs.
        $this->assertSame(2000, (int) $version->snapshot['markup_percentage_bps']);

        // And the human-readable summary says so.
        $summary = implode(' | ', $version->changeSummary());
        $this->assertStringContainsString('Markup %', $summary);
        $this->assertStringContainsString('20.00%', $summary);
        $this->assertStringContainsString('30.00%', $summary);
    }

    public function test_a_change_that_changes_nothing_writes_no_version(): void
    {
        $admin = $this->superAdmin();

        $rule = app(PricingRuleService::class)->create([
            'name' => 'Global default',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 2000,
            'is_active' => true,
        ], $admin, 'Initial rule');

        $this->actingAs($admin)->put(route('admin.pricing.update', $rule), [
            'name' => $rule->name,
            'scope' => 'global',
            'markup_type' => 'percentage',
            'markup_percentage' => 20, // unchanged
            'rounding_mode' => 'nearest',
            'on_unprofitable' => 'unavailable',
        ])->assertRedirect();

        // One version, from creation. A no-op must not imply a change happened.
        $this->assertSame(1, PricingRuleVersion::where('pricing_rule_id', $rule->getKey())->count());
    }

    public function test_deactivating_a_rule_is_audited_as_a_deactivation(): void
    {
        $rule = $this->globalRule();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.pricing.toggle', $rule))
            ->assertRedirect();

        $this->assertFalse($rule->fresh()->is_active);

        $version = PricingRuleVersion::where('pricing_rule_id', $rule->getKey())
            ->orderByDesc('version')
            ->firstOrFail();

        $this->assertSame('Rule deactivated', $version->reason);
    }

    public function test_a_rule_that_is_another_rules_fallback_cannot_be_deleted(): void
    {
        $fallback = $this->globalRule(['name' => 'Fallback']);
        $primary = $this->globalRule([
            'name' => 'Primary',
            'fallback_rule_id' => $fallback->getKey(),
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_FALLBACK,
        ]);

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.pricing.destroy', $fallback))
            ->assertSessionHas('error');

        // Deleting it would leave the primary rule pointing at nothing, and the
        // failure would only surface when a customer tried to buy.
        $this->assertDatabaseHas('pricing_rules', ['id' => $fallback->getKey()]);
    }

    /* =====================================================================
     | Rule validation
     | =================================================================== */

    public function test_a_scoped_rule_without_its_subject_is_refused(): void
    {
        $service = app(PricingRuleService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must name its subject');

        $service->create([
            'name' => 'Broken product rule',
            'scope' => PricingRule::SCOPE_PRODUCT,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 2000,
        ], $this->superAdmin());
    }

    public function test_a_rule_naming_two_subjects_is_refused(): void
    {
        $category = ServiceCategory::create(['group' => 'digital', 'slug' => 'digital', 'name' => 'Digital']);
        $provider = Provider::create(['slug' => 'p1', 'name' => 'P1', 'driver' => 'sogo']);

        $service = app(PricingRuleService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ambiguous');

        $service->create([
            'name' => 'Ambiguous',
            'scope' => PricingRule::SCOPE_CATEGORY,
            'category_id' => $category->id,
            'provider_id' => $provider->id,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 2000,
        ], $this->superAdmin());
    }

    public function test_a_fallback_loop_is_refused(): void
    {
        $a = $this->globalRule(['name' => 'A']);
        $b = $this->globalRule(['name' => 'B', 'fallback_rule_id' => $a->getKey()]);

        // A → B → A would recurse until the request died.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('loop');

        app(PricingRuleService::class)->update($a, ['fallback_rule_id' => $b->getKey()], $this->superAdmin());
    }

    public function test_a_percentage_entered_as_basis_points_beyond_a_thousand_percent_is_refused(): void
    {
        $rule = $this->globalRule();

        /*
         * 200000 bps is 2000% — a value that can only be a decimal entered where
         * basis points were expected, or a stray zero. The guard lives in
         * `assertNumericFieldsAreIntegers`, which the create and update paths
         * share, so it applies however the rule is written.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Basis points');

        app(PricingRuleService::class)->update(
            $rule,
            ['markup_percentage_bps' => 200000],
            $this->superAdmin(),
        );
    }

    /* =====================================================================
     | Preview
     | =================================================================== */

    public function test_the_preview_shows_current_and_proposed_figures(): void
    {
        $rule = $this->globalRule(['markup_percentage_bps' => 2000]);

        $response = $this->actingAs($this->superAdmin())->postJson(route('admin.pricing.preview'), [
            'rule_id' => $rule->getKey(),
            // ₦1,000 sample cost.
            'sample_cost' => 1000,
            'quantity' => 1,
            'changes' => ['markup_percentage_bps' => 2500],
        ]);

        $response->assertOk();

        /*
         * The brief's worked example: ₦1,000 cost, current ₦1,200 price and ₦200
         * profit; proposed ₦1,250 price and ₦250 profit.
         */
        $response->assertJsonPath('current.price_minor', 120000);
        $response->assertJsonPath('current.profit_minor', 20000);
        $response->assertJsonPath('proposed.price_minor', 125000);
        $response->assertJsonPath('proposed.profit_minor', 25000);

        // Margins, not markups: 200/1200 = 16.67%, 250/1250 = 20%.
        $response->assertJsonPath('current.margin_bps', 1667);
        $response->assertJsonPath('proposed.margin_bps', 2000);
    }

    public function test_a_significant_price_change_requires_confirmation(): void
    {
        $rule = $this->globalRule(['markup_percentage_bps' => 2000]);

        // Doubling the markup is well beyond a 5% price movement.
        $response = $this->actingAs($this->superAdmin())->postJson(route('admin.pricing.preview'), [
            'rule_id' => $rule->getKey(),
            'sample_cost' => 1000,
            'changes' => ['markup_percentage_bps' => 4000],
        ]);

        $response->assertOk()->assertJsonPath('requires_confirmation', true);
    }

    public function test_a_small_price_change_does_not_require_confirmation(): void
    {
        $rule = $this->globalRule(['markup_percentage_bps' => 2000]);

        // 20% → 20.5% is a 0.4% price movement.
        $response = $this->actingAs($this->superAdmin())->postJson(route('admin.pricing.preview'), [
            'rule_id' => $rule->getKey(),
            'sample_cost' => 1000,
            'changes' => ['markup_percentage_bps' => 2050],
        ]);

        $response->assertOk()->assertJsonPath('requires_confirmation', false);
    }

    public function test_the_preview_flags_a_change_that_would_make_a_service_unsellable(): void
    {
        $rule = $this->globalRule([
            'markup_percentage_bps' => 2000,
            'minimum_margin_bps' => 1500,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
        ]);

        // Dropping the markup to 1% breaches the 15% minimum margin.
        $response = $this->actingAs($this->superAdmin())->postJson(route('admin.pricing.preview'), [
            'rule_id' => $rule->getKey(),
            'sample_cost' => 1000,
            'changes' => ['markup_percentage_bps' => 100],
        ]);

        $response->assertOk()
            ->assertJsonPath('proposed.sellable', false)
            ->assertJsonPath('would_become_unsellable', true);
    }

    public function test_a_plain_administrator_cannot_use_the_preview(): void
    {
        $rule = $this->globalRule();

        // The preview exposes provider cost and margin, so it is gated too.
        $this->actingAs($this->plainAdmin())->postJson(route('admin.pricing.preview'), [
            'rule_id' => $rule->getKey(),
            'sample_cost' => 1000,
            'changes' => ['markup_percentage_bps' => 3000],
        ])->assertForbidden();
    }

    /* =====================================================================
     | Bulk
     | =================================================================== */

    public function test_a_bulk_update_versions_and_audits_every_affected_rule(): void
    {
        $category = ServiceCategory::create(['group' => 'social', 'slug' => 'social', 'name' => 'Social']);

        $first = PricingRule::create([
            'name' => 'Instagram', 'scope' => PricingRule::SCOPE_CATEGORY,
            'category_id' => $category->id,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE, 'markup_percentage_bps' => 2000,
            'is_active' => true,
        ]);

        $second = PricingRule::create([
            'name' => 'TikTok', 'scope' => PricingRule::SCOPE_CATEGORY,
            'category_id' => $category->id,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE, 'markup_percentage_bps' => 2000,
            'is_active' => true,
        ]);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.pricing.bulk.update'), [
            'scope' => 'category',
            'category_id' => $category->id,
            'markup_percentage' => 25,
            'reason' => 'Social category margin review',
        ])->assertRedirect(route('admin.pricing.rules'));

        $this->assertSame(2500, $first->fresh()->markup_percentage_bps);
        $this->assertSame(2500, $second->fresh()->markup_percentage_bps);

        // Each rule gets its own version — a bulk edit is N decisions, not one.
        foreach ([$first, $second] as $rule) {
            $version = PricingRuleVersion::where('pricing_rule_id', $rule->getKey())
                ->orderByDesc('version')
                ->first();

            $this->assertNotNull($version);
            $this->assertSame('Social category margin review', $version->reason);
        }
    }

    /* =====================================================================
     | History screen
     | =================================================================== */

    public function test_the_history_screen_renders_the_version_diff(): void
    {
        $rule = $this->globalRule();
        $admin = $this->superAdmin();

        app(PricingRuleService::class)->update(
            $rule,
            ['markup_percentage_bps' => 3000],
            $admin,
            'Rate increase',
        );

        $this->actingAs($admin)
            ->get(route('admin.pricing.versions', $rule))
            ->assertOk()
            ->assertSee('Markup %', false)
            ->assertSee('Rate increase', false);
    }

    /* =====================================================================
     | Profit dashboard
     | =================================================================== */

    public function test_the_profit_dashboard_renders_for_a_super_admin(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.profit.index'))
            ->assertOk()
            ->assertSee('Gross profit', false);
    }

    public function test_the_profit_dashboard_counts_nothing_when_there_are_no_orders(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.profit.index'))
            ->assertOk();

        // Zero rather than an error, and explicitly zero rather than absent.
        $this->assertSame(0, \App\Models\ProfitRecord::realized()->count());
    }

    public function test_the_profit_data_endpoint_returns_server_computed_figures(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson(route('admin.profit.data'))
            ->assertOk()
            ->assertJsonPath('summary.transactions', 0)
            ->assertJsonPath('summary.gross_profit_minor', 0);
    }

    public function test_the_margin_watchlist_renders(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.profit.watchlist'))
            ->assertOk();
    }

    /* =====================================================================
     | Money handling in the console
     | =================================================================== */

    public function test_a_naira_amount_entered_in_the_form_is_stored_in_kobo(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.pricing.store'), [
            'name' => 'Fixed fee rule',
            'scope' => 'global',
            'markup_type' => 'fixed',
            'markup_fixed' => '1500.50',
            'minimum_profit' => '100.25',
            'rounding_step' => '10',
            'rounding_mode' => 'nearest',
            'on_unprofitable' => 'unavailable',
        ])->assertRedirect();

        $rule = PricingRule::where('name', 'Fixed fee rule')->firstOrFail();

        // ₦1,500.50 is exactly 150050 kobo; no floating-point residue.
        $this->assertSame(150050, (int) $rule->markup_fixed_minor);
        $this->assertSame(10025, (int) $rule->minimum_profit_minor);
        $this->assertSame(1000, (int) $rule->rounding_step_minor);
    }

    public function test_a_percentage_entered_as_a_decimal_becomes_exact_basis_points(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.pricing.store'), [
            'name' => 'Decimal markup',
            'scope' => 'global',
            'markup_type' => 'percentage',
            // 1.5% must become exactly 150 bps, not 149 or 151.
            'markup_percentage' => '1.5',
            'rounding_mode' => 'nearest',
            'on_unprofitable' => 'unavailable',
        ])->assertRedirect();

        $this->assertSame(150, (int) PricingRule::where('name', 'Decimal markup')->firstOrFail()->markup_percentage_bps);
    }
}

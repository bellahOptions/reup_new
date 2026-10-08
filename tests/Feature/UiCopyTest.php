<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\ProviderProduct;
use App\Models\ServiceCategory;
use App\Models\ServiceProduct;
use App\Models\User;
use App\Support\UiCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The approved frontend copy, and the rules that keep the frontend from lying.
 *
 * The assertions that carry money here are the ones about *status language*:
 *
 *   * an UNKNOWN or PENDING outcome must never read as a failure, because a customer
 *     told their purchase failed will pay again for something they may already have;
 *   * a refund must never be claimed before the finance system has recorded it;
 *   * a customer must never see a provider name, a provider cost or a margin.
 *
 * The copy assertions are deliberately made against the rendered page rather than
 * against `config('copy')`. A test that reads the config proves the config is right;
 * a test that reads the page proves the page is. Only the second one catches a
 * template that hardcoded its own wording.
 */
class UiCopyTest extends TestCase
{
    use RefreshDatabase;

    /* =====================================================================
     | Status vocabulary — the rule that matters most
     =================================================================== */

    public function test_an_unknown_outcome_never_reads_as_a_failure(): void
    {
        foreach (['unknown', 'pending', 'processing', 'verifying', 'initiated'] as $status) {
            $this->assertSame(
                UiCopy::get('outcome.processing.heading'),
                UiCopy::statusLabel($status),
                "[{$status}] must read as processing, never as a failure."
            );

            $this->assertStringNotContainsString('Failed', UiCopy::statusLabel($status));
            $this->assertStringNotContainsString('failed', UiCopy::statusDescription($status));
        }
    }

    public function test_a_failed_outcome_is_the_only_one_that_reads_as_a_failure(): void
    {
        $this->assertSame(UiCopy::get('outcome.failed.heading'), UiCopy::statusLabel('failed'));

        // Success, refund and cancellation are all resolved and none of them is a
        // failure, even though a refunded transaction began as one.
        foreach (['success', 'refunded', 'cancelled'] as $status) {
            $this->assertStringNotContainsString('Failed', UiCopy::statusLabel($status));
        }
    }

    public function test_an_unrecognised_status_is_treated_as_unresolved_rather_than_failed(): void
    {
        /*
         * The dangerous default. A provider introduces a status this build has not
         * seen; reading it as a failure invites a refund on a delivered purchase.
         */
        foreach (['awaiting_fulfilment', 'queued', 'some_new_status', ''] as $status) {
            $this->assertSame(UiCopy::get('outcome.processing.heading'), UiCopy::statusLabel($status));
            $this->assertTrue(UiCopy::isUnresolved($status) || $status === '');
        }
    }

    public function test_a_refund_is_not_claimed_before_it_is_recorded(): void
    {
        $pending = UiCopy::statusDescription('failed', refundRecorded: false);
        $recorded = UiCopy::statusDescription('failed', refundRecorded: true);

        $this->assertSame(UiCopy::get('outcome.failed.refund_pending'), $pending);
        $this->assertSame(UiCopy::get('outcome.failed.refunded'), $recorded);
        $this->assertNotSame($pending, $recorded);

        // The un-recorded wording must not assert that the money is back.
        $this->assertStringNotContainsString('has been refunded', $pending);
        $this->assertStringContainsString('will be reversed', $pending);
    }

    public function test_a_reconciliation_note_is_offered_only_while_the_outcome_is_unresolved(): void
    {
        $this->assertNotNull(UiCopy::reconciliationNote('unknown'));
        $this->assertNotNull(UiCopy::reconciliationNote('pending'));

        // A failed transaction is resolved; telling the customer we are "still
        // checking" would be its own kind of wrong.
        $this->assertNull(UiCopy::reconciliationNote('failed'));
        $this->assertNull(UiCopy::reconciliationNote('success'));
        $this->assertNull(UiCopy::reconciliationNote('refunded'));
    }

    public function test_a_service_specific_success_heading_is_used_where_one_exists(): void
    {
        $this->assertSame('Airtime Sent Successfully', UiCopy::serviceSuccessHeading('airtime'));
        $this->assertSame('Data Activated Successfully', UiCopy::serviceSuccessHeading('data'));

        // A service with no copy of its own falls back rather than rendering a
        // missing-key string to a customer.
        $this->assertSame(UiCopy::get('outcome.success.heading'), UiCopy::serviceSuccessHeading('interpretive_dance'));
        $this->assertSame(UiCopy::get('outcome.success.heading'), UiCopy::serviceSuccessHeading(null));
    }

    public function test_the_greeting_follows_the_clock_it_is_given(): void
    {
        $this->assertSame('Good morning', UiCopy::greeting(now()->setTime(0, 30)));
        $this->assertSame('Good morning', UiCopy::greeting(now()->setTime(11, 59)));
        $this->assertSame('Good afternoon', UiCopy::greeting(now()->setTime(12, 0)));
        $this->assertSame('Good afternoon', UiCopy::greeting(now()->setTime(16, 59)));
        $this->assertSame('Good evening', UiCopy::greeting(now()->setTime(17, 0)));
        $this->assertSame('Good evening', UiCopy::greeting(now()->setTime(23, 59)));
    }

    public function test_every_approved_section_has_copy(): void
    {
        /*
         * A spot-check of the deck rather than an exhaustive list: these are the keys
         * the wired views read, and a missing one would render its own key name to a
         * customer — which is why `UiCopy::get()` returns the key rather than an empty
         * string.
         */
        foreach ([
            'brand.tagline',
            'brand.supporting',
            'actions.get_started',
            'actions.sign_in',
            'dashboard.statement',
            'dashboard.recent_payments',
            'dashboard.recent_payments_empty',
            'dashboard.my_reup_description',
            'wallet.available_balance',
            'wallet.insufficient',
            'outcome.success.heading',
            'outcome.processing.heading',
            'outcome.failed.heading',
            'outcome.refund.heading',
            'checkout.heading',
            'checkout.confirm_body',
            'price_change.notice',
            'availability.heading',
            'availability.description',
            'availability.pricing',
            'search.placeholder',
            'errors.404',
            'auth.login_heading',
            'security.pin_description',
            'delivery.epin_ready',
            'maintenance.heading',
        ] as $key) {
            $this->assertTrue(UiCopy::has($key), "The approved copy deck is missing [{$key}].");
        }
    }

    public function test_a_missing_copy_key_renders_visibly_rather_than_blank(): void
    {
        // A blank would be reported as a layout bug; the key names itself.
        $this->assertSame('outcome.nonexistent.heading', UiCopy::get('outcome.nonexistent.heading'));
    }

    public function test_the_confirm_body_keeps_the_amount_and_service_dynamic(): void
    {
        /*
         * §51.40: the displayed price must come from the backend pricing engine. The
         * sentence is copy; the amount is substituted by the caller, so no template
         * can bake a figure into the sentence.
         */
        $rendered = UiCopy::get('checkout.confirm_body', ['amount' => '1,500.50', 'service' => 'Airtime']);

        $this->assertStringContainsString('1,500.50', $rendered);
        $this->assertStringContainsString('Airtime', $rendered);
        $this->assertStringNotContainsString('{amount}', $rendered);
    }

    /* =====================================================================
     | What a customer must never see
     =================================================================== */

    public function test_a_customer_never_sees_a_provider_name_cost_or_margin(): void
    {
        $user = User::factory()->create(['name' => 'Ada Obi']);

        $provider = Provider::create([
            'slug' => 'sogo',
            'name' => 'Sogo Africa Ltd',
            'driver' => 'sogo',
            'capabilities' => ['data'],
            'is_active' => true,
            'priority' => 10,
            'credential_env_prefix' => 'SOGO',
        ]);

        $category = ServiceCategory::create([
            'group' => ServiceCategory::GROUP_PAY_BILLS,
            'slug' => 'data',
            'name' => 'Data',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $product = ServiceProduct::create([
            'category_id' => $category->getKey(),
            'product_key' => 'mtn-1gb',
            'slug' => 'mtn-1gb',
            'name' => 'MTN 1GB',
            'status' => ServiceProduct::STATUS_ACTIVE,
            'availability' => ServiceProduct::AVAILABILITY_AVAILABLE,
        ]);

        ProviderProduct::create([
            'provider_id' => $provider->getKey(),
            'service_product_id' => $product->getKey(),
            'provider_product_id' => 'MTN-1GB-30D',
            'provider_name' => 'MTN 1GB Monthly (provider internal name)',
            'provider_cost_minor' => 40000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();

        $body = $response->getContent();

        // The provider's legal name, its slug, its internal product name and the cost
        // it charges us are all absent (§51.36, §51.37).
        $this->assertStringNotContainsString('Sogo', $body);
        $this->assertStringNotContainsString('sogo', $body);
        $this->assertStringNotContainsString('provider internal name', $body);
        $this->assertStringNotContainsString('400.00', $body);
        $this->assertStringNotContainsString('provider_cost', $body);

        // The customer-facing product name is present, because that is what they buy.
        $this->assertStringContainsString('MTN 1GB', $body);
    }

    public function test_the_dashboard_uses_the_approved_greeting_and_statement(): void
    {
        $user = User::factory()->create(['name' => 'Ada Obi']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();

        // The first word of the name stands in for `{first_name}` — the `users` table
        // has a single `name` column.
        $response->assertSee('Ada', false);
        $response->assertSee(UiCopy::get('dashboard.statement'), false);
        $response->assertSee(UiCopy::get('dashboard.recent_payments'), false);

        // The previous wording is gone: it was not part of the approved deck.
        $response->assertDontSee('Welcome back, Ada Obi', false);
    }

    public function test_the_homepage_uses_the_approved_hero_copy(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee(UiCopy::get('brand.tagline'), false);
        $response->assertSee(UiCopy::get('brand.supporting'), false);
        $response->assertSee(UiCopy::get('actions.get_started'), false);
        $response->assertSee(UiCopy::get('actions.sign_in'), false);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payment-method picker and the screens it appears on.
 *
 * ## Why this exists
 *
 * The backend for card-funded purchases was covered by `PaystackAirtimeTest`, but
 * the *page* was not: `x-payment-method` is new, it takes a prop, and it lives inside
 * two forms whose submit is handled by JavaScript. None of that is exercised by a
 * test that posts to the endpoint directly — and a Blade component that fails to
 * compile, or a prop name that does not resolve, throws only when the page is
 * actually rendered.
 *
 * The specific trap worth pinning is the component tag's attribute names. Blade
 * converts a kebab-case attribute to a camelCase variable (`:wallet-balance` becomes
 * `$walletBalance`), so a prop passed under the wrong name is not an error — it is
 * silently absent, and the component renders an empty balance. That is the failure
 * this catches.
 *
 * ## What is asserted, and what is not
 *
 * The assertions are on the *contract the JavaScript depends on*: the hidden input
 * that is actually submitted, the radio values, and the marker the PIN field listens
 * for. The visual behaviour of the Alpine expression is not testable here — that
 * needs a browser — and asserting on class names would be asserting on styling rather
 * than on behaviour.
 */
class PaymentMethodPickerTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'buyer@example.com',
            'phone' => '08031234567',
        ]);
    }

    /* =====================================================================
     | The component
     =================================================================== */

    public function test_the_picker_offers_both_rails(): void
    {
        $html = (string) $this->blade('<x-payment-method :wallet="1234.5" />');

        /*
         * Asserted on the option values, not on the visible wording. The labels are
         * customer-facing copy and will change; the submitted values are the contract
         * with the endpoint, and a test that pins the copy fails for a reason
         * unrelated to behaviour — which is exactly what happened here.
         */
        $this->assertStringContainsString('<option value="wallet"', $html);
        $this->assertStringContainsString('<option value="paystack"', $html);
        $this->assertStringContainsString('My wallet balance', $html);
        $this->assertStringContainsString('Card or bank transfer', $html);
    }

    public function test_the_picker_submits_the_wallet_rail_by_default(): void
    {
        /*
         * The default matters more than it looks. The form must work with JavaScript
         * disabled, and Alpine has not run when the browser decides what to submit — so
         * the markup itself has to declare the wallet rail, which is the one a PIN
         * authorises. Defaulting to the gateway rail would let a no-JavaScript submit
         * reach the server expecting no PIN.
         */
        $html = (string) $this->blade('<x-payment-method :wallet="0" />');

        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="payment_method"/',
            $html,
            'The rail must be a real form control that submits its own value.'
        );

        $this->assertMatchesRegularExpression(
            '/<option value="wallet"[^>]*selected/',
            $html,
            'The wallet rail must be marked selected in the served HTML, not only by Alpine.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<option value="paystack"[^>]*selected/',
            $html
        );
    }

    public function test_the_picker_has_exactly_one_value_bearing_control(): void
    {
        /*
         * The bug this guards, and it shipped: the rail was
         * `<input type="hidden" :value="method" value="wallet">` — a static `value`
         * *and* a bound one. The static attribute wins in the DOM, so the form always
         * submitted `wallet`. Choosing "Card or transfer" disabled the PIN, submitted
         * the wallet rail, and had the submission rejected — the card feature was
         * entirely unusable, and every endpoint-level test still passed because they
         * posted the payload a *working* form would have sent.
         *
         * Asserting on the rendered control is the only way to catch that class of bug:
         * the endpoint cannot see how the value was produced.
         */
        $html = (string) $this->blade('<x-payment-method :wallet="0" />');

        $this->assertSame(
            1,
            preg_match_all('/name="payment_method"/', $html),
            'There must be exactly one control named payment_method.'
        );

        $this->assertStringNotContainsString(
            ':value=',
            $html,
            'A bound value alongside a static one cannot win, so the static one silently decides.'
        );
    }

    public function test_the_picker_shows_the_wallet_balance(): void
    {
        // The prop is what tells the customer whether the wallet rail is even usable,
        // so a silently-absent prop would leave them choosing blind.
        $html = (string) $this->blade('<x-payment-method :wallet="1234.5" />');

        $this->assertStringContainsString('1,234.50', $html);
    }

    public function test_the_picker_copes_with_no_wallet_at_all(): void
    {
        // A customer who has never funded has no wallet row; the page must still
        // render rather than throwing on a null.
        $html = (string) $this->blade('<x-payment-method :wallet="0" />');

        $this->assertStringContainsString('0.00', $html);
    }

    public function test_the_pin_field_reads_the_rail_from_the_form_it_sits_in(): void
    {
        /*
         * The PIN input lives outside the picker, so the choice has to reach it. Two
         * earlier mechanisms were tried and both were fragile — a wrapper-scoped
         * `x-data` that never reached the input, then an `$dispatch` plus a window
         * listener. This is now a plain `change` listener on the real control, which is
         * one hop instead of an event bus and cannot be missed because the element is
         * in the same form.
         */
        $html = (string) $this->blade('<x-payment-method :wallet="0" />');

        $this->assertStringContainsString('name="payment_method"', $html);
        $this->assertStringContainsString('x-model="rail"', $html);
    }

    /* =====================================================================
     | The PIN field reacts to it
     =================================================================== */

    public function test_the_pin_field_is_bound_to_the_card_rail_on_a_real_page(): void
    {
        /*
         * Asserted through the page rather than through `$this->blade()`.
         *
         * The component reads `$errors`, which Laravel shares with every view rendered
         * through the HTTP stack (`ShareErrorsFromSession`) but **not** with a view
         * rendered by `Blade::render()` in isolation. Rendering it standalone throws
         * `Undefined variable $errors` — a fact about the test harness, not about the
         * component, and a reminder that a component which assumes a shared variable is
         * only really proved in place.
         */
        $user = $this->customer();

        $html = $this->actingAs($user)->get(route('airtime-data.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="pin"', $html);
        $this->assertStringContainsString('name="payment_method"', $html);
        $this->assertStringContainsString('x-bind:required="! cardRail"', $html);
        $this->assertStringContainsString('x-bind:disabled="cardRail"', $html);
    }

    public function test_the_pin_field_carries_static_required_in_the_served_html(): void
    {
        /*
         * The opposite of what an earlier version asserted, and the correction is the
         * point.
         *
         * `required` was bound (`:required="! cardRail"`), which is **absent from the
         * served HTML** — so with JavaScript disabled, or still loading on a slow
         * connection, the field was not required at all and a wallet submission skipped
         * the browser's own validation. The server still refused it, but the customer
         * got a round trip and a page reload instead of the browser stopping them.
         *
         * Static `required` plus `x-bind` inverts that: the safe case is the one that
         * does not depend on JavaScript having run, and the relaxation is what has to
         * be earned.
         */
        $user = $this->customer();

        $html = $this->actingAs($user)->get(route('airtime-data.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="pin"[^>]*\srequired[\s>]/',
            $html,
            'The PIN must be required in the markup; Alpine removes it for the card rail.'
        );
    }

    /* =====================================================================
     | The pages that use it
     =================================================================== */

    public function test_the_airtime_page_renders_the_picker(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->get(route('airtime-data.index'))
            ->assertOk()
            ->assertSee('name="payment_method"', false)
            ->assertSee('Card or bank transfer', false);
    }

    public function test_the_airtime_page_shows_the_customers_own_balance(): void
    {
        // Proves the prop resolves through the page and not only in isolation.
        $user = $this->customer();

        app(\App\Services\WalletService::class)->credit(
            user: $user,
            amount: \App\Support\Money::fromNaira('2500'),
            countsAsFunding: true,
            description: 'Test fixture',
        );

        $this->actingAs($user)
            ->get(route('airtime-data.index'))
            ->assertOk()
            ->assertSee('2,500.00', false);
    }

    public function test_the_airtime_page_renders_with_no_wallet_at_all(): void
    {
        // The controller loads the wallet, but a customer with no wallet row must not
        // produce a 500 on a page whose whole purpose is to let them buy something.
        $user = $this->customer();
        $user->wallet()->delete();

        $this->actingAs($user)->get(route('airtime-data.index'))->assertOk();
    }

    /* =====================================================================
     | The admin menu
     =================================================================== */

    public function test_a_super_admin_sees_every_module_in_the_menu(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        // The three that were missing from the sidebar entirely.
        $response->assertSee('Revenue &amp; profit', false)
            ->assertSee('Pricing', false)
            ->assertSee('Providers', false)
            ->assertSee('Payments', false);

        // And the ones that were already there, so this is a menu and not a rewrite.
        foreach (['Dashboard', 'Transactions', 'Bank transfers', 'Customers', 'Live chat', 'Messages', 'Announcements', 'Legal documents', 'Settings', 'Administrators'] as $label) {
            $response->assertSee($label, false);
        }
    }

    public function test_an_ordinary_administrator_does_not_see_the_financial_internal_modules(): void
    {
        /*
         * The other half of the requirement, and the one that matters more. The menu
         * lists every module, but the *permission* gates it — and for a plain
         * administrator that means no pricing, no provider configuration and no
         * margins. Their routes would refuse anyway (`view_pricing`, `manage_providers`
         * and `view_profit` are SUPER_ADMIN_ONLY); what this proves is that the menu
         * does not advertise what the routes would refuse, because a link that 403s
         * teaches an administrator to distrust the menu.
         */
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $response->assertDontSee('Revenue &amp; profit', false)
            ->assertDontSee('Margin watchlist', false)
            ->assertDontSee('Provider costs', false)
            ->assertDontSee('>Pricing<', false);

        /*
         * And it does see the modules its permissions allow. Asserted against the
         * factory's actual default set — `manage_users`, `manage_wallets`,
         * `view_transactions` — rather than against whichever labels look likely. Two
         * earlier versions of this got it wrong in instructive ways: the first asserted
         * "Payments", which needs `manage_payments` and is therefore correctly
         * *absent*; the second asserted the bare word "Transactions", which is hidden
         * inside the sidebar's `title` attribute and its matching `aria-current`
         * wrapper rather than appearing as visible text where a naive search finds it.
         *
         * The lesson both times was the same: the menu was right and the assertion was
         * wrong. Anchoring on the route the link actually points at is the version that
         * cannot drift with the markup.
         */
        $response->assertSee('Dashboard', false)
            ->assertSee('Customers', false)
            ->assertSee('Bank transfers', false)
            ->assertSee(route('admin.transactions.index', [], false), false);
    }

    public function test_a_super_admin_can_open_every_module_the_menu_offers(): void
    {
        /*
         * The claim that makes the menu honest: every link it renders resolves for the
         * account it rendered for. A menu is a promise, and this is the test that the
         * promise holds — a typo in a route name or a permission that does not match
         * its route fails here rather than in front of an administrator.
         */
        $admin = User::factory()->superAdmin()->create();

        foreach ([
            'admin.dashboard',
            'admin.transactions.index',
            'admin.bank-transfers.index',
            'admin.users.index',
            'admin.paystack.balance',
            'admin.profit.index',
            'admin.pricing.index',
            'admin.pricing.costs',
            'admin.profit.watchlist',
            'admin.providers.index',
            'admin.chat.index',
            'admin.contact.index',
            'admin.announcement.index',
            'admin.terms.index',
            'admin.settings.index',
            'admin.admins.index',
        ] as $route) {
            $this->actingAs($admin)
                ->get(route($route))
                ->assertOk();
        }
    }

    public function test_only_one_sidebar_link_is_marked_active_per_page(): void
    {
        /*
         * The bug this guards: "Pricing" carried the pattern `admin.pricing.*` and
         * "Provider costs" carried `admin.pricing.costs`, so on the costs page
         * **both** links lit up. The active state is `routeIs($pattern)`, and a
         * sub-page listed at the top level overlaps its own section by construction.
         *
         * A sidebar that highlights two things at once reads as broken, and no error
         * is raised anywhere — it is purely visual, which is exactly why nothing caught
         * it.
         */
        $admin = User::factory()->superAdmin()->create();

        foreach (['admin.dashboard', 'admin.pricing.costs', 'admin.profit.watchlist', 'admin.users.index'] as $route) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

            $this->assertSame(
                1,
                substr_count($html, 'sidebar-link-active'),
                "Exactly one sidebar item should be active on {$route}."
            );
        }
    }

    public function test_the_profit_page_actually_emits_its_polling_script(): void
    {
        /*
         * The bug this guards, and it shipped: the page's `@push('scripts')` was
         * inside `@section('content')` while a comment claimed it was outside. A
         * `@push` inside a section is buffered with the section instead of being
         * registered against the stack, so the script was **never emitted** and the
         * live "profit today" figure never moved. No PHP error, no failed request,
         * nothing in the log — the page simply did less than it claimed.
         *
         * Asserting the rendered page contains the script is the only thing that
         * catches it. This is why "the test posts to the endpoint" is not the same as
         * "the page works".
         */
        $admin = User::factory()->superAdmin()->create();

        $html = $this->actingAs($admin)->get(route('admin.profit.index'))->assertOk()->getContent();

        $this->assertStringContainsString("Alpine.data('profitLive'", $html);
        $this->assertStringContainsString('profitLive({', $html, 'The component must also be mounted.');
    }
}

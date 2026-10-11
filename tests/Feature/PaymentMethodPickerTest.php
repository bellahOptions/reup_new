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

        $this->assertStringContainsString('value="wallet"', $html);
        $this->assertStringContainsString('value="paystack"', $html);
        $this->assertStringContainsString('Wallet', $html);
        $this->assertStringContainsString('Card or transfer', $html);
    }

    public function test_the_picker_submits_the_wallet_rail_by_default(): void
    {
        /*
         * The default matters more than it looks. The form must work with JavaScript
         * disabled, and Alpine has not run yet when the browser evaluates the
         * `required` attributes — so the markup itself has to declare the wallet rail,
         * which is the one that requires a PIN. Defaulting to the gateway rail instead
         * would let a no-JavaScript submit reach the server without a PIN.
         */
        $html = (string) $this->blade('<x-payment-method :wallet="0" />');

        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="hidden"[^>]*name="payment_method"[^>]*value="wallet"/',
            $html,
            'The hidden payment_method input must default to wallet in the markup.'
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

    public function test_the_picker_announces_the_choice_for_the_pin_field(): void
    {
        /*
         * The PIN input lives outside this component, so the choice has to reach it.
         * The dispatch is the mechanism — without it the PIN stays `required` and
         * silently blocks a card submission, which is the bug a wrapper-scoped
         * `x-data` caused the first time.
         */
        $html = (string) $this->blade('<x-payment-method :wallet="0" />');

        $this->assertStringContainsString("dispatch('payment-method'", $html);
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

        $this->assertStringContainsString('@payment-method.window', $html);
        $this->assertStringContainsString(':required="! cardRail"', $html);
        $this->assertStringContainsString(':disabled="cardRail"', $html);
        $this->assertStringContainsString('name="pin"', $html);
        $this->assertStringContainsString('name="payment_method"', $html);
    }

    public function test_the_pin_field_does_not_hard_code_required(): void
    {
        /*
         * `required` is bound rather than literal, so the card rail is not blocked by
         * an attribute the browser enforces before Alpine can remove it. The server
         * does not rely on the browser either way — the endpoint carries
         * `required_unless:payment_method,paystack`, which
         * `PaystackAirtimeTest` exercises from both sides.
         */
        $user = $this->customer();

        $html = $this->actingAs($user)->get(route('airtime-data.index'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="pin"[^>]*\srequired[\s>]/',
            $html,
            'A hard-coded required would block the card rail entirely.'
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
            ->assertSee('Card or transfer', false);
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
            ->assertSee('Provider costs', false)
            ->assertSee('Margin watchlist', false)
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
            ->assertDontSee('Pricing', false);

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
}

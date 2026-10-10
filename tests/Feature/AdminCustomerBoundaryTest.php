<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HomeRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Administrators are not customers.
 *
 * ## Why this is a boundary and not a matter of taste
 *
 * `is_admin` is a flag on an ordinary customer row behind the ordinary `web`
 * guard, so an administrator holds a valid session for the whole customer
 * application. Nothing checked the flag, which meant an administrator could
 * fund a wallet, buy airtime and hold a virtual account through the same
 * screens a customer uses — while also being the account trusted to approve
 * refunds and move wallets in the console. That is a segregation-of-duties
 * failure, and it is the kind an auditor tests first.
 *
 * These tests therefore assert three separate claims, because they fail
 * independently:
 *
 *   1. an administrator reaching a customer route is sent to the console;
 *   2. an administrator cannot open a second, customer account;
 *   3. a customer is still refused by the console (the inverse, which already
 *      held — asserted so that tightening one direction cannot silently loosen
 *      the other).
 */
class AdminCustomerBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    /* =====================================================================
     | 1. An administrator is redirected out of the customer application
     | =================================================================== */

    public function test_an_admin_is_redirected_from_the_customer_dashboard(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_an_admin_is_redirected_from_every_customer_entry_point(): void
    {
        // A representative route from each area of the customer application,
        // rather than one: the guard lives on the group, and a route added
        // outside the group later is exactly the failure this would miss.
        $routes = [
            'wallet.index',
            'wallet.fund',
            'wallet.history',
            'wallet.virtual-account',
            'transactions.index',
            'airtime-data.index',
            'cable-tv.index',
            'electricity.index',
            'betting.index',
            'profile.index',
            'affiliate.index',
            'live.chat',
        ];

        $admin = $this->admin();

        foreach ($routes as $name) {
            $this->actingAs($admin)
                ->get(route($name))
                ->assertRedirect(route('admin.dashboard'));
        }
    }

    public function test_an_admin_does_not_reach_a_post_only_customer_action(): void
    {
        // The guard runs before the controller, so the action never executes —
        // asserted by the wallet balance being untouched rather than by the
        // status code alone.
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('wallet.process-funding'), [
                'amount' => 5000,
                'payment_method' => 'bank_transfer',
            ])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(
            0,
            \App\Models\Transactions::where('user_id', $admin->id)->count(),
            'An administrator must not be able to start a funding transaction.'
        );
    }

    public function test_a_json_caller_is_refused_rather_than_redirected(): void
    {
        // The console polls endpoints with fetch(); a 302 to an HTML page would
        // surface as a parse error rather than as "you are in the wrong place".
        $this->actingAs($this->admin())
            ->getJson(route('wallet.balance'))
            ->assertForbidden();
    }

    public function test_an_admin_still_reaches_the_console(): void
    {
        // The redirect must not be a loop: the destination has to be reachable.
        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    /* =====================================================================
     | 2. An administrator cannot open a customer account
     | =================================================================== */

    public function test_an_admin_is_sent_to_the_console_from_the_register_form(): void
    {
        $this->actingAs($this->admin())
            ->get(route('register'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_an_admin_cannot_register_a_second_account(): void
    {
        $admin = $this->admin();
        $before = User::count();

        $this->actingAs($admin)
            ->post(route('register'), [
                'name' => 'Second Account',
                'email' => 'second@example.test',
                'password' => 'password',
                'password_confirmation' => 'password',
                'terms' => 'on',
            ])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame($before, User::count(), 'No customer account may be created from an admin session.');
        $this->assertNull(User::where('email', 'second@example.test')->first());
    }

    /* =====================================================================
     | 3. Sign-in sends each account to its own home
     | =================================================================== */

    public function test_an_admin_signing_in_lands_on_the_console_in_one_hop(): void
    {
        // The failure this guards against is a double redirect: sign-in sends
        // the admin to /dashboard, and `customer` then bounces them to the
        // console — two hops, and a flash of the wrong application.
        $admin = $this->admin();

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_customer_signing_in_is_unaffected(): void
    {
        $customer = $this->customer();

        $this->post(route('login'), [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_an_authenticated_admin_opening_the_login_page_is_sent_to_the_console(): void
    {
        $this->actingAs($this->admin())
            ->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_home_route_helper_answers_per_account(): void
    {
        $this->assertSame('dashboard', HomeRoute::for(null));
        $this->assertSame('dashboard', HomeRoute::for($this->customer()));
        $this->assertSame('admin.dashboard', HomeRoute::for($this->admin()));
    }

    /* =====================================================================
     | 4. The inverse still holds
     | =================================================================== */

    public function test_a_customer_is_still_refused_by_the_console(): void
    {
        $this->actingAs($this->customer())
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    /* =====================================================================
     | 5. Public pages are not part of the boundary
     | =================================================================== */

    public function test_an_admin_may_still_read_public_pages(): void
    {
        /*
         * The landing page, the pricelist and the legal documents are not the
         * customer application. Redirecting an administrator away from the
         * marketing site would be a different (and wrong) rule: the boundary is
         * the authenticated customer area, not the public web.
         */
        $admin = $this->admin();

        foreach (['home', 'pricelist', 'terms-of-service', 'privacy-policy', 'contact'] as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk();
        }
    }
}

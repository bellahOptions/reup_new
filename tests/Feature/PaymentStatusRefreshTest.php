<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentStatusResolver;
use App\Support\Money;
use App\Support\RefreshStatusResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin console's "Refresh status" action.
 *
 * The decisions that matter, and why:
 *
 *   * an attempt that never reached a gateway is failed **without** a network
 *     call — there is no charge that could exist, so asking is pointless;
 *   * an attempt that did reach one is settled only from the gateway's own
 *     answer, and credited exactly once even if the button is pressed twice;
 *   * "the gateway could not be reached" is reported as such and changes
 *     nothing. Treating it as a verdict is how a live payment gets written off.
 */
class PaymentStatusRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const PAYSTACK_SECRET = 'sk_test_refresh';
    private const BACHS_SECRET = 'sk_sandbox_refresh';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => self::PAYSTACK_SECRET,
            'services.bachs.enabled' => true,
            'services.bachs.secret_key' => self::BACHS_SECRET,
            'services.bachs.base_url' => 'https://sandbox-api.bachs.io',
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $admin->forceFill([
            'is_super_admin' => false,
            'admin_permissions' => ['view_transactions', 'manage_transactions'],
        ])->save();

        return $admin->fresh();
    }

    /** An admin who may look but not act. */
    private function viewer(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $admin->forceFill([
            'is_super_admin' => false,
            'admin_permissions' => ['view_transactions'],
        ])->save();

        return $admin->fresh();
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    private function pendingFunding(User $user, string $amount = '5000.00', array $meta = []): Transactions
    {
        $money = Money::fromNaira($amount);

        return Transactions::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding — Card',
            'amount' => $money->toDecimalString(),
            'service_fee' => '0.00',
            'total_amount' => $money->toDecimalString(),
            'recipient' => $user->email,
            'provider' => 'paystack',
            'payment_method' => 'paystack',
            'payment_status' => 'pending',
            'status' => 'pending',
            'meta' => $meta,
        ]);
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    /* =====================================================================
     | 1. Did it reach the gateway?
     |=================================================================== */

    public function test_an_attempt_that_never_reached_paystack_is_failed_without_a_network_call(): void
    {
        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $customer = $this->customer();

        // No access code: initialize() never came back, so no charge can exist.
        $transaction = $this->pendingFunding($customer);

        $response = $this->actingAs($this->admin())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertOk();

        $response->assertJson([
            'success' => true,
            'changed' => true,
            'outcome' => 'failed',
            'status' => 'failed',
        ]);

        $this->assertSame('failed', $transaction->fresh()->payment_status);
        $this->assertNotNull($transaction->fresh()->completed_at);
        $this->assertSame('0.00', $this->balanceOf($customer));

        // The whole point: nothing was asked of the gateway.
        Http::assertNothingSent();
    }

    public function test_a_paystack_attempt_that_reached_checkout_is_queried_by_uuid(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer, '5000.00', ['paystack_access_code' => 'acc_1']);

        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => [
                'reference' => $transaction->gatewayReference(),
                'status' => 'success',
                'amount' => 500000,
                'currency' => 'NGN',
                'channel' => 'card',
                'gateway_response' => 'Successful',
            ],
        ], 200)]);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertOk()
            ->assertJson(['outcome' => 'settled', 'changed' => true]);

        // Queried with the UUID, which is what the gateway was given — not the
        // display reference the customer sees.
        Http::assertSent(function ($request) use ($transaction) {
            return str_contains($request->url(), '/transaction/verify/' . $transaction->gatewayReference());
        });

        $this->assertSame('5000.00', $this->balanceOf($customer));
        $this->assertSame('success', $transaction->fresh()->status);
    }

    public function test_a_bachs_attempt_is_queried_by_checkout_id(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer, '5000.00', [
            'gateway' => 'bachs',
            'bachs_checkout_id' => 'chk_refresh',
        ]);

        Http::fake(['sandbox-api.bachs.io/*' => Http::response([
            'checkout_id' => 'chk_refresh',
            'status' => 'completed',
            'payment' => [
                'id' => 'ch_refresh',
                'status' => 'succeeded',
                'amount' => '5000.00',
                'currency' => 'NGN',
            ],
        ], 200)]);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertOk()
            ->assertJson(['outcome' => 'settled']);

        $this->assertSame('5000.00', $this->balanceOf($customer));
    }

    /* =====================================================================
     | 2. What the gateway said
     |=================================================================== */

    public function test_a_payment_paystack_still_treats_as_live_is_left_pending(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer, '5000.00', ['paystack_access_code' => 'acc_2']);

        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'ongoing', 'amount' => 500000, 'currency' => 'NGN'],
        ], 200)]);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertOk()
            ->assertJson(['outcome' => 'pending', 'changed' => false]);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $this->balanceOf($customer));
    }

    public function test_an_abandoned_paystack_checkout_is_failed(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer, '5000.00', ['paystack_access_code' => 'acc_3']);

        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'abandoned', 'amount' => 500000, 'currency' => 'NGN'],
        ], 200)]);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertOk()
            ->assertJson(['outcome' => 'failed']);

        $this->assertSame('failed', $transaction->fresh()->status);
    }

    public function test_an_amount_mismatch_does_not_credit(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer, '5000.00', ['paystack_access_code' => 'acc_4']);

        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => [
                'reference' => $transaction->gatewayReference(),
                'status' => 'success',
                'amount' => 100, // ₦1 against a ₦5,000 row
                'currency' => 'NGN',
            ],
        ], 200)]);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertOk()
            ->assertJson(['outcome' => 'failed']);

        $this->assertSame('0.00', $this->balanceOf($customer));
        $this->assertSame('failed', $transaction->fresh()->status);
    }

    /* =====================================================================
     | 3. Unreachable is not a verdict
     |=================================================================== */

    public function test_an_unreachable_gateway_changes_nothing(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer, '5000.00', ['paystack_access_code' => 'acc_5']);

        Http::fake(['api.paystack.co/*' => Http::response(['message' => 'Service unavailable'], 503)]);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertOk()
            ->assertJson(['outcome' => 'unreachable', 'changed' => false]);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $this->balanceOf($customer));
    }

    public function test_an_unknown_reference_is_reported_rather_than_written_off(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer, '5000.00', ['paystack_access_code' => 'acc_6']);

        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => false,
            'message' => 'Transaction reference not found',
        ], 404)]);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertOk()
            ->assertJson(['outcome' => 'unreachable', 'changed' => false]);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_a_settled_transaction_is_not_queried_again(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer, '5000.00', ['paystack_access_code' => 'acc_7']);

        $transaction->forceFill(['status' => 'success', 'payment_status' => 'success'])->save();

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertOk()
            ->assertJson(['outcome' => 'already_final', 'changed' => false]);

        Http::assertNothingSent();
    }

    /* =====================================================================
     | 4. Pressing it twice must not pay twice
     |=================================================================== */

    public function test_refreshing_a_settled_payment_repeatedly_credits_once(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer, '5000.00', ['paystack_access_code' => 'acc_8']);

        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => [
                'reference' => $transaction->gatewayReference(),
                'status' => 'success',
                'amount' => 500000,
                'currency' => 'NGN',
                'channel' => 'card',
            ],
        ], 200)]);

        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.transactions.refresh-status', $transaction))->assertOk();
        $this->actingAs($admin)->put(route('admin.transactions.refresh-status', $transaction))->assertOk();
        $this->actingAs($admin)->put(route('admin.transactions.refresh-status', $transaction))->assertOk();

        $this->assertSame('5000.00', $this->balanceOf($customer));
    }

    /* =====================================================================
     | 5. Authorisation
     |=================================================================== */

    public function test_a_viewer_without_manage_permission_cannot_refresh(): void
    {
        $customer = $this->customer();
        $transaction = $this->pendingFunding($customer);

        $this->actingAs($this->viewer())
            ->put(route('admin.transactions.refresh-status', $transaction))
            ->assertForbidden();

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_a_guest_cannot_refresh(): void
    {
        $transaction = $this->pendingFunding($this->customer());

        $this->put(route('admin.transactions.refresh-status', $transaction))
            ->assertRedirect();
    }

    /* =====================================================================
     | 6. The resolver's own contract
     |=================================================================== */

    public function test_the_resolver_reports_which_gateway_served_the_transaction(): void
    {
        $resolver = app(PaymentStatusResolver::class);
        $customer = $this->customer();

        $this->assertSame('paystack', $resolver->gateway($this->pendingFunding($customer)));
        $this->assertSame('bachs', $resolver->gateway($this->pendingFunding($customer, '100', ['gateway' => 'bachs'])));
    }

    public function test_the_resolver_detects_whether_a_gateway_was_ever_reached(): void
    {
        $resolver = app(PaymentStatusResolver::class);
        $customer = $this->customer();

        $this->assertFalse($resolver->reachedGateway($this->pendingFunding($customer)));
        $this->assertTrue($resolver->reachedGateway($this->pendingFunding($customer, '100', ['paystack_access_code' => 'x'])));
        $this->assertTrue($resolver->reachedGateway($this->pendingFunding($customer, '100', ['bachs_checkout_id' => 'y'])));

        // `initiated_at` is stamped before any gateway is called, so it must
        // never be read as evidence that checkout was reached.
        $this->assertFalse($resolver->reachedGateway($this->pendingFunding($customer, '100', ['initiated_at' => now()->toDateTimeString()])));
    }

    public function test_the_resolver_leaves_bank_transfer_to_the_webhook(): void
    {
        $customer = $this->customer();

        $transaction = $this->pendingFunding($customer);
        $transaction->forceFill(['payment_method' => 'bank_transfer'])->save();

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $result = app(PaymentStatusResolver::class)->refresh($transaction->fresh());

        // A dedicated virtual account has no per-transaction query we can make;
        // the inbound transfer is matched by the webhook.
        $this->assertSame(RefreshStatusResult::OUTCOME_FINAL, $result->outcome);
        Http::assertNothingSent();
    }
}

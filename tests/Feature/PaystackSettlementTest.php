<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Services\PaystackService;
use App\Services\SecurityService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Paystack settlement.
 *
 * Every test here asserts on the **wallet balance and the ledger**, not on a
 * redirect or a status code. "Did the customer get exactly the money they paid,
 * once" is the only question that matters, and an HTTP 200 answers none of it.
 */
class PaystackSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_settlement';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => self::SECRET,
            'services.paystack.public_key' => 'pk_test_settlement',
        ]);
    }

    private function user(float $balance = 0): User
    {
        $user = User::factory()->create();

        if ($balance > 0) {
            app(WalletService::class)->credit($user, number_format($balance, 2, '.', ''));
        }

        return $user;
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    /** A pending card-funding row, as `processFunding` creates it. */
    private function pendingFunding(User $user, string $amount = '1500.50'): Transactions
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
        ]);
    }

    /**
     * The payload Paystack sends and returns, built from the transaction so the
     * reference always matches what was lodged.
     */
    private function gatewayPayload(Transactions $transaction, array $overrides = []): array
    {
        return array_merge([
            'reference' => $transaction->gatewayReference(),
            'status' => 'success',
            'amount' => Money::fromDatabase($transaction->total_amount)->minor(),
            'currency' => 'NGN',
            'channel' => 'card',
            'gateway_response' => 'Successful',
        ], $overrides);
    }

    /** Deliver a signed webhook exactly as Paystack documents it. */
    private function webhook(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    /* =====================================================================
     | 1. Successful payment
     |=================================================================== */

    /**
     * A production install must never hand Paystack a callback the customer's
     * browser cannot reach. Paystack accepts an unreachable `callback_url`
     * without complaint, so the failure only shows up *after* the card is
     * charged — which is what this guard exists to prevent.
     */
    public function test_initialisation_refuses_a_non_public_callback_url_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1500.50');

        // The value a stale/exported APP_URL produces — note Paystack itself
        // would have accepted this.
        $this->forceOrigin('http://localhost');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a public https URL');

        app(PaystackService::class)->initialize($transaction, $user);
    }

    public function test_initialisation_refuses_a_plain_http_callback_url_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1500.50');

        $this->forceOrigin('http://reup.com.ng');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a public https URL');

        app(PaystackService::class)->initialize($transaction, $user);
    }

    public function test_initialisation_refuses_a_private_ip_callback_url_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1500.50');

        $this->forceOrigin('https://192.168.1.10');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a public https URL');

        app(PaystackService::class)->initialize($transaction, $user);
    }

    /**
     * The guard must not fire where a loopback origin is correct, and it must
     * send the *correct* callback when the origin is genuinely public.
     */
    public function test_a_public_https_callback_url_is_accepted_in_production_and_sent_to_paystack(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->forceOrigin('https://reup.com.ng');

        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code' => 'abc123',
                    'reference' => 'abc123',
                ],
            ]),
        ]);

        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1500.50');

        $url = app(PaystackService::class)->initialize($transaction, $user);

        $this->assertSame('https://checkout.paystack.com/abc123', $url);

        Http::assertSent(function ($request) {
            return $request['callback_url'] === 'https://reup.com.ng/wallet/paystack/callback'
                && $request['amount'] === 150050;
        });

        $this->assertSame(
            'https://reup.com.ng/wallet/paystack/callback',
            $transaction->fresh()->meta['callback_url'] ?? null
        );
    }

    public function test_local_development_still_points_the_callback_at_loopback(): void
    {
        // The suite runs as `testing`, i.e. not production: no guard.
        $this->assertSame(
            route('wallet.paystack.callback'),
            app(PaystackService::class)->callbackUrl()
        );
    }

    /**
     * Pin the origin the URL generator builds from, for the duration of one
     * test. `config()` alone is not enough: `UrlGenerator` caches its root the
     * first time a URL is generated, so a later config change would be ignored.
     */
    private function forceOrigin(string $origin): void
    {
        URL::forceRootUrl(rtrim($origin, '/'));
        URL::forceScheme(str_starts_with($origin, 'https://') ? 'https' : 'http');
    }

    /* =====================================================================
     | Retrying an attempt that failed
     |=================================================================== */

    /**
     * A customer who retries a funding attempt that never went through must be
     * able to try again. The idempotency key exists to stop a *second* charge
     * for work that already happened; a failed attempt is not work that
     * happened, so a replay of it must not pin the customer to the status page
     * of a dead attempt.
     */
    public function test_a_replayed_failed_attempt_sends_the_customer_back_to_the_form(): void
    {
        $user = User::factory()->create();
        $failed = $this->pendingFunding($user, '1500.50');

        $failed->forceFill([
            'status' => 'failed',
            'payment_status' => 'failed',
            'status_message' => 'Could not start payment session.',
            'completed_at' => now(),
        ])->save();

        $key = $this->reserveFunding($user, '1500.50', $failed);

        $response = $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '1500.50',
            'payment_method' => 'paystack',
            'idempotency_key' => $key,
        ]);

        $response->assertRedirect(route('wallet.fund'));
        $response->assertSessionHas('error');

        // No second row was created for the retry.
        $this->assertSame(1, Transactions::where('user_id', $user->id)->count());
    }

    /**
     * The guard is only for failures. A replay of an attempt that is still in
     * flight must keep returning the original outcome, which is the whole point
     * of the key.
     */
    public function test_a_replayed_live_attempt_still_returns_the_original_outcome(): void
    {
        $user = User::factory()->create();
        $live = $this->pendingFunding($user, '1500.50');

        $key = $this->reserveFunding($user, '1500.50', $live);

        $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '1500.50',
            'payment_method' => 'paystack',
            'idempotency_key' => $key,
        ])->assertRedirect(route('wallet.payment.status', ['reference' => $live->reference]));

        $this->assertSame(1, Transactions::where('user_id', $user->id)->count());
    }

    /**
     * Reserve a funding key exactly as the controller would, pointing at an
     * existing transaction.
     */
    private function reserveFunding(User $user, string $amount, Transactions $transaction): string
    {
        $key = 'funding-key-' . Str::random(12);

        $security = app(SecurityService::class);

        $reservation = $security->reserve(
            $key,
            'wallet_funding',
            $user,
            $security->requestHash($user, [
                'amount' => Money::fromNaira($amount)->toDecimalString(),
                'method' => 'paystack',
            ])
        );

        $security->complete($reservation['record'], $transaction, ['outcome' => 'initiated']);

        return $key;
    }

    public function test_a_successful_gateway_payment_credits_the_exact_amount(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1500.50');

        $result = app(PaystackService::class)->settle($transaction, $this->gatewayPayload($transaction));

        $this->assertSame('settled', $result['status']);
        $this->assertSame('1500.50', $this->balanceOf($user));

        $fresh = $transaction->fresh();
        $this->assertSame('success', $fresh->status);
        $this->assertSame('success', $fresh->payment_status);
        $this->assertSame('0.00', (string) $fresh->balance_before);
        $this->assertSame('1500.50', (string) $fresh->balance_after);

        // One ledger entry, CREDIT direction, exact before/after chain.
        $entries = WalletLedger::where('wallet_id', Wallet::where('user_id', $user->id)->value('id'))->get();
        $this->assertCount(1, $entries);
        $this->assertSame(WalletLedger::ENTRY_CREDIT, $entries->first()->entry_type);
        $this->assertSame('1500.50', (string) $entries->first()->amount);
        $this->assertSame($transaction->id, $entries->first()->transaction_id);
    }

    public function test_a_kobo_amount_survives_the_gateway_round_trip(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '0.01');

        app(PaystackService::class)->settle($transaction, $this->gatewayPayload($transaction));

        $this->assertSame('0.01', $this->balanceOf($user));
    }

    /* =====================================================================
     | 2. Wrong amount
     |=================================================================== */

    public function test_an_underpayment_does_not_credit(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $result = app(PaystackService::class)->settle($transaction, $this->gatewayPayload($transaction, [
            'amount' => 10000, // ₦100 paid against a ₦5,000 row
        ]));

        $this->assertSame('amount_mismatch', $result['status']);
        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame(0, WalletLedger::count());
    }

    public function test_an_overpayment_does_not_credit_the_wrong_figure(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1000.00');

        $result = app(PaystackService::class)->settle($transaction, $this->gatewayPayload($transaction, [
            'amount' => 200000, // ₦2,000 paid against a ₦1,000 row
        ]));

        $this->assertSame('amount_mismatch', $result['status']);
        $this->assertSame('0.00', $this->balanceOf($user), 'A mismatched payment must credit nothing at all.');
    }

    public function test_a_one_kobo_shortfall_is_a_mismatch(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1500.50');

        // 150049 kobo instead of 150050. This is exactly the case a floating
        // point conversion gets wrong, and it must be a mismatch, not a credit.
        $result = app(PaystackService::class)->settle($transaction, $this->gatewayPayload($transaction, [
            'amount' => 150049,
        ]));

        $this->assertSame('amount_mismatch', $result['status']);
        $this->assertSame('0.00', $this->balanceOf($user));
    }

    /* =====================================================================
     | 3. Currency
     |=================================================================== */

    public function test_a_payment_in_another_currency_does_not_credit(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1000.00');

        $result = app(PaystackService::class)->settle($transaction, $this->gatewayPayload($transaction, [
            'currency' => 'USD',
        ]));

        $this->assertSame('currency_mismatch', $result['status']);
        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame('failed', $transaction->fresh()->status);
    }

    /* =====================================================================
     | 4. Reference validation
     |=================================================================== */

    public function test_a_payment_for_a_different_reference_does_not_credit(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1000.00');

        $result = app(PaystackService::class)->settle($transaction, $this->gatewayPayload($transaction, [
            'reference' => 'SOMEONE-ELSES-REFERENCE',
        ]));

        $this->assertSame('reference_mismatch', $result['status']);
        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame('pending', $transaction->fresh()->status, 'The row must be left pending, not failed.');
    }

    /* =====================================================================
     | 5. Duplicate webhook / callback
     |=================================================================== */

    public function test_a_duplicate_webhook_credits_once(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '2500.00');

        $payload = [
            'event' => 'charge.success',
            'data' => $this->gatewayPayload($transaction),
        ];

        $this->webhook($payload)->assertOk()->assertJson(['status' => 'settled']);
        $this->assertSame('2500.00', $this->balanceOf($user));

        // Paystack retries anything it is not sure it delivered.
        $this->webhook($payload)->assertOk()->assertJson(['status' => 'already_settled']);

        $this->assertSame('2500.00', $this->balanceOf($user), 'A retried webhook must not credit twice.');
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_a_duplicate_settle_call_credits_once(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '750.25');
        $payload = $this->gatewayPayload($transaction);

        $first = app(PaystackService::class)->settle($transaction, $payload);
        $second = app(PaystackService::class)->settle($transaction->fresh(), $payload);

        $this->assertSame('settled', $first['status']);
        $this->assertSame('already_settled', $second['status']);
        $this->assertSame('750.25', $this->balanceOf($user));
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_a_webhook_arriving_after_the_callback_settled_is_harmless(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '300.00');

        // The browser callback settles first…
        app(PaystackService::class)->settle($transaction, $this->gatewayPayload($transaction));

        // …then the (delayed) webhook arrives for the same charge.
        $this->webhook([
            'event' => 'charge.success',
            'data' => $this->gatewayPayload($transaction),
        ])->assertOk()->assertJson(['status' => 'already_settled']);

        $this->assertSame('300.00', $this->balanceOf($user));
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_an_unsigned_webhook_changes_nothing(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '1000.00');

        $body = json_encode([
            'event' => 'charge.success',
            'data' => $this->gatewayPayload($transaction),
        ]);

        $this->call('POST', '/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => 'not-the-right-signature',
        ], $body)->assertStatus(401);

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_a_client_cannot_declare_its_own_payment_successful(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        // The gateway says the charge was never completed.
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => $this->gatewayPayload($transaction, ['status' => 'abandoned']),
            ], 200),
        ]);

        $this->actingAs($user)
            ->get(route('wallet.paystack.callback', ['reference' => $transaction->gatewayReference()]))
            ->assertRedirect();

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame(0, WalletLedger::count());
    }

    /* =====================================================================
     | 6. Successful gateway payment with a missing webhook
     |=================================================================== */

    public function test_a_payment_the_gateway_confirms_settles_without_a_webhook(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '4200.00');

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => $this->gatewayPayload($transaction),
            ], 200),
        ]);

        $this->actingAs($user)
            ->get(route('wallet.paystack.callback', ['reference' => $transaction->gatewayReference()]))
            ->assertRedirect(route('wallet.index'));

        $this->assertSame('4200.00', $this->balanceOf($user));
        $this->assertSame('success', $transaction->fresh()->status);
    }

    public function test_a_callback_for_another_users_transaction_is_refused(): void
    {
        $owner = $this->user();
        $attacker = $this->user();
        $transaction = $this->pendingFunding($owner, '9000.00');

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => $this->gatewayPayload($transaction),
            ], 200),
        ]);

        $this->actingAs($attacker)
            ->get(route('wallet.paystack.callback', ['reference' => $transaction->gatewayReference()]))
            ->assertNotFound();

        $this->assertSame('0.00', $this->balanceOf($owner));
        $this->assertSame('pending', $transaction->fresh()->status);
    }

    /* =====================================================================
     | 7. Reconciliation settlement
     |=================================================================== */

    public function test_reconciliation_settles_a_payment_the_webhook_missed(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '3300.00');
        $transaction->forceFill(['created_at' => now()->subMinutes(30)])->saveQuietly();

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => $this->gatewayPayload($transaction),
            ], 200),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('3300.00', $this->balanceOf($user));
        $this->assertSame('success', $transaction->fresh()->status);
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '3300.00');
        $transaction->forceFill(['created_at' => now()->subMinutes(30)])->saveQuietly();

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => $this->gatewayPayload($transaction),
            ], 200),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);
        $this->artisan('payments:reconcile')->assertExitCode(0);
        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('3300.00', $this->balanceOf($user), 'Running reconciliation repeatedly must not credit repeatedly.');
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_reconciliation_does_not_credit_an_amount_the_gateway_disagrees_with(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');
        $transaction->forceFill(['created_at' => now()->subMinutes(30)])->saveQuietly();

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => $this->gatewayPayload($transaction, ['amount' => 100]),
            ], 200),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame('failed', $transaction->fresh()->status);
    }

    /* =====================================================================
     | Dedicated virtual account transfers
     |=================================================================== */

    public function test_an_inbound_bank_transfer_credits_the_right_wallet_once(): void
    {
        $user = $this->user();
        $user->forceFill(['dva_account_number' => '1234567890'])->save();

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'DVA-TRANSFER-REF-1',
                'amount' => 25000,
                'currency' => 'NGN',
                'authorization' => [
                    'channel' => 'dedicated_nuban',
                    'receiver_bank_account_number' => '1234567890',
                    'sender_bank' => 'Test Bank',
                ],
            ],
        ];

        $this->webhook($payload)->assertOk()->assertJson(['status' => 'credited']);
        $this->assertSame('250.00', $this->balanceOf($user));

        // Paystack retries until it receives a 2xx.
        $this->webhook($payload)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertSame('250.00', $this->balanceOf($user));
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_an_inbound_transfer_to_an_unknown_account_credits_nobody(): void
    {
        $user = $this->user();
        $user->forceFill(['dva_account_number' => '1234567890'])->save();

        $this->webhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'DVA-UNKNOWN-1',
                'amount' => 25000,
                'currency' => 'NGN',
                'authorization' => [
                    'channel' => 'dedicated_nuban',
                    'receiver_bank_account_number' => '9999999999',
                ],
            ],
        ])->assertOk()->assertJson(['status' => 'unknown_account']);

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame(0, WalletLedger::count());
    }

    public function test_an_inbound_transfer_in_another_currency_credits_nobody(): void
    {
        $user = $this->user();
        $user->forceFill(['dva_account_number' => '1234567890'])->save();

        $this->webhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'DVA-USD-1',
                'amount' => 25000,
                'currency' => 'USD',
                'authorization' => [
                    'channel' => 'dedicated_nuban',
                    'receiver_bank_account_number' => '1234567890',
                ],
            ],
        ])->assertOk()->assertJson(['status' => 'currency_mismatch']);

        $this->assertSame('0.00', $this->balanceOf($user));
    }
}

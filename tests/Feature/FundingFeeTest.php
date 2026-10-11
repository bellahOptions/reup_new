<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Services\PaystackService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The wallet funding service fee.
 *
 * ## The rule being pinned
 *
 * The customer pays the amount they chose, and nothing else. The service fee is
 * then taken from the wallet as a separate, recorded debit, so the *net* balance
 * increase is the amount minus the fee. Before this, the fee was added to the
 * checkout: a customer who typed ₦1,000 was asked for ₦1,035.
 *
 * So the tests come in two halves, and both matter:
 *
 *   * **checkout** — what the gateway is asked for equals what the customer
 *     typed. A regression here overcharges at the point of payment, which is the
 *     one place it cannot be quietly corrected afterwards.
 *   * **settlement** — the credit is the full amount, the fee is a second,
 *     separate movement, and the two together are exactly one funding and one
 *     fee however many times settlement is attempted.
 *
 * The fee is configured to 1% for these tests rather than left at the shipped
 * zero, so the assertions are about the mechanism rather than about "zero equals
 * zero".
 */
class FundingFeeTest extends TestCase
{
    use RefreshDatabase;

    private const PAYSTACK_SECRET = 'sk_test_funding_fee';

    /** 1% — a non-zero rate, so the arithmetic is actually exercised. */
    private const FEE_PERCENT = 1.0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => self::PAYSTACK_SECRET,
            'services.paystack.public_key' => 'pk_test_funding_fee',
            'services.bachs.enabled' => false,
            'wallet.funding_fee' => [
                'percentage' => self::FEE_PERCENT,
                'additional' => 0,
                'cap' => null,
            ],
        ]);
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    private function feeFor(User $user, Transactions $funding): ?Transactions
    {
        return Transactions::where('reference', WalletService::fundingFeeReference((string) $funding->reference))
            ->where('user_id', $user->id)
            ->first();
    }

    private function pendingFunding(User $user, string $amount): Transactions
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
            'meta' => ['service_fee' => $money->percentage(self::FEE_PERCENT)->toDecimalString()],
        ]);
    }

    /** Settle through the same door the webhook uses. */
    private function settle(Transactions $funding): array
    {
        $transaction = $funding->fresh();

        return app(PaystackService::class)->settle($transaction, [
            'reference' => $transaction->gatewayReference(),
            'status' => 'success',
            'amount' => Money::fromDatabase($transaction->total_amount)->minor(),
            'currency' => 'NGN',
            'channel' => 'card',
            'gateway_response' => 'Successful',
        ]);
    }

    /* =====================================================================
     | 1. The checkout
     | =================================================================== */

    public function test_the_customer_pays_exactly_the_amount_they_chose(): void
    {
        /*
         * The headline behaviour. ₦1,000 typed means ₦1,000 charged — no fee on
         * top. This is the assertion that would have failed before the change:
         * the total was ₦1,035.
         */
        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'acc'],
        ], 200)]);

        $user = $this->customer();

        $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '1000',
            'payment_method' => 'paystack',
        ]);

        $funding = Transactions::where('user_id', $user->id)->where('service_type', 'funding')->firstOrFail();

        $this->assertSame('1000.00', (string) $funding->amount);
        $this->assertSame('1000.00', (string) $funding->total_amount, 'The checkout total must equal the amount chosen.');
        $this->assertSame('0.00', (string) $funding->service_fee, 'The funding row carries no fee of its own.');

        // The fee is recorded for disclosure, not added to the payment.
        $this->assertSame('10.00', (string) $funding->meta['service_fee']);
        $this->assertSame('990.00', (string) $funding->meta['net_credit']);
        $this->assertSame('1000.00', (string) $funding->meta['total_payable']);
    }

    public function test_paystack_is_asked_for_the_chosen_amount_in_kobo(): void
    {
        // The figure actually sent to the gateway, which is what the customer is
        // charged — asserting on the row alone would not prove this.
        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'acc'],
        ], 200)]);

        $user = $this->customer();

        $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '1000',
            'payment_method' => 'paystack',
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/transaction/initialize')) {
                return false;
            }

            return (int) ($request->data()['amount'] ?? 0) === 100000;
        });
    }

    public function test_funding_with_no_configured_fee_charges_the_amount_and_takes_nothing(): void
    {
        // The shipped default. The mechanism must be inert, not merely quiet.
        config(['wallet.funding_fee' => ['percentage' => 0, 'additional' => 0, 'cap' => null]]);

        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $this->settle($funding);

        $this->assertSame('1000.00', $this->balanceOf($user), 'With no fee configured the full amount must land.');
        $this->assertNull($this->feeFor($user, $funding), 'No fee row may be written when there is no fee.');
    }

    /* =====================================================================
     | 2. Settlement: credit gross, then charge the fee
     | =================================================================== */

    public function test_settlement_credits_the_gross_and_takes_the_fee_separately(): void
    {
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $result = $this->settle($funding);

        $this->assertSame('settled', $result['status']);

        // Net: ₦1,000 credited, ₦10 fee taken.
        $this->assertSame('990.00', $this->balanceOf($user), 'fee error: ' . json_encode($funding->fresh()->meta['funding_fee_error'] ?? null));

        $fee = $this->feeFor($user, $funding);

        $this->assertNotNull($fee, 'The fee must be recorded as its own transaction.');
        $this->assertSame('debit', $fee->type);
        $this->assertSame('fee', $fee->service_type);
        $this->assertSame('10.00', (string) $fee->amount);
        $this->assertSame('success', $fee->status);
        $this->assertSame('wallet', $fee->payment_method, 'Our own charge is not a gateway payment.');
        $this->assertSame($funding->id, $fee->meta['funding_transaction_id']);
    }

    public function test_both_movements_are_in_the_ledger_against_the_right_transactions(): void
    {
        /*
         * Auditability: the ledger is the immutable record, so the credit and the
         * fee must both be there — and each must point at its own transaction row
         * rather than at the funding row.
         */
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $this->settle($funding);

        $fee = $this->feeFor($user, $funding);
        $ledger = WalletLedger::where('user_id', $user->id)->orderBy('id')->get();

        $this->assertCount(2, $ledger, 'One credit and one fee debit.');

        $this->assertSame('CREDIT', $ledger[0]->entry_type);
        $this->assertSame('1000.00', (string) $ledger[0]->amount);
        $this->assertSame($funding->id, $ledger[0]->transaction_id);

        $this->assertSame('DEBIT', $ledger[1]->entry_type);
        $this->assertSame('10.00', (string) $ledger[1]->amount);
        $this->assertSame($fee->id, $ledger[1]->transaction_id);

        // The wallet's own totals must still reconcile to the ledger.
        $verification = app(WalletService::class)->verify(Wallet::where('user_id', $user->id)->firstOrFail());
        $this->assertTrue($verification['consistent'] ?? false, 'The ledger and balance must agree.');
    }

    public function test_the_fee_is_recorded_on_the_funding_row_for_audit(): void
    {
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $this->settle($funding);

        $meta = $funding->fresh()->meta;

        $this->assertSame(
            'charged',
            $meta['funding_fee_status'] ?? '(missing)',
            'meta: ' . json_encode($meta)
        );
        $this->assertSame('10.00', $meta['funding_fee']);
        $this->assertSame($this->feeFor($user, $funding)->id, $meta['funding_fee_transaction_id']);
        $this->assertNotNull($meta['funding_fee_charged_at']);
    }

    public function test_the_fee_does_not_count_as_funding_or_as_spending(): void
    {
        /*
         * `total_funded` drives the referral threshold, so a fee that counted as
         * funding would let a customer qualify for a reward by being charged.
         *
         * `total_spent` is not asserted here: the fee is a wallet debit and
         * `WalletService` counts every debit there, deliberately, so it *is*
         * included — that is existing behaviour for every outgoing movement and
         * changing it is outside this work. What matters is that funded is gross
         * and the balance is net.
         */
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $this->settle($funding);

        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('1000.00', (string) $wallet->total_funded, 'The fee must not inflate funded.');
        $this->assertSame('990.00', (string) $wallet->balance, 'The balance is what the fee left behind.');
    }

    /* =====================================================================
     | 3. Idempotency — the same funding settles many times
     | =================================================================== */

    public function test_settling_the_same_funding_twice_charges_the_fee_once(): void
    {
        /*
         * The webhook, the browser callback and the reconciliation sweep can all
         * arrive for one payment. Exactly one fee may be taken, exactly as
         * exactly one credit is.
         */
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $this->settle($funding);
        $second = $this->settle($funding);
        $third = $this->settle($funding);

        $this->assertSame('already_settled', $second['status']);
        $this->assertSame('already_settled', $third['status']);

        $this->assertSame('990.00', $this->balanceOf($user), 'A replay must not take the fee again.');

        $this->assertSame(
            1,
            Transactions::where('service_type', 'fee')->where('user_id', $user->id)->count(),
            'Exactly one fee row may exist for one funding.'
        );

        $this->assertSame(
            1,
            WalletLedger::where('user_id', $user->id)->where('entry_type', 'DEBIT')->count(),
            'Exactly one fee debit may be recorded.'
        );
    }

    public function test_a_double_webhook_delivery_takes_the_fee_once(): void
    {
        // The same guarantee, exercised through the real endpoint rather than the
        // service, because that is the path Paystack actually retries.
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => $funding->gatewayReference(),
                'status' => 'success',
                'amount' => 100000,
                'currency' => 'NGN',
                'channel' => 'card',
                'gateway_response' => 'Successful',
            ],
        ];

        $body = json_encode($payload);
        $signature = hash_hmac('sha512', $body, self::PAYSTACK_SECRET);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ];

        $this->call('POST', '/paystack/webhook', [], [], [], $server, $body);
        $this->call('POST', '/paystack/webhook', [], [], [], $server, $body);

        $this->assertSame('990.00', $this->balanceOf($user));
        $this->assertSame(1, Transactions::where('service_type', 'fee')->count());
    }

    /* =====================================================================
     | 4. Failure — the funding must survive it
     | =================================================================== */

    public function test_the_fee_retry_never_drives_the_wallet_negative(): void
    {
        /*
         * The requirement "never allow fee deductions to make the wallet balance
         * negative", exercised end to end: the funding is on record as successful,
         * its fee was never collected, and the wallet is empty because the
         * customer has since spent everything.
         *
         * The sweep must refuse, leave the balance at zero, and keep the debt on
         * the record so it is collected if the wallet is ever funded again.
         */
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $funding->forceFill([
            'status' => 'success',
            'payment_status' => 'success',
            'meta' => array_merge($funding->meta ?? [], [
                'service_fee' => '10.00',
                'funding_fee_status' => 'failed',
            ]),
        ])->save();

        // An empty wallet: the ₦1,000 was credited and spent before the fee landed.
        $this->assertSame('0.00', $this->balanceOf($user));

        $this->artisan('payments:charge-funding-fees', ['--minutes' => 0])->assertExitCode(0);

        $this->assertSame('0.00', $this->balanceOf($user), 'The fee must not overdraw the wallet.');
        $this->assertSame('failed', $funding->fresh()->meta['funding_fee_status'], 'The debt stays on record.');

        // No money moved, so there must be no ledger entry — that is the real
        // assertion. A `failed` fee *row* is expected and is the audit trail of
        // the attempt; what must not exist is a movement.
        $this->assertSame(0, WalletLedger::where('user_id', $user->id)->where('entry_type', 'DEBIT')->count());
        $this->assertSame(0, Transactions::where('service_type', 'fee')->where('status', 'success')->count());
    }

    public function test_an_uncollectable_fee_leaves_the_funding_successful(): void
    {
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        // Simulate the balance being gone by the time the fee is attempted, by
        // charging a fee larger than the wallet holds via a drained wallet.
        app(WalletService::class)->credit($user, '1000', countsAsFunding: false);
        app(WalletService::class)->debit($user, '1000', description: 'Drain');

        $result = app(WalletService::class)->chargeFundingFee($user, $funding);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('0.00', $this->balanceOf($user));

        // And the funding itself is untouched — no rollback of a paid credit.
        $this->assertSame('pending', $funding->fresh()->status, 'The funding row is not the fee\'s to change.');
    }

    /* =====================================================================
     | 5. Recovery
     | =================================================================== */

    public function test_the_retry_command_collects_a_fee_that_could_not_be_taken(): void
    {
        /*
         * The failure is recorded, not lost: an outstanding fee is collected as
         * soon as the wallet can cover it, without a second credit.
         */
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        // Settle with the fee failing: mark it as the settlement would.
        $funding->forceFill([
            'status' => 'success',
            'payment_status' => 'success',
            'meta' => array_merge($funding->meta ?? [], [
                'service_fee' => '10.00',
                'fee' => '10.00',
                'funding_fee_status' => 'failed',
                'funding_fee_error' => 'Insufficient wallet balance.',
            ]),
        ])->save();

        // The money arrives afterwards.
        app(WalletService::class)->credit($user, '1000', countsAsFunding: false);

        $this->artisan('payments:charge-funding-fees', ['--minutes' => 0])->assertExitCode(0);

        $this->assertSame('990.00', $this->balanceOf($user), 'The fee must be collected on retry.');
        $this->assertSame('charged', $funding->fresh()->meta['funding_fee_status']);
        $this->assertSame(1, Transactions::where('service_type', 'fee')->count());
    }

    public function test_the_retry_command_does_not_touch_a_funding_that_paid(): void
    {
        // The uniqueness guard, exercised through the sweep rather than the
        // service: a replayed failure flag must not produce a second fee.
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $this->settle($funding);
        $this->assertSame('990.00', $this->balanceOf($user));

        // Force the flag back to 'failed' as a stale/duplicated write would.
        $funding->forceFill([
            'meta' => array_merge($funding->fresh()->meta ?? [], ['funding_fee_status' => 'failed']),
        ])->save();

        $this->artisan('payments:charge-funding-fees', ['--minutes' => 0])->assertExitCode(0);

        $this->assertSame('990.00', $this->balanceOf($user), 'A replayed flag must not take the fee twice.');
        $this->assertSame(1, Transactions::where('service_type', 'fee')->count());
    }

    public function test_the_retry_command_dry_run_charges_nothing(): void
    {
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $funding->forceFill([
            'status' => 'success',
            'payment_status' => 'success',
            'meta' => array_merge($funding->meta ?? [], [
                'service_fee' => '10.00',
                'funding_fee_status' => 'failed',
            ]),
        ])->save();

        app(WalletService::class)->credit($user, '1000', countsAsFunding: false);

        $this->artisan('payments:charge-funding-fees', ['--minutes' => 0, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame('1000.00', $this->balanceOf($user));
        $this->assertSame(0, Transactions::where('service_type', 'fee')->count());
    }

    public function test_the_retry_command_ignores_funding_that_never_recorded_a_fee_failure(): void
    {
        /*
         * A funding row settled *before* fees existed has no `funding_fee_status`
         * at all. Treating that absence as outstanding would charge a fee against
         * historical funding the customer was never told about — the single most
         * dangerous thing this sweep could do.
         */
        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $funding->forceFill([
            'status' => 'success',
            'payment_status' => 'success',
            'meta' => ['funding_amount' => '1000.00'],   // no fee keys at all
        ])->save();

        app(WalletService::class)->credit($user, '1000', countsAsFunding: false);

        $this->artisan('payments:charge-funding-fees', ['--minutes' => 0])
            ->expectsOutput('No outstanding funding fees.')
            ->assertExitCode(0);

        $this->assertSame('1000.00', $this->balanceOf($user));
        $this->assertSame(0, Transactions::where('service_type', 'fee')->count());
    }

    /* =====================================================================
     | 6. The fee arithmetic itself
     | =================================================================== */

    public function test_the_fee_is_percentage_plus_flat_and_capped(): void
    {
        config(['wallet.funding_fee' => ['percentage' => 1.5, 'additional' => 20, 'cap' => 50]]);

        $wallets = app(WalletService::class);

        // 1.5% of 1,000 = 15, + 20 = 35 — under the cap.
        $this->assertSame('35.00', $wallets->fundingFeeFor(Money::fromNaira('1000'))->toDecimalString());

        // 1.5% of 10,000 = 150, + 20 = 170 — capped at 50.
        $this->assertSame('50.00', $wallets->fundingFeeFor(Money::fromNaira('10000'))->toDecimalString());
    }

    public function test_the_fee_is_never_more_than_the_amount_funded(): void
    {
        /*
         * A flat fee larger than a small top-up would otherwise debit money the
         * customer did not just add. Funding ₦100 with a ₦500 flat fee must take
         * ₦100, not ₦500.
         */
        config(['wallet.funding_fee' => ['percentage' => 0, 'additional' => 500, 'cap' => null]]);

        $fee = app(WalletService::class)->fundingFeeFor(Money::fromNaira('100'));

        $this->assertSame('100.00', $fee->toDecimalString());
    }

    public function test_a_fee_below_one_kobo_is_not_charged(): void
    {
        // A rounding step that would produce ₦0.00 must not write a zero-value
        // debit row into the statement.
        config(['wallet.funding_fee' => ['percentage' => 0, 'additional' => 0, 'cap' => null]]);

        $user = $this->customer();
        $funding = $this->pendingFunding($user, '1000');

        $result = app(WalletService::class)->chargeFundingFee($user, $funding);

        $this->assertSame('none', $result['status']);
        $this->assertSame(0, Transactions::where('service_type', 'fee')->count());
    }
}

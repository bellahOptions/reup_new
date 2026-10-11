<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end confirmation of the funding promise, through the real HTTP surface.
 *
 * The promise:
 *
 *   * the customer pays exactly the amount they choose;
 *   * the service fee is deducted from the wallet *after* successful funding;
 *   * it is never added to the checkout.
 *
 * This is deliberately not a unit test of the arithmetic — `FundingFeeTest`
 * covers that. This drives the actual routes: the funding form POST, a signed
 * gateway webhook, and the balance the customer is left with. A unit test can
 * pass while the wiring between the pieces is wrong, and the wiring is what the
 * customer experiences.
 *
 * The fee is configured to a **non-zero** rate here. Against the shipped default
 * of zero, every assertion below would hold trivially and prove nothing.
 */
class FundingPromiseTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_promise';

    /** 1% — so ₦1,000 pays ₦10 of fee and lands ₦990. */
    private const FEE_PERCENT = 1.0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => self::SECRET,
            'services.paystack.public_key' => 'pk_test_promise',
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

    /** Sign and deliver a `charge.success` for a funding row. */
    private function deliverWebhook(Transactions $funding): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => $funding->gatewayReference(),
                'status' => 'success',
                'amount' => \App\Support\Money::fromDatabase($funding->total_amount)->minor(),
                'currency' => 'NGN',
                'channel' => 'card',
                'gateway_response' => 'Successful',
            ],
        ];

        $body = json_encode($payload);

        $this->call('POST', '/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    public function test_the_whole_promise_holds_end_to_end(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'acc'],
        ], 200)]);

        $user = $this->customer();

        // 1. The customer funds ₦1,000 through the form.
        $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '1000',
            'payment_method' => 'paystack',
        ]);

        $funding = Transactions::where('user_id', $user->id)->where('service_type', 'funding')->firstOrFail();

        // 2. What they are charged is what they typed.
        $this->assertSame(
            100000,
            (int) $this->initializedAmount(),
            'Paystack must be asked for exactly ₦1,000 — the checkout total must not carry the fee.'
        );

        $this->assertSame('1000.00', (string) $funding->total_amount);
        $this->assertSame('1000.00', (string) $funding->amount);
        $this->assertSame('0.00', (string) $funding->service_fee);

        // Nothing has been credited or charged yet.
        $this->assertSame('0.00', $this->balanceOf($user));

        // 3. The gateway confirms.
        $this->deliverWebhook($funding);

        // 4. Full credit, then the fee as its own debit: ₦1,000 − ₦10 = ₦990.
        $this->assertSame('990.00', $this->balanceOf($user), 'Net balance must be gross less the fee.');

        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('1000.00', (string) $wallet->total_funded, 'Funded is the gross the customer paid.');

        // 5. Both movements are recorded, and each points at its own row.
        $ledger = WalletLedger::where('user_id', $user->id)->orderBy('id')->get();

        $this->assertCount(2, $ledger, 'Exactly two movements: the funding credit and the fee debit.');
        $this->assertSame('CREDIT', $ledger[0]->entry_type);
        $this->assertSame('1000.00', (string) $ledger[0]->amount);
        $this->assertSame('DEBIT', $ledger[1]->entry_type);
        $this->assertSame('10.00', (string) $ledger[1]->amount);

        // 6. The ledger reconciles to the balance the customer sees.
        $verification = app(WalletService::class)->verify($wallet->fresh());
        $this->assertTrue($verification['consistent'], 'The wallet must reconcile to its ledger.');

        // 7. The fee is auditable from the funding row alone.
        $meta = $funding->fresh()->meta;
        $this->assertSame('charged', $meta['funding_fee_status']);
        $this->assertSame('10.00', $meta['funding_fee']);
        $this->assertSame('990.00', $meta['net_credit']);

        $fee = Transactions::where('service_type', 'fee')->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('10.00', (string) $fee->amount);
        $this->assertSame('debit', $fee->type);
        $this->assertSame($funding->id, $fee->meta['funding_transaction_id']);
    }

    public function test_a_replayed_gateway_notification_does_not_charge_the_fee_twice(): void
    {
        // Paystack retries until it gets a 2xx, and the sweep may also act. The
        // promise has to survive that, because a second ₦10 is a real loss to
        // the customer and invisible unless somebody reads their statement.
        Http::fake(['api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'acc'],
        ], 200)]);

        $user = $this->customer();

        $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '1000',
            'payment_method' => 'paystack',
        ]);

        $funding = Transactions::where('user_id', $user->id)->firstOrFail();

        $this->deliverWebhook($funding);
        $this->deliverWebhook($funding);
        $this->deliverWebhook($funding);

        $this->assertSame('990.00', $this->balanceOf($user), 'Replays must not take the fee again.');
        $this->assertSame(1, Transactions::where('service_type', 'fee')->count());
        $this->assertSame(1, WalletLedger::where('user_id', $user->id)->where('entry_type', 'DEBIT')->count());
    }

    public function test_funding_below_the_minimum_is_still_rejected_before_anything_happens(): void
    {
        // A guard on the path being verified: the fee change must not have
        // loosened the funding rules around it.
        $user = $this->customer();

        $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '50',
            'payment_method' => 'paystack',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Transactions::where('user_id', $user->id)->count());
    }

    /** The amount the application asked Paystack to charge, in kobo. */
    private function initializedAmount(): int
    {
        $sent = 0;

        Http::assertSent(function ($request) use (&$sent) {
            if (str_contains($request->url(), '/transaction/initialize')) {
                $sent = (int) ($request->data()['amount'] ?? 0);
            }

            return true;
        });

        return $sent;
    }
}

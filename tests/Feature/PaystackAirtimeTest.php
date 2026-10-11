<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\ProfitRecord;
use App\Models\Provider;
use App\Models\ProviderNetworkCost;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Orders\FundedPurchase;
use App\Services\SecurityService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviders;
use Tests\TestCase;

/**
 * Paying for airtime with Paystack instead of the wallet.
 *
 * ## The thing being protected
 *
 * The brief's question is how a customer can pay by card while ReUp still keeps its
 * margin. The answer these tests pin is that **the rail does not change the margin**:
 * the customer pays the quoted price, the purchase runs through the *same*
 * `BillPaymentService` pipeline as a wallet purchase, and profit is whatever the
 * pricing engine put in the quote. There is no second pricing path to get wrong.
 *
 * ## What each group of tests is for
 *
 *   1. the customer is sent to the gateway for the **exact quoted price** — not the
 *      price plus a fee, and not the amount without the margin;
 *   2. **nothing is vended before the money arrives**. A redirect is not a payment,
 *      and vending on one would hand goods to anybody who closed the tab;
 *   3. when the payment settles, the product is vended and the **wallet ends where it
 *      started** — credited the price, debited the price;
 *   4. the **funding service fee is not charged**, because this money is already
 *      spent on goods and a fee taken from it would leave the purchase unaffordable;
 *   5. profit is recorded on the sale at the margin the quote specified;
 *   6. a delivery failure **keeps the customer's money**, in their wallet, and leaves
 *      the purchase retryable rather than silently lost.
 */
class PaystackAirtimeTest extends TestCase
{
    use FakesProviders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['fake'],
            'bills.limits.per_minute' => 100,
            'services.paystack.secret_key' => 'sk_test_funded_purchase',
            'pricing.policy.allow_configured_assumptions' => true,
            'pricing.policy.allow_stale' => false,
            'pricing.policy.max_age_hours' => 168,
        ]);
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    private function airtimeCost(string $network = 'mtn', int $discountBps = 300): ProviderNetworkCost
    {
        $provider = Provider::firstOrCreate(
            ['slug' => 'clubkonnect'],
            ['name' => 'ClubKonnect', 'driver' => 'clubkonnect', 'is_active' => true, 'priority' => 100]
        );

        return ProviderNetworkCost::create([
            'provider_id' => $provider->getKey(),
            'capability' => 'airtime',
            'network' => $network,
            'discount_bps' => $discountBps,
            'provider_fee_minor' => 0,
            'currency' => 'NGN',
            'verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function faceValueRule(string $network = 'mtn'): PricingRule
    {
        return PricingRule::create([
            'name' => "Airtime — {$network}",
            'scope' => PricingRule::SCOPE_NETWORK,
            'network' => $network,
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'markup_percentage_bps' => 0,
            'markup_fixed_minor' => 0,
            'minimum_profit_minor' => 0,
            'minimum_margin_bps' => 0,
            'customer_fee_enabled' => false,
            'allow_negative_margin' => false,
            'rounding_step_minor' => 0,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    private function user(string $balance = '0.00'): User
    {
        $user = User::factory()->create();

        app(SecurityService::class)->setPin($user, '5931');

        if (Money::fromNaira($balance)->isPositive()) {
            app(WalletService::class)->credit($user, $balance);
        }

        return $user->fresh();
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    /** The shape Paystack returns for `POST /transaction/initialize`. */
    private function fakeCheckout(string $url = 'https://checkout.paystack.com/funded'): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => $url,
                    'access_code' => 'acc_funded',
                    'reference' => 'psk_funded',
                ],
            ], 200),
        ]);
    }

    private function buyWithCard(User $user, string $network = '01', string $amount = '1000', ?string $key = null)
    {
        return $this->actingAs($user)->postJson(route('airtime.purchase'), [
            'network' => $network,
            'phone' => '08031234567',
            'amount' => $amount,
            'payment_method' => 'paystack',
            'idempotency_key' => $key,
        ]);
    }

    /** The funding row the card purchase created. */
    private function fundingRow(User $user): Transactions
    {
        return Transactions::where('user_id', $user->id)
            ->where('service_type', 'funding')
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * Settle a funding row the way the gateway webhook does.
     *
     * Goes through the real `settle()` rather than writing `status = success`, so the
     * test exercises the amount check, the credit, the fee decision and the vending
     * hook — the four things that matter, in the order they actually happen.
     */
    private function settle(Transactions $funding, ?int $paidKobo = null): array
    {
        config(['services.paystack.assume_live_dva' => true]);

        return app(\App\Services\PaystackService::class)->settle($funding, [
            'status' => 'success',
            'reference' => $funding->gatewayReference(),
            'amount' => $paidKobo ?? Money::fromDatabase($funding->total_amount)->minor(),
            'currency' => 'NGN',
            'channel' => 'card',
            'gateway_response' => 'Successful',
        ]);
    }

    /* =====================================================================
     | 1. The customer is sent to the gateway for the exact price
     =================================================================== */

    public function test_a_card_purchase_sends_the_customer_to_pay_the_quoted_price(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');
        $this->fakeCheckout();

        $user = $this->user();

        $response = $this->buyWithCard($user, '01', '1000');

        $response->assertOk()->assertJson([
            'status' => 'redirect',
            'redirect' => 'https://checkout.paystack.com/funded',
        ]);

        $funding = $this->fundingRow($user);

        /*
         * ₦1,000 exactly. The margin is *inside* this figure — the provider will
         * charge us ₦970 — so the customer pays the price of the airtime and ReUp's
         * ₦30 comes out of the spread, not out of the customer's pocket.
         */
        $this->assertSame('1000.00', (string) $funding->total_amount);
        $this->assertSame('1000.00', (string) $funding->amount);
        $this->assertSame('pending', $funding->status);

        // And the gateway was asked for 100,000 kobo, not a naira figure.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/transaction/initialize')
                && ($request['amount'] ?? null) === 100000;
        });
    }

    public function test_the_purchase_intent_is_recorded_on_the_payment(): void
    {
        /*
         * The intent is what makes the second request possible. Settlement happens in
         * a different request — a webhook or a sweep — with nothing but this row to go
         * on, so everything needed to vend has to be on it.
         */
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');

        $intent = app(FundedPurchase::class)->intent($this->fundingRow($user));

        $this->assertNotNull($intent);
        $this->assertSame('airtime', $intent['product']);
        $this->assertSame('08031234567', $intent['recipient']);
        $this->assertSame(FundedPurchase::STATE_PENDING, $intent['state']);
        $this->assertSame(100000, $intent['quoted_price_minor']);
        $this->assertSame(97000, $intent['quoted_cost_minor']);
        $this->assertSame(3000, $intent['quoted_profit_minor'], 'The quoted margin is ₦30');
        $this->assertSame('01', $intent['provider_params']['network']);
    }

    public function test_a_card_purchase_does_not_require_a_pin(): void
    {
        /*
         * The gateway authorises the payment; a PIN as well would be asking the
         * customer to authorise the same payment twice, once with a four-digit code
         * and once with their bank.
         */
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();

        $user = $this->user();

        $this->actingAs($user)->postJson(route('airtime.purchase'), [
            'network' => '01',
            'phone' => '08031234567',
            'amount' => '1000',
            'payment_method' => 'paystack',
            // No `pin`.
        ])->assertOk();

        $this->assertSame('pending', $this->fundingRow($user)->status);
    }

    public function test_a_wallet_purchase_still_requires_a_pin(): void
    {
        // The other half of the rule: the relaxation is specific to the card rail and
        // must not have opened the wallet rail up.
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');

        $user = $this->user('5000.00');

        $this->actingAs($user)->postJson(route('airtime.purchase'), [
            'network' => '01',
            'phone' => '08031234567',
            'amount' => '1000',
            'payment_method' => 'wallet',
        ])->assertStatus(422)->assertJsonValidationErrors('pin');
    }

    /* =====================================================================
     | 2. Nothing is vended before the money arrives
     =================================================================== */

    public function test_nothing_is_vended_before_the_payment_settles(): void
    {
        /*
         * The failure this prevents: a customer opens the gateway, changes their mind,
         * and closes the tab — and receives airtime anyway. Vending is triggered by
         * settlement, never by the redirect.
         */
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();

        $provider = $this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']);
        $this->withProviders($provider);

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');

        $this->assertSame(0, Transactions::where('user_id', $user->id)->where('type', 'debit')->count());
        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame(FundedPurchase::STATE_PENDING, app(FundedPurchase::class)->intent($this->fundingRow($user))['state']);
    }

    public function test_an_unsettled_payment_is_never_vended(): void
    {
        // Even asked directly: a `pending` funding row is a customer who has not paid.
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');

        $user = $this->user('1000.00');

        $funding = Transactions::create([
            'user_id' => $user->id,
            'reference' => 'FND-UNSETTLED',
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Payment for Airtime',
            'amount' => '1000.00',
            'service_fee' => '0.00',
            'total_amount' => '1000.00',
            'provider' => 'paystack',
            'payment_method' => 'paystack',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        app(FundedPurchase::class)->attach($funding, [
            'product' => 'airtime',
            'amount' => 1000.0,
            'recipient' => '08031234567',
            'provider_params' => ['network' => '01', 'phone' => '08031234567', 'amount' => 1000.0],
        ]);

        $result = app(FundedPurchase::class)->vend($funding->fresh());

        $this->assertSame(FundedPurchase::STATE_PENDING, $result['state']);
        $this->assertNull($result['transaction']);
        $this->assertSame(0, Transactions::where('user_id', $user->id)->where('type', 'debit')->count());
    }

    /* =====================================================================
     | 3. On settlement the purchase is vended and the wallet is unchanged
     =================================================================== */

    public function test_settling_the_payment_vends_the_airtime(): void
    {
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');

        $result = $this->settle($this->fundingRow($user));

        $this->assertSame('settled', $result['status']);

        $intent = app(FundedPurchase::class)->intent($this->fundingRow($user));

        $this->assertSame(FundedPurchase::STATE_VENDED, $intent['state']);

        $product = Transactions::where('user_id', $user->id)->where('type', 'debit')->firstOrFail();

        $this->assertSame('airtime', $product->service_type);
        $this->assertSame('success', $product->status);
        $this->assertSame('1000.00', (string) $product->total_amount);
        $this->assertSame('08031234567', $product->recipient);
        $this->assertSame('mtn', $product->network_code);
    }

    public function test_the_wallet_ends_where_it_started(): void
    {
        /*
         * The mechanism, made visible: the price is credited and then debited, so a
         * customer who starts with nothing ends with nothing — and gets the airtime.
         * That round trip is what lets the purchase reuse the entire wallet pipeline.
         */
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');
        $this->settle($this->fundingRow($user));

        $this->assertSame('0.00', $this->balanceOf($user->fresh()));

        // Two ledger movements for one purchase: the payment in, the purchase out.
        $entries = WalletLedger::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(WalletLedger::DIRECTION_CREDIT, $entries[0]->direction);
        $this->assertSame(WalletLedger::DIRECTION_DEBIT, $entries[1]->direction);
        $this->assertSame('1000.00', (string) $entries[0]->amount);
        $this->assertSame('1000.00', (string) $entries[1]->amount);
    }

    public function test_the_purchase_is_recorded_as_paid_by_paystack(): void
    {
        // So support can answer "I paid by card" by looking at one column, and so the
        // admin console can separate card purchases from wallet ones.
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');
        $this->settle($this->fundingRow($user));

        $product = Transactions::where('user_id', $user->id)->where('type', 'debit')->firstOrFail();

        $this->assertSame('paystack', $product->payment_method);
        $this->assertSame($this->fundingRow($user)->reference, $product->meta['funded_by'] ?? null);
    }

    /* =====================================================================
     | 4. No funding fee is taken from money that is already spent
     =================================================================== */

    public function test_no_funding_fee_is_charged_on_a_purchase_payment(): void
    {
        /*
         * A funding fee is a percentage of a top-up. This money is not a top-up — it
         * is spent on goods milliseconds after it arrives — and taking a fee from it
         * would leave the customer unable to afford the purchase they just paid for.
         *
         * Asserted with a fee configured, because the default is zero and a test
         * against zero would pass without the exemption existing at all.
         */
        config(['wallet.funding_fee.percentage' => 1.5]);

        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');
        $this->settle($this->fundingRow($user));

        $funding = $this->fundingRow($user);

        $this->assertArrayNotHasKey(
            'funding_fee_transaction_id',
            array_filter($funding->meta, fn ($v) => $v !== null),
            'No fee row should have been created for a purchase payment.'
        );

        // And the whole price reached the purchase: the balance is exactly zero
        // rather than a fee's worth short.
        $this->assertSame('0.00', $this->balanceOf($user->fresh()));

        $product = Transactions::where('user_id', $user->id)->where('type', 'debit')->firstOrFail();
        $this->assertSame('1000.00', (string) $product->total_amount);
    }

    public function test_an_ordinary_top_up_still_pays_the_fee(): void
    {
        /*
         * The other side of the exemption. If the flag leaked onto ordinary funding,
         * the platform would silently stop collecting its fee on every top-up — a
         * revenue loss nothing would report.
         */
        config(['wallet.funding_fee.percentage' => 1.5]);

        $user = $this->user();

        $funding = Transactions::create([
            'user_id' => $user->id,
            'reference' => 'FND-TOPUP',
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding — Card',
            'amount' => '1000.00',
            'service_fee' => '0.00',
            'total_amount' => '1000.00',
            'provider' => 'paystack',
            'payment_method' => 'paystack',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->settle($funding);

        $this->assertArrayHasKey('funding_fee_status', $funding->fresh()->meta ?? []);

        // ₦1,000 credited, ₦15 fee taken.
        $this->assertSame('985.00', $this->balanceOf($user->fresh()));
    }

    /* =====================================================================
     | 5. Profit is unchanged by the rail
     =================================================================== */

    public function test_the_sale_records_the_margin_the_quote_specified(): void
    {
        /*
         * The brief's actual question, answered by the accounting table. The customer
         * paid ₦1,000 by card, the provider cost ₦970, and ReUp's profit is ₦30 —
         * identical to the same sale paid from a wallet, because it is the same sale.
         */
        $this->airtimeCost('mtn', 300);
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');
        $this->settle($this->fundingRow($user));

        $product = Transactions::where('user_id', $user->id)->where('type', 'debit')->firstOrFail();
        $record = ProfitRecord::where('transaction_id', $product->id)->first();

        $this->assertNotNull($record, 'A settled card purchase must record profit like any other sale.');
        $this->assertSame('airtime', $record->service_type);
        $this->assertSame(100000, (int) $record->revenue_minor);
        $this->assertSame(97000, (int) $record->provider_cost_minor);
        $this->assertSame(3000, (int) $record->gross_profit_minor, '₦30 of margin, from the spread');
        $this->assertTrue($record->is_realized);
    }

    /* =====================================================================
     | 6. A delivery failure keeps the customer's money
     =================================================================== */

    public function test_an_unknown_outcome_holds_the_money_and_does_not_claim_the_purchase_was_delivered(): void
    {
        /*
         * A provider *timeout* is the one outcome that must not be guessed at: the
         * request was sent and we never learned what happened to it, so the airtime
         * may well have been vended. Retrying could vend it twice; refunding could
         * hand over goods for free.
         *
         * The money is therefore debited and **held** — exactly as it would be for a
         * wallet purchase that timed out — and the row stays open for
         * `payments:auto-resolve` or a status query to settle. What is asserted here
         * is the part specific to the funded path: the purchase is *not* recorded as
         * delivered, so nothing downstream claims the customer received something
         * nobody can confirm.
         */
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'TIMEOUT'], 'unknown'));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');
        $this->settle($this->fundingRow($user));

        $intent = app(FundedPurchase::class)->intent($this->fundingRow($user));

        $this->assertNotSame(FundedPurchase::STATE_VENDED, $intent['state']);

        // The debit exists and is unresolved, not silently successful.
        $product = Transactions::where('user_id', $user->id)->where('type', 'debit')->firstOrFail();
        $this->assertContains($product->status, ['processing', 'unknown', 'pending']);
    }

    public function test_a_refused_purchase_returns_the_money_to_the_customer(): void
    {
        /*
         * The opposite of a timeout: the provider says no, unambiguously. The existing
         * refund path credits the wallet, so the customer ends up whole and can spend
         * the money on something else — which is the honest outcome, and better than a
         * card reversal the platform has no path for.
         */
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        // A definite refusal, not a timeout.
        $this->withProviders($this->fakeProvider('fake', ['status' => 'INVALID_RECIPIENT'], 'failed'));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');
        $this->settle($this->fundingRow($user));

        $intent = app(FundedPurchase::class)->intent($this->fundingRow($user));

        $this->assertNotSame(FundedPurchase::STATE_VENDED, $intent['state']);

        /*
         * The customer's money is back in their wallet: credited by the payment,
         * debited by the purchase, credited again by the refund — so they hold the
         * full amount they paid.
         */
        $this->assertSame('1000.00', $this->balanceOf($user->fresh()));
    }

    public function test_a_vended_purchase_is_never_vended_twice(): void
    {
        /*
         * A webhook plus the callback plus the sweep can all reach the intent. A
         * second vend is a free product that cannot be taken back, so the claim is
         * taken under a row lock before any provider is called.
         */
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');
        $this->settle($this->fundingRow($user));

        $funding = $this->fundingRow($user);

        // Ask twice more, the way a duplicate webhook would.
        app(FundedPurchase::class)->vend($funding->fresh());
        app(FundedPurchase::class)->vend($funding->fresh());

        $this->assertSame(
            1,
            Transactions::where('user_id', $user->id)->where('type', 'debit')->count(),
            'One payment must produce exactly one product transaction.'
        );
    }

    public function test_settling_twice_does_not_vend_twice(): void
    {
        // The same guarantee reached through the settlement path, which is the route a
        // replayed webhook actually takes.
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');

        $funding = $this->fundingRow($user);
        $this->settle($funding);
        $this->settle($funding->fresh());

        $this->assertSame(1, Transactions::where('user_id', $user->id)->where('type', 'debit')->count());
    }

    /* =====================================================================
     | The sweep
     =================================================================== */

    public function test_the_sweep_vends_a_purchase_that_settlement_missed(): void
    {
        /*
         * The case nothing else covers: the process died between the credit and the
         * provider call. The customer has paid, has nothing, and no other sweep is
         * looking — `payments:auto-resolve` resolves the *payment*, finds it settled,
         * and stops.
         */
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');

        $funding = $this->fundingRow($user);

        // Credit the wallet and settle the row *without* the vending hook, simulating
        // a process that stopped between the two.
        app(WalletService::class)->credit(
            user: $user,
            amount: Money::fromDatabase($funding->total_amount),
            countsAsFunding: false,
            description: 'Payment received',
            transaction: $funding,
        );

        $funding->forceFill(['status' => 'success', 'payment_status' => 'success', 'paid_at' => now()])->save();
        $funding->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $this->artisan('payments:vendor-funded-purchases')->assertExitCode(0);

        $this->assertSame(FundedPurchase::STATE_VENDED, app(FundedPurchase::class)->intent($this->fundingRow($user))['state']);
        $this->assertSame(1, Transactions::where('user_id', $user->id)->where('type', 'debit')->count());
    }

    public function test_the_sweep_finds_nothing_when_everything_was_delivered(): void
    {
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');
        $this->settle($this->fundingRow($user));

        $this->artisan('payments:vendor-funded-purchases')->assertExitCode(0);
    }

    public function test_the_sweep_dry_run_changes_nothing(): void
    {
        $this->airtimeCost('mtn');
        $this->faceValueRule('mtn');
        $this->fakeCheckout();
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = $this->user();
        $this->buyWithCard($user, '01', '1000');

        $funding = $this->fundingRow($user);

        app(WalletService::class)->credit(
            user: $user,
            amount: Money::fromDatabase($funding->total_amount),
            countsAsFunding: false,
            description: 'Payment received',
            transaction: $funding,
        );

        $funding->forceFill(['status' => 'success', 'payment_status' => 'success'])->save();
        $funding->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $this->artisan('payments:vendor-funded-purchases', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, Transactions::where('user_id', $user->id)->where('type', 'debit')->count());
        $this->assertSame(FundedPurchase::STATE_PENDING, app(FundedPurchase::class)->intent($this->fundingRow($user))['state']);
    }

    public function test_the_sweep_is_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $entry = $events->first(fn ($event) => str_contains((string) $event->command, 'payments:vendor-funded-purchases'));

        $this->assertNotNull($entry, 'payments:vendor-funded-purchases is not scheduled.');
        $this->assertSame('*/2 * * * *', $entry->expression);
    }
}

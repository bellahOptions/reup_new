<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Pricing\PriceChangeQuote;
use App\Services\ClubKonnectCatalogue;
use App\Services\NetworkResolver;
use App\Services\SecurityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesProviders;
use Tests\TestCase;

/**
 * The price-change decision, end to end.
 *
 * ## The behaviour being replaced
 *
 * A customer was shown ₦940.90, submitted, and got an error: *"the price for that
 * bundle is now ₦864.90 — please confirm the new price and submit again"*. There
 * was nothing to confirm with. The figure they submitted the second time was one
 * they produced themselves, and nothing recorded that they had seen and accepted
 * the new amount.
 *
 * ## What is asserted here
 *
 *   * a moved price produces a **structured decision**, not a dead end;
 *   * nothing is charged before that decision is taken;
 *   * accepting it validates the quote, the user, the bundle, the price, the rule
 *     and the expiry — all server-side, from a token the client cannot forge or
 *     edit;
 *   * a price or a rule that moves *again* asks again rather than charging an
 *     amount nobody agreed to;
 *   * a confirmation is single-use, and the wallet is debited exactly once.
 */
class PriceChangeConfirmationTest extends TestCase
{
    use RefreshDatabase;
    use FakesProviders;

    /** The price the customer is shown before submitting. */
    private const SHOWN = '515.00';

    private array $plan = [
        'network_code' => '01',
        'network' => 'MTN',
        'network_key' => 'mtn',
        'plan_id' => '500',
        'plan_code' => 'MTN-1GB-30D',
        'plan_name' => 'MTN 1GB Monthly',
        'data_volume' => '1 GB',
        'validity' => '30 days',
        'plan_type' => 'SME Data',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['clubkonnect'],
            'bills.limits.per_minute' => 100,
            'pricing.catalogue.quote_ttl_seconds' => 300,
        ]);

        $this->dataMarkupRule();

        // The cost the catalogue is serving right now: ₦500 + 3% = ₦515.
        $this->plan['clubkonnect_price'] = 500.0;

        $this->mockCatalogue();
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    private function dataMarkupRule(int $bps = 300): PricingRule
    {
        return PricingRule::create([
            'name' => 'Data — default markup',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => $bps,
            'minimum_profit_minor' => 1,
            'customer_fee_enabled' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    /**
     * The provider, behind the boundary the application reads it through.
     *
     * The `$plan` property is read at call time, so a test can change the provider
     * cost between two requests — which is exactly what a synchronisation does —
     * without re-mocking anything.
     */
    private function mockCatalogue(): void
    {
        $test = $this;

        $this->mock(ClubKonnectCatalogue::class, function ($mock) use ($test) {
            $mock->shouldReceive('plan')->andReturnUsing(fn () => $test->currentPlan());
            $mock->shouldReceive('plans')->andReturnUsing(fn () => [
                'data_plans' => [$test->currentPlan()],
                'total_plans' => 1,
                'last_updated' => now()->toDateTimeString(),
            ]);
            $mock->shouldReceive('cachedPlans')->andReturn(null);
            $mock->shouldReceive('planPrice')->andReturn(null);
            $mock->shouldReceive('status')->andReturn([
                'last_success_at' => now()->toDateTimeString(),
                'age_seconds' => 5,
                'stale' => false,
                'plan_count' => 1,
                'consecutive_failures' => 0,
                'error_type' => null,
                'last_failure_at' => null,
            ]);
            $mock->shouldReceive('isStale')->andReturn(false);
            $mock->shouldReceive('forget')->andReturnNull();
        });
    }

    /** Public so the mock closure above can read it. */
    public function currentPlan(): array
    {
        return $this->plan;
    }

    /**
     * Simulate a provider cost change landing in a scheduled synchronisation.
     *
     * This is the only way a price moves in production: nothing polls the provider
     * on a customer's behalf.
     */
    private function providerRaisesCostTo(float $naira): void
    {
        $this->plan['clubkonnect_price'] = $naira;
    }

    private function customer(string $balance = '5000.00'): User
    {
        $user = User::factory()->create();
        app(SecurityService::class)->setPin($user, '5931');
        app(WalletService::class)->credit($user, $balance);

        return $user->fresh();
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    /**
     * The payload the airtime page's data form posts.
     *
     * @return array<string,mixed>
     */
    private function purchasePayload(array $overrides = []): array
    {
        return array_merge([
            'data_network' => '01',
            'data_plan' => '500',
            'phone' => '08031234567',
            'plan_name' => 'MTN 1GB Monthly',
            'plan_price' => self::SHOWN,
            'plan_type' => 'SME Data',
            'pin' => '5931',
        ], $overrides);
    }

    private function fakeVending(): void
    {
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));
    }

    private function submit(array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->customer())
            ->postJson(route('data.purchase'), $payload);
    }

    /* =====================================================================
     | An unchanged price goes straight through
     |=================================================================== */

    public function test_an_unchanged_price_completes_the_purchase_with_no_modal(): void
    {
        $this->fakeVending();

        $user = $this->customer();

        $response = $this->actingAs($user)->post(route('data.purchase'), $this->purchasePayload());

        // A normal page flow, not a JSON decision.
        $response->assertRedirect();

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction);
        $this->assertSame(self::SHOWN, (string) $transaction->total_amount);
        $this->assertSame('4,485.00', $this->balanceOf($user) === '4485.00' ? '4,485.00' : $this->balanceOf($user));
    }

    /* =====================================================================
     | A moved price is a decision, not a dead end
     |=================================================================== */

    public function test_a_moved_price_returns_a_structured_confirmation_and_charges_nothing(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        // A scheduled synchronisation lands a lower provider cost while the
        // customer is on the page: ₦420 + 3% = ₦432.60.
        $this->providerRaisesCostTo(420.0);

        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->purchasePayload());

        $response->assertStatus(409);

        $payload = $response->json();

        $this->assertSame('price_changed', $payload['status']);
        $this->assertSame(self::SHOWN, $payload['previous_price'], 'The figure the customer was shown');
        $this->assertSame('432.60', $payload['new_price'], 'The authoritative figure now');
        $this->assertSame('MTN 1GB Monthly', $payload['product']['plan_name']);
        $this->assertSame('MTN', $payload['product']['network']);
        $this->assertNotNull($payload['quote']['identifier']);
        $this->assertGreaterThan(time(), $payload['quote']['expires_at_unix']);
        $this->assertSame('decrease', $payload['direction']);

        // A modal needs a sentence to read, and it must name both figures.
        $this->assertStringContainsString('515.00', $payload['message']);
        $this->assertStringContainsString('432.60', $payload['message']);
        $this->assertStringContainsString('Would you like to continue', $payload['message']);

        // Nothing moved.
        $this->assertSame('5000.00', $this->balanceOf($user));
        $this->assertSame(0, Transactions::where('user_id', $user->id)->count());
        $this->assertSame([], $this->providerCalls, 'No provider was called');
    }

    public function test_the_confirmation_is_bound_to_the_user_who_was_shown_the_price(): void
    {
        $buyer = $this->customer();
        $other = $this->customer();

        $this->fakeVending();
        $this->providerRaisesCostTo(420.0);

        $token = $this->actingAs($buyer)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->json('quote.identifier');

        // Another account presents it.
        $response = $this->actingAs($other)->postJson(route('data.purchase'), $this->purchasePayload([
            'price_confirmation' => $token,
        ]));

        $response->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_WRONG_USER, $response->json('reason'));
        $this->assertSame('5000.00', $this->balanceOf($other));
        $this->assertSame(0, Transactions::where('user_id', $other->id)->count());
    }

    /* =====================================================================
     | Accepting
     |=================================================================== */

    public function test_accepting_the_new_price_completes_the_purchase_at_it(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->providerRaisesCostTo(420.0);

        $token = $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->json('quote.identifier');

        // Still ₦432.60 when they accept.
        $response = $this->actingAs($user)->post(route('data.purchase'), $this->purchasePayload([
            'plan_price' => '432.60',
            'price_confirmation' => $token,
            'idempotency_key' => str_repeat('c', 24),
        ]));

        $response->assertRedirect(route('transactions.success', Transactions::where('user_id', $user->id)->value('reference') ?? ''));

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction, 'The accepted purchase must go through');
        $this->assertSame('432.60', (string) $transaction->total_amount, 'Charged the accepted new price');
        $this->assertSame('4567.40', $this->balanceOf($user), '₦5,000 − ₦432.60');

        // The snapshot records the cost the price was derived from, so the margin
        // on the accepted sale is answerable.
        $this->assertSame(42000, (int) $transaction->pricingSnapshot->provider_cost_minor);
        $this->assertSame(43260, (int) $transaction->pricingSnapshot->customer_price_minor);
        $this->assertSame(1260, (int) $transaction->pricingSnapshot->gross_profit_minor);

        // And the acceptance is recorded, so support can see it rather than
        // inferring it from a mismatch.
        $this->assertNotNull($transaction->meta['price_confirmed_at'] ?? null);
    }

    public function test_accepting_an_increased_price_still_pays_the_new_figure(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        // ₦900 + 3% = ₦927.00.
        $this->providerRaisesCostTo(900.0);

        $token = $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->assertStatus(409)
            ->json('quote.identifier');

        $this->actingAs($user)->post(route('data.purchase'), $this->purchasePayload([
            'plan_price' => '927.00',
            'price_confirmation' => $token,
            'idempotency_key' => str_repeat('d', 24),
        ]))->assertRedirect();

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame('927.00', (string) $transaction->total_amount);
        $this->assertSame('4073.00', $this->balanceOf($user));
    }

    /* =====================================================================
     | Cancelling
     |=================================================================== */

    public function test_cancelling_leaves_the_wallet_untouched_and_the_inputs_preserved(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->providerRaisesCostTo(420.0);

        $response = $this->actingAs($user)->post(route('data.purchase'), $this->purchasePayload());

        /*
         * The modal's "Cancel transaction" issues no request at all — it closes
         * the dialog and clears the token. What the *server* must guarantee is
         * that the refused submission itself charged nothing and kept the form's
         * values, which is what this asserts.
         */
        $response->assertSessionHasInput('data_plan', '500');
        $response->assertSessionHasInput('phone', '08031234567');
        $response->assertSessionHasInput('data_network', '01');
        $response->assertSessionHasInput('plan_price', self::SHOWN);
        $response->assertSessionHas('price_change');

        $this->assertSame('5000.00', $this->balanceOf($user));
        $this->assertSame(0, Transactions::where('user_id', $user->id)->count());
        $this->assertSame([], $this->providerCalls);
    }

    /**
     * A cancelled purchase can be started again from the same page.
     */
    public function test_a_cancelled_purchase_can_be_submitted_again_at_the_new_price(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->providerRaisesCostTo(420.0);

        // First attempt: refused, nothing charged, token issued and discarded.
        $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->assertStatus(409);

        $this->assertSame('5000.00', $this->balanceOf($user));

        // The customer reloads, sees ₦432.60, and submits that.
        $response = $this->actingAs($user)->post(route('data.purchase'), $this->purchasePayload([
            'plan_price' => '432.60',
        ]));

        $response->assertRedirect();

        $this->assertSame('432.60', (string) Transactions::where('user_id', $user->id)->latest('id')->firstOrFail()->total_amount);
    }

    /* =====================================================================
     | Expiry, invalidity and moving again
     | =================================================================== */

    public function test_an_expired_confirmation_asks_again_and_charges_nothing(): void
    {
        $user = $this->customer();
        $this->fakeVending();
        $this->providerRaisesCostTo(420.0);

        $token = $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->json('quote.identifier');

        /*
         * The confirmation lapses — an evicted cache entry and an expired one are
         * deliberately indistinguishable, so this is the same state.
         *
         * The key is asked for rather than spelled out: it became namespaced when
         * Social Boost arrived, and a hard-coded key would have kept passing while
         * the application used a different one — which is exactly what happened
         * here, and what this call prevents from happening again.
         */
        Cache::forget(PriceChangeQuote::cacheKey($token, PriceChangeQuote::NAMESPACE_DATA));

        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->purchasePayload([
            'plan_price' => '432.60',
            'price_confirmation' => $token,
        ]));

        $response->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_UNKNOWN, $response->json('reason'));
        $this->assertSame('432.60', $response->json('new_price'), 'And it carries the current figure');
        $this->assertSame('5000.00', $this->balanceOf($user));
    }

    public function test_an_invented_confirmation_token_is_refused(): void
    {
        $user = $this->customer();
        $this->fakeVending();
        $this->providerRaisesCostTo(420.0);

        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->purchasePayload([
            'plan_price' => '432.60',
            'price_confirmation' => str_repeat('x', 48),
        ]));

        $response->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_UNKNOWN, $response->json('reason'));
        $this->assertSame('5000.00', $this->balanceOf($user));
    }

    public function test_a_confirmation_for_a_different_bundle_is_refused(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        // A confirmation is issued for the 1GB bundle at its current price.
        $this->providerRaisesCostTo(420.0);
        $token = $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->json('quote.identifier');

        // The catalogue's cheapest bundle changes to a different plan while the
        // token is held.
        $this->plan = array_merge($this->plan, [
            'plan_id' => '999',
            'plan_code' => 'MTN-9GB-30D',
            'plan_name' => 'MTN 9GB Monthly',
            'clubkonnect_price' => 420.0,
        ]);

        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->purchasePayload([
            'data_plan' => '999',
            'plan_name' => 'MTN 9GB Monthly',
            'plan_price' => '432.60',
            'price_confirmation' => $token,
        ]));

        $response->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_WRONG_PRODUCT, $response->json('reason'));
        $this->assertSame('5000.00', $this->balanceOf($user));
    }

    /**
     * The loop the brief is explicit about: the price changes *again* while the
     * modal is open.
     *
     * The customer must be asked again for the latest figure. Silently charging
     * the second price would be charging an amount nobody agreed to, and failing
     * with an error would be the dead end this whole flow replaced.
     */
    public function test_a_price_that_moves_again_asks_again_rather_than_charging(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->providerRaisesCostTo(420.0);

        $token = $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->json('quote.identifier');

        // The provider moves again before the customer clicks Continue:
        // ₦600 + 3% = ₦618.00.
        $this->providerRaisesCostTo(600.0);

        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->purchasePayload([
            'plan_price' => '432.60',
            'price_confirmation' => $token,
        ]));

        $response->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_PRICE_MOVED, $response->json('reason'));
        $this->assertSame('432.60', $response->json('previous_price'));
        $this->assertSame('618.00', $response->json('new_price'));

        // A *fresh* confirmation comes with it, so the dialog can be shown again
        // without a round trip and without the customer starting over.
        $this->assertNotSame($token, $response->json('quote.identifier'));
        $this->assertNotNull($response->json('quote.identifier'));

        $this->assertSame('5000.00', $this->balanceOf($user));
        $this->assertSame(0, Transactions::where('user_id', $user->id)->count());
    }

    /**
     * A pricing-rule edit is the other way a price moves, and it must invalidate a
     * confirmation even when the arithmetic happens to agree.
     */
    public function test_a_pricing_rule_change_invalidates_a_held_confirmation(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->providerRaisesCostTo(420.0);

        $token = $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->json('quote.identifier');

        // A Super Admin replaces the rule with a different one at the same rate,
        // so the price is unchanged but the rule that produced it is not.
        $original = PricingRule::firstOrFail();
        $original->forceFill(['is_active' => false])->save();

        $this->dataMarkupRule(300);

        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->purchasePayload([
            'plan_price' => '432.60',
            'price_confirmation' => $token,
        ]));

        $response->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_RULE_CHANGED, $response->json('reason'));
        $this->assertSame('432.60', $response->json('new_price'), 'The amount is the same…');
        $this->assertSame('5000.00', $this->balanceOf($user), '…and the customer is still asked');
    }

    public function test_a_bundle_that_can_no_longer_be_priced_is_reported_as_unavailable(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->providerRaisesCostTo(420.0);

        $token = $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->json('quote.identifier');

        // The provider stops quoting a cost for it, so it cannot be priced at all.
        $this->plan['clubkonnect_price'] = 0.0;

        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->purchasePayload([
            'plan_price' => '432.60',
            'price_confirmation' => $token,
        ]));

        /*
         * A bundle with no usable cost is a *refusal*, not a price-change: there
         * is no new figure to offer, so the answer says the bundle is unavailable
         * rather than inviting the customer to accept a number that does not
         * exist. Either way nothing is charged.
         */
        $response->assertStatus(409);
        $this->assertSame('refused', $response->json('status'));
        $this->assertStringContainsString('unavailable', strtolower((string) $response->json('message')));
        $this->assertSame('5000.00', $this->balanceOf($user));
        $this->assertSame(0, Transactions::where('user_id', $user->id)->count());
    }

    /* =====================================================================
     | Duplicate submissions and double debits
     | =================================================================== */

    public function test_a_replayed_confirmation_is_consumed_only_once(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->providerRaisesCostTo(420.0);

        $token = $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->json('quote.identifier');

        $payload = $this->purchasePayload([
            'plan_price' => '432.60',
            'price_confirmation' => $token,
            'idempotency_key' => str_repeat('e', 24),
        ]);

        $this->actingAs($user)->post(route('data.purchase'), $payload)->assertRedirect();

        $afterFirst = $this->balanceOf($user);

        $this->assertSame('4567.40', $afterFirst);

        // The same token again.
        $second = $this->actingAs($user)->postJson(route('data.purchase'), $payload);

        $second->assertStatus(409);
        $this->assertSame(PriceChangeQuote::REASON_UNKNOWN, $second->json('reason'));

        // Exactly one transaction, one debit, and one wallet movement.
        $this->assertSame(1, Transactions::where('user_id', $user->id)->count());
        $this->assertSame($afterFirst, $this->balanceOf($user), 'The wallet is debited once');
        $this->assertSame(1, \App\Models\WalletLedger::where('user_id', $user->id)
            ->where('direction', 'debit')
            ->count());
    }

    /**
     * A double-click on "Continue" is the ordinary version of the same hazard.
     */
    public function test_two_identical_confirmations_at_once_debit_once(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->providerRaisesCostTo(420.0);

        $token = $this->actingAs($user)
            ->postJson(route('data.purchase'), $this->purchasePayload())
            ->json('quote.identifier');

        $payload = $this->purchasePayload([
            'plan_price' => '432.60',
            'price_confirmation' => $token,
            'idempotency_key' => str_repeat('f', 24),
        ]);

        $this->actingAs($user)->post(route('data.purchase'), $payload);
        $this->actingAs($user)->post(route('data.purchase'), $payload);

        $this->assertSame(1, Transactions::where('user_id', $user->id)->count());
        $this->assertSame('4567.40', $this->balanceOf($user));
    }

    /**
     * The confirmation token is the *only* thing that makes an accepted price
     * acceptable. Without it, a posted price is just a claim.
     */
    public function test_a_client_supplied_price_cannot_buy_a_bundle_cheaply(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        // The page claims the bundle costs ₦1.00.
        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->purchasePayload([
            'plan_price' => '1.00',
        ]));

        $response->assertStatus(409);
        $this->assertSame(self::SHOWN, $response->json('new_price'), 'The server prices it, not the page');
        $this->assertSame('5000.00', $this->balanceOf($user));
    }

    /* =====================================================================
     | Provider timeout: reconcile, never retry or refund on a guess
     | ==================================================================== */

    /**
     * A connection the provider never answered is held, not refunded and not
     * retried — the order may have been vended.
     */
    public function test_a_provider_timeout_holds_the_purchase_and_refunds_nothing(): void
    {
        $user = $this->customer();

        // The fake's `unknown` outcome: the request left and no verdict came back.
        $this->withProviders($this->fakeProvider('fake', null, 'unknown'));

        $this->actingAs($user)->post(route('data.purchase'), $this->purchasePayload([
            'idempotency_key' => str_repeat('g', 24),
        ]));

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame('unknown', $transaction->status, 'Held for reconciliation');
        $this->assertNotSame('refunded', $transaction->payment_status, 'Never refunded on a guess');

        // The money is still debited — the customer may well have been served.
        $this->assertSame('4485.00', $this->balanceOf($user));

        // No ledger credit was written for it.
        $this->assertSame(0, \App\Models\WalletLedger::where('user_id', $user->id)
            ->where('entry_type', 'refund')
            ->count());
    }

    /**
     * A definite provider refusal is refunded, and the refund is recorded once.
     */
    public function test_a_definite_provider_refusal_is_refunded_in_full(): void
    {
        $user = $this->customer();

        $this->withProviders($this->fakeProvider('fake', ['status' => 'INVALID_RECIPIENT']));

        $this->actingAs($user)->post(route('data.purchase'), $this->purchasePayload([
            'idempotency_key' => str_repeat('h', 24),
        ]));

        /*
         * The *purchase* row, not the refund row. A refund is its own credit
         * transaction and therefore the newest row for this customer, so
         * `latest('id')` alone would assert against the refund.
         */
        $transaction = Transactions::where('user_id', $user->id)
            ->where('type', 'debit')
            ->latest('id')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame('failed', $transaction->status);
        $this->assertSame('refunded', $transaction->payment_status);
        $this->assertSame('5000.00', $this->balanceOf($user), 'Refunded in full');

        // Exactly one refund, and it is recorded against the purchase.
        $refund = Transactions::where('user_id', $user->id)->where('service_type', 'refund')->get();

        $this->assertCount(1, $refund);
        $this->assertSame((int) $transaction->id, (int) $refund->first()->meta['refunded_transaction_id']);
    }

    /* =====================================================================
     | The price list and the charge use one source
     | ==================================================================== */

    /**
     * What the price list shows and what the purchase charges come from the same
     * service, so they cannot drift — which is what made the original mismatch
     * possible.
     */
    public function test_the_displayed_price_and_the_charged_price_agree(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $shown = app(\App\Services\DataPriceService::class)->quote('500');

        $this->assertSame(51500, $shown['price_minor']);

        $this->actingAs($user)->post(route('data.purchase'), $this->purchasePayload());

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame('515.00', (string) $transaction->total_amount);
        $this->assertSame(
            $shown['price_minor'],
            (int) $transaction->pricingSnapshot->customer_price_minor
        );
    }

    /**
     * A network the form claims that the bundle does not belong to is refused.
     *
     * ## This test used to assert a silent correction
     *
     * The previous behaviour recorded the catalogue's network and carried on, so a
     * purchase that said "Airtel" was stored as MTN and dispatched as MTN. That is
     * right about *which* network is authoritative and wrong about what to do when
     * the two disagree: the customer had just told the page to send Airtel data, and
     * the page was about to send MTN data instead without saying so.
     *
     * The bundle's own network is still authoritative — it is the plan that
     * determines where the data can go — but a disagreement is now a refusal the
     * customer can act on, which is the only outcome that cannot spend money on the
     * wrong network.
     */
    public function test_a_bundle_from_another_network_is_refused_rather_than_silently_reassigned(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        // The form claims Airtel; the catalogue row is MTN.
        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->purchasePayload([
            'data_network' => '04',
        ]));

        $response->assertStatus(409);
        $this->assertStringContainsString('different network', (string) $response->json('message'));

        // Nothing was bought and nothing was charged.
        $this->assertSame(
            0,
            Transactions::where('user_id', $user->id)->count(),
            'A mismatched network and bundle must not create a transaction'
        );
        $this->assertSame('5000.00', $this->balanceOf($user));
    }

    /* =====================================================================
     | The modal is on the page, with the contract the JavaScript needs
     | ==================================================================== */

    public function test_the_purchase_page_renders_the_confirmation_modal_and_its_contract(): void
    {
        $user = $this->customer();

        $response = $this->actingAs($user)->get(route('airtime-data.index'));

        $response->assertOk();

        // The dialog itself.
        $response->assertSee('Data bundle price updated', false);
        $response->assertSee('Continue at new price', false);
        $response->assertSee('Cancel transaction', false);
        $response->assertSee('role="dialog"', false);
        $response->assertSee('aria-modal="true"', false);

        // The Alpine component, which the modal is inert without. A `@push` in
        // the wrong place emits nothing at all, so this is the assertion that can
        // actually catch it.
        $response->assertSee("Alpine.data('priceChange'", false);
        $response->assertSee('reup:price-changed', false);

        // The hidden field the confirmation token travels in.
        $response->assertSee('name="price_confirmation"', false);
    }

    public function test_the_purchase_page_shows_the_network_brand_marks(): void
    {
        $user = $this->customer();

        $response = $this->actingAs($user)->get(route('airtime-data.index'));

        $response->assertOk();

        // The real assets, not the placeholder monograms.
        $response->assertSee('mtn.jpeg', false);
        $response->assertSee('airtel.jpeg', false);
        $response->assertSee('glo.png', false);
        $response->assertSee('t2.png', false);

        // And the current branding for 9mobile.
        $response->assertSee('T2mobile', false);
    }
}

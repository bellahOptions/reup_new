<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BachsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bachs — the fallback card rail.
 *
 * The cases that matter are the ones where acting wrongly costs money: crediting
 * an amount the gateway never collected, crediting twice, accepting an unsigned
 * webhook, and charging a customer through a gateway the app cannot reconcile.
 */
class BachsFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const PAYSTACK_SECRET = 'sk_test_primary';
    private const BACHS_SECRET = 'sk_sandbox_fallback';
    private const BACHS_WEBHOOK_SECRET = 'whsec_bachs_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => self::PAYSTACK_SECRET,
            'services.bachs.enabled' => true,
            'services.bachs.secret_key' => self::BACHS_SECRET,
            'services.bachs.webhook_secret' => self::BACHS_WEBHOOK_SECRET,
            'services.bachs.base_url' => 'https://sandbox-api.bachs.io',
            'services.bachs.payment_method_type' => 'NGN_CARD',
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
    }

    private function pendingFunding(User $user, string $amount = '5000.00'): Transactions
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

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    /** A Bachs checkout-session response. */
    private function fakeCheckout(string $checkoutId = 'chk_test_1', string $url = 'https://checkout.bachs.io/c/test1'): void
    {
        Http::fake([
            'sandbox-api.bachs.io/v1/checkout-sessions' => Http::response([
                'checkout_id' => $checkoutId,
                'checkout_url' => $url,
                'status' => 'open',
            ], 200),
        ]);
    }

    /** A Bachs webhook body plus its valid signature headers. */
    private function signedWebhook(array $data, string $type = 'collection.succeeded', ?int $timestamp = null): array
    {
        $body = json_encode([
            'id' => 'evt_' . bin2hex(random_bytes(4)),
            'type' => $type,
            'created_at' => now()->toIso8601String(),
            'organization_id' => 'acct_test',
            'data' => $data,
        ]);

        $timestamp ??= time();

        return [
            'body' => $body,
            'headers' => [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_BACHS_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_BACHS_SIGNATURE' => hash_hmac('sha256', $timestamp . '.' . $body, self::BACHS_WEBHOOK_SECRET),
                'HTTP_X_BACHS_SIGNATURE_V2' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, self::BACHS_WEBHOOK_SECRET),
            ],
        ];
    }

    /**
     * Deliver a signed webhook for a specific transaction, as Bachs would.
     *
     * `$data` is merged over the defaults so a test can override just the field
     * it is probing (an underpayment, a foreign currency) without restating the
     * reference every time.
     */
    private function deliverWebhook(
        Transactions $transaction,
        array $data = [],
        string $type = 'collection.succeeded',
        ?int $timestamp = null
    ) {
        $hook = $this->signedWebhook(array_merge([
            'charge_id' => 'ch_test',
            'checkout_id' => ($transaction->meta ?? [])['bachs_checkout_id'] ?? null,
            'status' => 'succeeded',
            'amount' => Money::fromDatabase($transaction->total_amount)->toDecimalString(),
            'currency' => 'NGN',
            'reference' => $transaction->gatewayReference(),
        ], $data), $type, $timestamp);

        return $this->call('POST', '/bachs/webhook', [], [], [], $hook['headers'], $hook['body']);
    }

    /* =====================================================================
     | 1. Fallback routing
     |=================================================================== */

    public function test_paystack_still_serves_card_payments_when_it_works(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/primary',
                    'access_code' => 'primary',
                    'reference' => 'primary',
                ],
            ]),
        ]);

        $user = $this->user();

        $response = $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '5000',
            'payment_method' => 'paystack',
        ]);

        $response->assertRedirect('https://checkout.paystack.com/primary');

        // Bachs was never asked.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'bachs'));
    }

    public function test_card_funding_falls_back_to_bachs_when_paystack_refuses(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'message' => 'Invalid key'], 401),
            'sandbox-api.bachs.io/v1/checkout-sessions' => Http::response([
                'checkout_id' => 'chk_fallback',
                'checkout_url' => 'https://checkout.bachs.io/c/fallback',
                'status' => 'open',
            ], 200),
        ]);

        $user = $this->user();

        $response = $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '5000',
            'payment_method' => 'paystack',
        ]);

        $response->assertRedirect('https://checkout.bachs.io/c/fallback');

        $transaction = Transactions::where('user_id', $user->id)->firstOrFail();

        // The gateway is recorded, so settlement and support can tell which
        // rail actually took the money.
        $this->assertSame('bachs', $transaction->meta['gateway'] ?? null);
        $this->assertSame('chk_fallback', $transaction->meta['bachs_checkout_id'] ?? null);
        $this->assertSame('pending', $transaction->status);
    }

    public function test_the_bachs_checkout_is_created_in_naira_with_a_decimal_string_amount(): void
    {
        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'down'], 503),
            'sandbox-api.bachs.io/*' => Http::response([
                'checkout_id' => 'chk_assert',
                'checkout_url' => 'https://checkout.bachs.io/c/assert',
            ], 200),
        ]);

        $user = $this->user();

        $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '5000',
            'payment_method' => 'paystack',
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'checkout-sessions')) {
                return false;
            }

            $body = $request->data();

            // Bachs takes decimal strings, never minor units — the opposite of
            // Paystack's integer kobo. Getting this wrong under-charges by 100x.
            //
            // The amount asked for is the transaction TOTAL (amount + our
            // processing fee), not the top-up the customer typed: the funding
            // page quotes the fee as payable on top.
            return $body['pricing']['currency'] === 'NGN'
                && $body['pricing']['amount'] === '5095.00'
                && $body['payment_method_types'] === ['NGN_CARD']
                && $body['customer']['email'] === 'ada@example.com'
                && ! empty($body['reference']);
        });
    }

    public function test_the_fallback_is_skipped_when_bachs_is_not_enabled(): void
    {
        config(['services.bachs.enabled' => false]);

        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401),
        ]);

        $user = $this->user();

        $response = $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '5000',
            'payment_method' => 'paystack',
        ]);

        $response->assertRedirect(route('wallet.fund'));
        $response->assertSessionHas('error');

        // Failed, not left pending for the reconciliation sweep to age out.
        $this->assertSame('failed', Transactions::where('user_id', $user->id)->firstOrFail()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'bachs'));
    }

    public function test_the_fallback_is_skipped_when_the_key_is_missing(): void
    {
        config(['services.bachs.secret_key' => null]);

        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401),
        ]);

        $user = $this->user();

        $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '5000',
            'payment_method' => 'paystack',
        ])->assertRedirect(route('wallet.fund'));

        $this->assertSame('failed', Transactions::where('user_id', $user->id)->firstOrFail()->status);
    }

    public function test_the_attempt_is_failed_when_both_gateways_refuse(): void
    {
        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401),
            'sandbox-api.bachs.io/*' => Http::response(['message' => 'CHECKOUT_RESTRICTION_LEAVES_NO_PAYMENT_METHOD'], 400),
        ]);

        $user = $this->user();

        $response = $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '5000',
            'payment_method' => 'paystack',
        ]);

        $response->assertRedirect(route('wallet.fund'));

        $transaction = Transactions::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('failed', $transaction->status);
        $this->assertSame('failed', $transaction->payment_status);
        $this->assertNotNull($transaction->completed_at);
        $this->assertSame('0.00', $this->balanceOf($user));
    }

    public function test_card_is_not_offered_when_no_card_gateway_is_usable(): void
    {
        config([
            'services.paystack.secret_key' => null,
            'services.bachs.enabled' => false,
        ]);

        $this->actingAs($this->user())
            ->get(route('wallet.fund'))
            ->assertOk()
            ->assertSee('Card payment is not configured');
    }

    public function test_card_is_offered_when_only_bachs_is_usable(): void
    {
        config(['services.paystack.secret_key' => null]);

        $this->actingAs($this->user())
            ->get(route('wallet.fund'))
            ->assertOk()
            ->assertDontSee('Card payment is not configured');
    }

    /* =====================================================================
     | 2. Settlement
     |=================================================================== */

    public function test_a_signed_successful_webhook_credits_the_exact_amount(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $transaction->forceFill([
            'meta' => ['gateway' => 'bachs', 'bachs_checkout_id' => 'chk_paid'],
        ])->save();

        $this->deliverWebhook($transaction, [
            'charge_id' => 'ch_123',
        ])->assertOk()->assertJson(['status' => 'settled']);

        $this->assertSame('5000.00', $this->balanceOf($user));
        $this->assertSame('success', $transaction->fresh()->status);
        $this->assertSame('bachs', $transaction->fresh()->meta['gateway']);
    }

    public function test_an_unsigned_webhook_is_rejected_and_credits_nothing(): void
    {
        $user = $this->user();
        $this->pendingFunding($user, '5000.00');

        $this->call('POST', '/bachs/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'type' => 'collection.succeeded',
            'data' => ['reference' => 'anything', 'status' => 'succeeded', 'amount' => '5000.00', 'currency' => 'NGN'],
        ]))->assertStatus(401);

        $this->assertSame('0.00', $this->balanceOf($user));
    }

    /**
     * A captured body replayed later must not settle a second time or stay
     * replayable forever.
     */
    public function test_a_stale_webhook_delivery_is_rejected(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $hook = $this->signedWebhook([
            'charge_id' => 'ch_old',
            'checkout_id' => 'chk_old',
            'status' => 'succeeded',
            'amount' => '5000.00',
            'currency' => 'NGN',
        ], timestamp: time() - 3600);

        $this->call('POST', '/bachs/webhook', [], [], [], $hook['headers'], $hook['body'])
            ->assertStatus(401);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_a_wrong_signature_is_rejected(): void
    {
        $user = $this->user();
        $this->pendingFunding($user, '5000.00');

        $hook = $this->signedWebhook([
            'charge_id' => 'ch_1',
            'checkout_id' => 'chk_1',
            'status' => 'succeeded',
            'amount' => '5000.00',
            'currency' => 'NGN',
        ]);

        $hook['headers']['HTTP_X_BACHS_SIGNATURE'] = str_repeat('a', 64);
        unset($hook['headers']['HTTP_X_BACHS_SIGNATURE_V2']);

        $this->call('POST', '/bachs/webhook', [], [], [], $hook['headers'], $hook['body'])
            ->assertStatus(401);

        $this->assertSame('0.00', $this->balanceOf($user));
    }

    public function test_a_webhook_underpaying_the_row_does_not_credit(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $transaction->forceFill([
            'meta' => ['gateway' => 'bachs', 'bachs_checkout_id' => 'chk_under'],
        ])->save();

        $this->deliverWebhook($transaction, [
            'charge_id' => 'ch_under',
            'amount' => '50.00',
        ])->assertOk()->assertJson(['status' => 'amount_mismatch']);

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame('failed', $transaction->fresh()->status);
    }

    public function test_a_usd_charge_against_a_naira_row_does_not_credit(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $transaction->forceFill([
            'meta' => ['gateway' => 'bachs', 'bachs_checkout_id' => 'chk_usd'],
        ])->save();

        $this->deliverWebhook($transaction, [
            'charge_id' => 'ch_usd',
            'currency' => 'USD',
        ])->assertOk()->assertJson(['status' => 'currency_mismatch']);

        $this->assertSame('0.00', $this->balanceOf($user));
    }

    public function test_a_charge_from_another_checkout_does_not_credit(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $transaction->forceFill([
            'meta' => ['gateway' => 'bachs', 'bachs_checkout_id' => 'chk_mine'],
        ])->save();

        // The reference is what routes the webhook, and it is ours, so this
        // reaches settle() — which must then refuse the foreign checkout id.
        $this->deliverWebhook($transaction, [
            'charge_id' => 'ch_other',
            'checkout_id' => 'chk_someone_else',
        ])->assertOk();

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_a_duplicate_webhook_credits_once(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $transaction->forceFill([
            'meta' => ['gateway' => 'bachs', 'bachs_checkout_id' => 'chk_dup'],
        ])->save();

        $this->deliverWebhook($transaction, ['charge_id' => 'ch_dup'])->assertOk();
        $second = $this->deliverWebhook($transaction, ['charge_id' => 'ch_dup']);

        $second->assertOk()->assertJson(['status' => 'already_settled']);

        $this->assertSame('5000.00', $this->balanceOf($user));
    }

    public function test_a_failed_collection_marks_the_row_failed_and_credits_nothing(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $transaction->forceFill([
            'meta' => ['gateway' => 'bachs', 'bachs_checkout_id' => 'chk_failed'],
        ])->save();

        $this->deliverWebhook($transaction, [
            'charge_id' => 'ch_failed',
            'status' => 'failed',
        ], 'collection.failed')->assertOk();

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame('failed', $transaction->fresh()->status);
    }

    public function test_a_webhook_for_an_unknown_reference_is_acknowledged_not_retried(): void
    {
        $hook = $this->signedWebhook([
            'charge_id' => 'ch_x',
            'checkout_id' => 'chk_x',
            'status' => 'succeeded',
            'amount' => '5000.00',
            'currency' => 'NGN',
            'reference' => 'NOT-OURS',
        ]);

        $this->call('POST', '/bachs/webhook', [], [], [], $hook['headers'], $hook['body'])
            ->assertOk()
            ->assertJson(['status' => 'unknown_reference']);
    }

    public function test_the_webhook_refuses_to_run_without_a_signing_secret(): void
    {
        config(['services.bachs.webhook_secret' => null]);

        $hook = $this->signedWebhook(['reference' => 'x']);

        $this->call('POST', '/bachs/webhook', [], [], [], $hook['headers'], $hook['body'])
            ->assertStatus(503);
    }

    /* =====================================================================
     | 3. Browser callback
     |=================================================================== */

    public function test_the_callback_settles_a_paid_checkout(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $transaction->forceFill([
            'api_reference' => 'chk_callback',
            'meta' => ['gateway' => 'bachs', 'bachs_checkout_id' => 'chk_callback', 'user_id' => (string) $user->id],
        ])->save();

        Http::fake([
            'sandbox-api.bachs.io/v1/checkout-sessions/chk_callback' => Http::response([
                'checkout_id' => 'chk_callback',
                'status' => 'completed',
                'payment' => [
                    'id' => 'ch_callback',
                    'status' => 'SUCCEEDED',
                    'amount' => '5000.00',
                    'currency' => 'NGN',
                ],
            ], 200),
        ]);

        $this->get(route('wallet.bachs.callback', ['checkout_id' => 'chk_callback']))
            ->assertRedirect(route('wallet.index'));

        $this->assertSame('5000.00', $this->balanceOf($user));

        // Uppercase "SUCCEEDED" from Bachs must normalise to a settled row.
        $this->assertSame('success', $transaction->fresh()->status);
    }

    public function test_the_callback_without_a_checkout_id_sends_the_customer_back(): void
    {
        $this->get(route('wallet.bachs.callback'))
            ->assertRedirect(route('wallet.fund'));
    }

    public function test_the_callback_404s_for_an_unknown_checkout(): void
    {
        $this->get(route('wallet.bachs.callback', ['checkout_id' => 'chk_nope']))
            ->assertNotFound();
    }

    public function test_the_callback_waits_when_no_charge_is_recorded_yet(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $transaction->forceFill([
            'api_reference' => 'chk_waiting',
            'meta' => ['gateway' => 'bachs', 'bachs_checkout_id' => 'chk_waiting', 'user_id' => (string) $user->id],
        ])->save();

        Http::fake([
            'sandbox-api.bachs.io/v1/checkout-sessions/*' => Http::response([
                'checkout_id' => 'chk_waiting',
                'status' => 'open',
            ], 200),
        ]);

        $this->get(route('wallet.bachs.callback', ['checkout_id' => 'chk_waiting']))
            ->assertRedirect(route('wallet.payment.status', ['reference' => $transaction->reference]));

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $this->balanceOf($user));
    }

    /* =====================================================================
     | 4. Reconciliation covers Bachs rows too
     |=================================================================== */

    public function test_a_bachs_attempt_that_never_reached_checkout_is_failed_automatically(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        // No access code: Bachs init never returned, so the customer was never
        // handed to a gateway.
        $transaction->forceFill(['created_at' => now()->subMinutes(45)])->saveQuietly();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('failed', $transaction->fresh()->status);
    }

    public function test_a_bachs_attempt_that_reached_checkout_keeps_the_long_window(): void
    {
        $user = $this->user();
        $transaction = $this->pendingFunding($user, '5000.00');

        $transaction->forceFill([
            'meta' => ['gateway' => 'bachs', 'bachs_checkout_id' => 'chk_open'],
            'created_at' => now()->subMinutes(45),
        ])->saveQuietly();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    /* =====================================================================
     | 6. Immediate feedback
     |=================================================================== */

    /**
     * The funding form submits with `fetch`, so the answer has to be JSON. If
     * this ever regressed to a redirect the form would receive a 302 to an
     * external gateway, follow it, and the customer would be left looking at a
     * "Processing…" button with no explanation.
     */
    public function test_a_json_submit_gets_the_gateway_url_back(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/json',
                    'access_code' => 'json',
                    'reference' => 'json',
                ],
            ]),
        ]);

        $this->actingAs($this->user())
            ->postJson(route('wallet.process-funding'), [
                'amount' => '5000',
                'payment_method' => 'paystack',
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'redirect',
                'redirect' => 'https://checkout.paystack.com/json',
            ]);
    }

    /**
     * The whole point of the change: a gateway that cannot start a payment is
     * reported in one round trip, with a message the customer can act on,
     * rather than leaving the spinner running.
     */
    public function test_a_json_submit_reports_both_gateways_failing_immediately(): void
    {
        Http::fake([
            'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401),
            'sandbox-api.bachs.io/*' => Http::response(['message' => 'ACCOUNT_NOT_ACTIVATED'], 400),
        ]);

        $user = $this->user();

        $response = $this->actingAs($user)->postJson(route('wallet.process-funding'), [
            'amount' => '5000',
            'payment_method' => 'paystack',
        ]);

        // 502: our request was fine, the upstream gateway could not be used.
        $response->assertStatus(502)
            ->assertJson(['status' => 'failed']);

        $this->assertNotEmpty($response->json('message'));
        $this->assertStringContainsString('bank transfer', $response->json('message'));

        // And the attempt is resolved, not left pending.
        $this->assertSame('failed', Transactions::where('user_id', $user->id)->firstOrFail()->status);
    }

    public function test_a_json_submit_reports_validation_errors_as_json(): void
    {
        $this->actingAs($this->user())
            ->postJson(route('wallet.process-funding'), [
                'amount' => '1',
                'payment_method' => 'paystack',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    /**
     * The non-JavaScript fallback must keep working: a plain form POST still
     * gets a real redirect rather than a JSON body it cannot use.
     */
    public function test_a_plain_form_post_still_redirects(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/plain',
                    'access_code' => 'plain',
                    'reference' => 'plain',
                ],
            ]),
        ]);

        $this->actingAs($this->user())->post(route('wallet.process-funding'), [
            'amount' => '5000',
            'payment_method' => 'paystack',
        ])->assertRedirect('https://checkout.paystack.com/plain');
    }

    /**
     * The form has to opt into the JSON path, or none of the above is reachable
     * from the page the customer actually uses.
     */
    public function test_the_funding_form_submits_in_the_background(): void
    {
        $html = $this->actingAs($this->user())->get(route('wallet.fund'))->assertOk()->getContent();

        // The component is called from the module, not inlined.
        $this->assertStringContainsString('x-data="walletFunding({', $html);
        $this->assertStringContainsString('@submit="submit($event)"', $html);

        /*
         * The submit handler itself lives in resources/js/wallet-funding.js.
         * That is the point: inline, Alpine evaluates it with `new Function` at
         * runtime, so a quoting mistake is invisible to every server-side check
         * and takes every binding on the page down with it. `.env`-independent
         * assertions on the bundle belong to the build, not here — but the
         * behaviour (Accept: application/json, the abort cap) is asserted in
         * that module's own docblock and exercised by the JSON tests above.
         */
        $module = (string) file_get_contents(resource_path('js/wallet-funding.js'));

        $this->assertStringContainsString("Accept: 'application/json'", $module);
        $this->assertStringContainsString('AbortController', $module);
        $this->assertStringContainsString('preventDefault', $module);
    }

    /* =====================================================================
     | 7. Signature verification unit behaviour
     |=================================================================== */

    public function test_signature_v2_carries_the_timestamp_inline(): void
    {
        $service = app(BachsService::class);

        $body = '{"type":"collection.succeeded"}';
        $timestamp = time();
        $digest = hash_hmac('sha256', $timestamp . '.' . $body, self::BACHS_WEBHOOK_SECRET);

        $this->assertTrue($service->verifySignature(
            rawBody: $body,
            timestamp: '',
            signature: '',
            signatureV2: 't=' . $timestamp . ',v1=' . $digest
        ));
    }

    /**
     * During a secret rotation the header carries the new secret's signature
     * first. Accepting only the first v1= would reject every delivery in the
     * window, so any match must be accepted.
     */
    public function test_signature_v2_accepts_any_matching_signature_during_rotation(): void
    {
        $service = app(BachsService::class);

        $body = '{"type":"collection.succeeded"}';
        $timestamp = time();
        $good = hash_hmac('sha256', $timestamp . '.' . $body, self::BACHS_WEBHOOK_SECRET);

        $this->assertTrue($service->verifySignature(
            rawBody: $body,
            timestamp: (string) $timestamp,
            signature: '',
            signatureV2: 't=' . $timestamp . ',v1=' . str_repeat('b', 64) . ',v1=' . $good
        ));
    }

    public function test_a_signature_for_a_different_body_is_rejected(): void
    {
        $service = app(BachsService::class);

        $timestamp = time();

        $this->assertFalse($service->verifySignature(
            rawBody: '{"type":"collection.succeeded"}',
            timestamp: (string) $timestamp,
            signature: hash_hmac('sha256', $timestamp . '.{"type":"collection.failed"}', self::BACHS_WEBHOOK_SECRET)
        ));
    }
}

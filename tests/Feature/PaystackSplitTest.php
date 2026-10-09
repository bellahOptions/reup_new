<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Services\PaystackService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Paystack transaction split.
 *
 * ## Why this is asserted on the request, not on a response
 *
 * `split_code` decides where the money goes at settlement. Nothing in a
 * successful checkout response says whether the split was applied — Paystack
 * returns an authorization URL either way — so the only place the intent is
 * observable is the outbound request. A test that checked the redirect would pass
 * while every payment settled wholly into the main account.
 *
 * The dangerous failure is not a *rejected* split code. Paystack rejects an
 * unknown one and the customer sees a funding error, which is recoverable. The
 * dangerous failure is a code that is accepted but is the wrong split: money
 * routes somewhere unintended and nothing surfaces it until reconciliation. These
 * tests therefore pin that the configured split is the one actually sent, and that
 * a malformed value is refused rather than handed to the gateway.
 */
class PaystackSplitTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_split';

    private const SPLIT = 'SPL_YsS8nTY0UJ';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => self::SECRET,
            'services.paystack.public_key' => 'pk_test_split',
            'services.paystack.split_code' => self::SPLIT,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create();
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

    private function fakeInitialise(string $url = 'https://checkout.paystack.com/split1'): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => $url,
                    'access_code' => 'acc_split',
                    'reference' => 'ref_split',
                ],
            ]),
        ]);
    }

    /* =====================================================================
     | The split is sent
     |=================================================================== */

    public function test_initialisation_sends_the_configured_split_code(): void
    {
        $this->fakeInitialise();

        $user = $this->user();
        $transaction = $this->pendingFunding($user);

        app(PaystackService::class)->initialize($transaction, $user);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/transaction/initialize')
                && ($request['split_code'] ?? null) === self::SPLIT;
        });
    }

    public function test_the_split_is_sent_alongside_the_amount_and_reference_unchanged(): void
    {
        $this->fakeInitialise();

        $user = $this->user();
        $transaction = $this->pendingFunding($user, '2500.00');

        app(PaystackService::class)->initialize($transaction, $user);

        Http::assertSent(function ($request) use ($transaction) {
            return ($request['split_code'] ?? null) === self::SPLIT
                // The customer is still charged the full amount; the split is
                // applied by the gateway at settlement, not taken off the top
                // of what they pay.
                && $request['amount'] === 250000
                && $request['reference'] === $transaction->gatewayReference()
                && $request['currency'] === 'NGN'
                && $request['email'] === $transaction->user->email;
        });
    }

    public function test_the_split_code_is_recorded_on_the_transaction(): void
    {
        /*
         * The split can be changed between a payment and the question "why did
         * this settle there?", so the row carries the answer. Paystack's verify
         * response does not reliably echo it.
         */
        $this->fakeInitialise();

        $user = $this->user();
        $transaction = $this->pendingFunding($user);

        app(PaystackService::class)->initialize($transaction, $user);

        $this->assertSame(
            self::SPLIT,
            $transaction->fresh()->meta['paystack_split_code'] ?? null
        );
    }

    public function test_initialisation_still_returns_the_checkout_url(): void
    {
        // The split must not have disturbed the response handling.
        $this->fakeInitialise('https://checkout.paystack.com/split-ok');

        $user = $this->user();
        $transaction = $this->pendingFunding($user);

        $url = app(PaystackService::class)->initialize($transaction, $user);

        $this->assertSame('https://checkout.paystack.com/split-ok', $url);
        $this->assertSame('acc_split', $transaction->fresh()->meta['paystack_access_code']);
    }

    /* =====================================================================
     | Disabling the split
     |=================================================================== */

    public function test_splitting_can_be_disabled_and_then_no_split_code_is_sent(): void
    {
        /*
         * An empty value is the documented way to turn splitting off, and it must
         * produce a request identical to the pre-split behaviour — not a request
         * carrying an empty string for the gateway to interpret.
         */
        config(['services.paystack.split_code' => '']);

        $this->fakeInitialise();

        $user = $this->user();
        $transaction = $this->pendingFunding($user);

        app(PaystackService::class)->initialize($transaction, $user);

        Http::assertSent(function ($request) {
            return ! array_key_exists('split_code', $request->data());
        });

        // And the field is recorded as null rather than as an empty string.
        $this->assertNull($transaction->fresh()->meta['paystack_split_code']);
    }

    public function test_whitespace_around_a_code_is_tolerated(): void
    {
        // A value pasted into an env file commonly carries a trailing space, which
        // would otherwise be sent verbatim and rejected by the gateway.
        config(['services.paystack.split_code' => '  ' . self::SPLIT . '  ']);

        $this->assertSame(self::SPLIT, app(PaystackService::class)->splitCode());

        $this->fakeInitialise();

        $user = $this->user();
        app(PaystackService::class)->initialize($this->pendingFunding($user), $user);

        Http::assertSent(fn ($request) => ($request['split_code'] ?? null) === self::SPLIT);
    }

    /* =====================================================================
     | A malformed code fails loudly
     |=================================================================== */

    /**
     * @dataProvider malformedSplitCodes
     */
    public function test_a_malformed_split_code_is_refused_before_it_reaches_the_gateway(string $bad): void
    {
        config(['services.paystack.split_code' => $bad]);

        $this->fakeInitialise();

        $user = $this->user();
        $transaction = $this->pendingFunding($user);

        try {
            app(PaystackService::class)->initialize($transaction, $user);
            $this->fail('A malformed split code must not be sent to the gateway.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not a valid Paystack split code', $e->getMessage());
        }

        // Nothing was sent, so the customer was never charged under a bad split.
        Http::assertNothingSent();
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function malformedSplitCodes(): array
    {
        return [
            // A subaccount code where a split code belongs — the plausible mistake.
            'subaccount code' => ['ACCT_abc123'],
            // A plan or page code.
            'plan code' => ['PLN_abc123'],
            // The prefix with no identifier.
            'no identifier' => ['SPL_'],
            // A percentage that looks like it belongs in a dashboard, not here.
            'numeric only' => ['50'],
            'wrong prefix' => ['SPLIT_YsS8nTY0UJ'],
            'space inside' => ['SPL YsS8nTY0UJ'],
        ];
    }

    public function test_a_valid_shaped_code_is_accepted(): void
    {
        // The shape check must not be so strict that a real code fails.
        foreach (['SPL_YsS8nTY0UJ', 'SPL_abc123XYZ', 'SPL_1a2b3c'] as $code) {
            config(['services.paystack.split_code' => $code]);

            $this->assertSame($code, app(PaystackService::class)->splitCode());
        }
    }

    /* =====================================================================
     | The gateway rejecting the split
     |=================================================================== */

    public function test_a_gateway_rejection_of_the_split_surfaces_as_a_funding_error(): void
    {
        /*
         * A valid-shaped code that Paystack does not recognise (deleted, or from
         * another account) is rejected at initialisation. That must raise — the
         * alternative is a checkout the customer completes into an account the
         * platform does not control.
         */
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => false,
                'message' => 'Invalid split code',
            ], 400),
        ]);

        $user = $this->user();
        $transaction = $this->pendingFunding($user);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid split code');

        app(PaystackService::class)->initialize($transaction, $user);
    }

    public function test_a_rejected_initialisation_leaves_no_access_code_behind(): void
    {
        // A transaction that never reached checkout must not look as though it
        // did, or the reconciler will treat it as an abandoned checkout rather
        // than one that was never started.
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => false,
                'message' => 'Invalid split code',
            ], 400),
        ]);

        $user = $this->user();
        $transaction = $this->pendingFunding($user);

        try {
            app(PaystackService::class)->initialize($transaction, $user);
        } catch (\RuntimeException $e) {
            // expected
        }

        $meta = $transaction->fresh()->meta ?? [];

        $this->assertArrayNotHasKey('paystack_access_code', $meta);
        $this->assertArrayNotHasKey('paystack_split_code', $meta);
    }

    /* =====================================================================
     | The funding route
     |=================================================================== */

    /**
     * The whole customer path, not just `initialize()`.
     *
     * The unit-level tests above prove the split is in the request that
     * `initialize()` builds. This proves the route a customer actually uses
     * reaches that code with the split intact — a controller that initialised
     * Paystack some other way would pass every test above and still settle every
     * payment unsplit.
     */
    public function test_card_funding_started_from_the_funding_page_carries_the_split(): void
    {
        $this->fakeInitialise('https://checkout.paystack.com/from-route');

        $user = $this->user();

        $response = $this->actingAs($user)->post(route('wallet.process-funding'), [
            'amount' => '5000',
            'payment_method' => 'paystack',
        ]);

        $response->assertRedirect('https://checkout.paystack.com/from-route');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/transaction/initialize')
                && ($request['split_code'] ?? null) === self::SPLIT;
        });

        // And the row the customer's payment created records which split it was
        // initialised under.
        $funding = Transactions::where('user_id', $user->id)
            ->where('payment_method', 'paystack')
            ->latest('id')
            ->first();

        $this->assertNotNull($funding);
        $this->assertSame(self::SPLIT, $funding->meta['paystack_split_code'] ?? null);
    }

    /* =====================================================================
     | Defaults
     |=================================================================== */

    public function test_the_shipped_default_is_the_platform_split(): void
    {
        /*
         * The default is read from config, so this pins the documented value
         * rather than the test's override. A change to it should be a deliberate
         * edit to config/services.php, visible in review.
         */
        $default = require config_path('services.php');

        $this->assertSame(
            self::SPLIT,
            $default['paystack']['split_code'] ?? null,
            'config/services.php must default to the platform split code'
        );
    }

    /* =====================================================================
     | The verification command
     |=================================================================== */

    /** The shape Paystack returns from GET /split/{id}. */
    private function fakeSplit(array $overrides = []): void
    {
        Http::fake([
            'api.paystack.co/split/*' => Http::response([
                'status' => true,
                'message' => 'Split retrieved',
                'data' => array_merge([
                    'split_code' => self::SPLIT,
                    'name' => 'ReUp platform split',
                    'type' => 'percentage',
                    'currency' => 'NGN',
                    'active' => true,
                    'bearer_type' => 'account',
                    'subaccounts' => [
                        ['subaccount' => ['subaccount_code' => 'ACCT_partner'], 'share' => '10'],
                    ],
                ], $overrides),
            ]),
        ]);
    }

    public function test_the_check_command_confirms_a_usable_split(): void
    {
        /*
         * The command exists because neither failure mode is visible from inside
         * the app: an accepted-but-wrong split misroutes settlements, and an
         * unknown one makes initialisation fail and silently fall back to the
         * other gateway.
         *
         * Style note (same as WalletVerificationCommandTest): Laravel 8's
         * `PendingCommand` has `expectsOutput()` — exact match — but not
         * `expectsOutputToContain()`, and `Artisan::output()` is not populated by
         * the harness. So each assertion names the whole line that carries the
         * verdict.
         */
        $this->fakeSplit();

        $this->artisan('paystack:check-split')
            ->expectsOutput('Checking split: ' . self::SPLIT)
            ->expectsOutput('Split looks usable: active, NGN, and recognised by this Paystack account.')
            ->assertExitCode(0);
    }

    public function test_the_check_command_fails_when_paystack_does_not_know_the_code(): void
    {
        // The state a deleted or cross-account code produces.
        Http::fake([
            'api.paystack.co/split/*' => Http::response(['status' => false, 'message' => 'Split not found'], 404),
        ]);

        $this->artisan('paystack:check-split')
            ->expectsOutput('Paystack does not recognise this split code for this account (404).')
            ->expectsOutput('where this split does not apply. Fix PAYSTACK_SPLIT_CODE.')
            ->assertExitCode(1);
    }

    public function test_the_check_command_fails_on_an_inactive_split(): void
    {
        // Paystack will not apply an inactive split, so a checkout would settle
        // wholly into the main account.
        $this->fakeSplit(['active' => false]);

        $this->artisan('paystack:check-split')
            ->expectsOutput('The split is INACTIVE. Paystack will not apply it.')
            ->assertExitCode(1);
    }

    public function test_the_check_command_fails_on_a_currency_mismatch(): void
    {
        // Every checkout is initialised as NGN; Paystack will not apply a split
        // in another currency.
        $this->fakeSplit(['currency' => 'GHS']);

        $this->artisan('paystack:check-split')
            ->expectsOutput('The split is in GHS but every checkout is initialised as NGN.')
            ->assertExitCode(1);
    }

    public function test_the_check_command_reports_a_malformed_code_without_calling_paystack(): void
    {
        config(['services.paystack.split_code' => 'ACCT_not-a-split']);

        $this->artisan('paystack:check-split')
            ->expectsOutput(
                'PAYSTACK_SPLIT_CODE [ACCT_not-a-split] is not a valid Paystack split code. '
                . 'Expected the SPL_ code from the Paystack dashboard (Splits), or empty to disable splitting.'
            )
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_check_command_explains_that_an_empty_code_disables_splitting(): void
    {
        config(['services.paystack.split_code' => '']);

        $this->artisan('paystack:check-split')
            ->expectsOutput('PAYSTACK_SPLIT_CODE is empty: splitting is DISABLED and settlements land wholly in the main account.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_the_check_command_fails_when_paystack_is_not_configured(): void
    {
        config(['services.paystack.secret_key' => null]);

        $this->artisan('paystack:check-split')
            ->expectsOutput('Paystack is not configured (PAYSTACK_SECRET_KEY is empty).')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_check_command_sends_the_secret_key_as_a_bearer_token_and_not_in_the_url(): void
    {
        /*
         * Console output is not capturable under the Laravel 8 harness, so the
         * guarantee that the credential does not leak is asserted where it is
         * actually decided: the key travels as an Authorization header, and no
         * part of it appears in the request URL — which is the form that ends up
         * in logs and error reports.
         */
        $this->fakeSplit();

        $this->artisan('paystack:check-split')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/split/' . self::SPLIT)
                && ! str_contains($request->url(), self::SECRET)
                && $request->hasHeader('Authorization', 'Bearer ' . self::SECRET);
        });
    }
}

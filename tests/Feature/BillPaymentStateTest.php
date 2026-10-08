<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Services\BillPaymentService;
use App\Services\ProviderManager;
use App\Services\Providers\BillProvider;
use App\Services\SecurityService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bill-payment state model.
 *
 * The rule this file exists to protect: **UNKNOWN is not FAILED.**
 *
 * When a provider times out after ReUp has sent the request, the order may have
 * been vended. Retrying it elsewhere can vend twice; refunding it can give away
 * goods for free; marking it failed invites the customer to submit it again and
 * be charged twice. The only safe action is to stop and say so.
 *
 * Every test asserts on the transaction row, the wallet balance and the ledger —
 * the states that decide whether money moves — not on a response code.
 */
class BillPaymentStateTest extends TestCase
{
    use RefreshDatabase;

    /** Records every purchase call so a test can prove failover did or did not happen. */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Isolation from network probes; the provider is supplied directly.
        config([
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['fake', 'fake_alt'],
            'bills.limits.per_minute' => 100,
        ]);
    }

    private function user(string $balance = '10000.00'): User
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
     * A provider whose response is scripted by the test.
     *
     * @param  array<string,mixed>|null  $response  what `purchase()` returns
     */
    private function fakeProvider(string $name, ?array $response, string $orderStatus = 'unknown'): BillProvider
    {
        return new class($name, $response, $orderStatus, $this) implements BillProvider {
            public function __construct(
                private string $name,
                private ?array $response,
                private string $orderStatus,
                private BillPaymentStateTest $test,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function label(): string
            {
                return ucfirst($this->name);
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supports(string $product): bool
            {
                return true;
            }

            public function ping(): bool
            {
                return true;
            }

            public function cost(string $product, array $params): ?float
            {
                return null;
            }

            public function balance(): array
            {
                return ['success' => true, 'balance' => 100000.0, 'currency' => 'NGN'];
            }

            public function verifyCustomer(string $product, array $params): ?array
            {
                return null;
            }

            public function purchase(string $product, array $params, string $reference): ?array
            {
                $this->test->recordCall($this->name);

                return $this->response;
            }

            public function isSuccess(?array $response): bool
            {
                return strtoupper((string) ($response['status'] ?? '')) === 'ORDER_RECEIVED';
            }

            public function errorMessage(?array $response): string
            {
                return (string) ($response['message'] ?? 'Transaction failed: ' . ($response['status'] ?? 'no response'));
            }

            public function orderReference(?array $response): ?string
            {
                return $response['order_id'] ?? null;
            }

            public function issuedToken(?array $response): ?string
            {
                return $response['token'] ?? null;
            }

            public function orderStatus(string $reference, array $params = []): string
            {
                return $this->orderStatus;
            }
        };
    }

    public function recordCall(string $provider): void
    {
        $this->calls[] = $provider;
    }

    /** Swap the real provider set for the fakes, preserving everything else. */
    private function withProviders(BillProvider ...$providers): void
    {
        $this->calls = [];

        $this->app->bind(ProviderManager::class, function ($app) use ($providers) {
            return new class($providers, $app->make(\App\Services\ProviderBalanceService::class), $app->make(\App\Services\ProviderHealthService::class)) extends ProviderManager {
                public function __construct(
                    private array $fakes,
                    $balances,
                    $health,
                ) {
                    parent::__construct(
                        new \App\Services\Providers\ClubKonnectProvider(
                            app(\App\Services\ClubKonnectService::class),
                            app(\App\Services\ClubKonnectCatalogue::class),
                        ),
                        new \App\Services\Providers\PairgateProvider(),
                        $balances,
                        $health,
                    );
                }

                public function all(): array
                {
                    return array_values($this->fakes);
                }

                public function byName(string $name): ?BillProvider
                {
                    foreach ($this->fakes as $fake) {
                        if ($fake->name() === $name) {
                            return $fake;
                        }
                    }

                    return null;
                }

                public function candidates(string $product): array
                {
                    return array_values($this->fakes);
                }

                public function affordable(string $product, float $amount, array $params = []): array
                {
                    return array_values($this->fakes);
                }

                public function verifier(string $product): BillProvider
                {
                    return $this->fakes[0];
                }
            };
        });
    }

    /**
     * Run one purchase through the real pipeline against the fakes.
     *
     * @return array{result:array,transaction:?Transactions}
     */
    private function purchase(User $user, ?string $idempotencyKey = null, string $amount = '1000.00'): array
    {
        $result = app(BillPaymentService::class)->purchase(
            user: $user,
            product: 'airtime',
            amount: $amount,
            fee: '0.00',
            recipient: '08031234567',
            providerLabel: 'Fake',
            description: 'Airtime — MTN',
            meta: [],
            dispatch: fn (BillProvider $provider, Transactions $transaction) => $provider->purchase('airtime', [], $transaction->reference),
            successMessage: 'Airtime delivered.',
            pin: '5931',
            idempotencyKey: $idempotencyKey,
        );

        return ['result' => $result];
    }

    /* =====================================================================
     | SUCCESS
     |=================================================================== */

    public function test_a_successful_purchase_debits_once_and_records_the_ledger(): void
    {
        $user = $this->user('5000.00');
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $outcome = $this->purchase($user, amount: '1000.00');

        $this->assertTrue($outcome['result']['ok']);
        $this->assertSame(BillPaymentService::OUTCOME_SUCCESS, $outcome['result']['outcome']);
        $this->assertSame('4000.00', $this->balanceOf($user));

        $transaction = $outcome['result']['transaction'];
        $this->assertSame('success', $transaction->status);
        $this->assertSame('1000.00', (string) $transaction->total_amount);

        // One DEBIT ledger entry, linked to the transaction.
        $entries = WalletLedger::where('user_id', $user->id)
            ->where('entry_type', WalletLedger::ENTRY_DEBIT)
            ->get();
        $this->assertCount(1, $entries);
        $this->assertSame($transaction->id, $entries->first()->transaction_id);
        $this->assertSame('5000.00', (string) $entries->first()->balance_before);
        $this->assertSame('4000.00', (string) $entries->first()->balance_after);
    }

    /* =====================================================================
     | FATAL FAILURE — refund, no failover
     |=================================================================== */

    public function test_a_provider_rejection_refunds_and_does_not_fail_over(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'INVALID_RECIPIENT', 'message' => 'Invalid phone number.']),
            $this->fakeProvider('fake_alt', ['status' => 'ORDER_RECEIVED']),
        );

        $outcome = $this->purchase($user);

        $this->assertFalse($outcome['result']['ok']);
        $this->assertSame(BillPaymentService::OUTCOME_FAILED, $outcome['result']['outcome']);

        // The second provider was never asked: an invalid phone number is not
        // something another vendor would accept.
        $this->assertSame(['fake'], $this->calls);

        // Money returned, exactly.
        $this->assertSame('5000.00', $this->balanceOf($user));

        $transaction = $outcome['result']['transaction'];
        $this->assertSame('failed', $transaction->status);
        $this->assertSame('refunded', $transaction->payment_status);

        // The debit and the compensating refund are both on the ledger.
        $this->assertSame(1, WalletLedger::where('entry_type', WalletLedger::ENTRY_DEBIT)->count());
        $this->assertSame(1, WalletLedger::where('entry_type', WalletLedger::ENTRY_REFUND)->count());
    }

    /* =====================================================================
     | RETRYABLE — failover, then success
     |=================================================================== */

    public function test_a_provider_float_failure_fails_over_and_the_second_provider_vends(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'INSUFFICIENT_BALANCE']),
            $this->fakeProvider('fake_alt', ['status' => 'ORDER_RECEIVED', 'order_id' => 'ALT-1']),
        );

        $outcome = $this->purchase($user);

        $this->assertTrue($outcome['result']['ok']);
        $this->assertSame(['fake', 'fake_alt'], $this->calls);
        $this->assertSame('4000.00', $this->balanceOf($user), 'Only the successful purchase may be charged.');
        $this->assertSame('fake_alt', $outcome['result']['provider']);

        // Exactly one debit; the failed attempt left no money movement.
        $this->assertSame(1, WalletLedger::where('entry_type', WalletLedger::ENTRY_DEBIT)->count());
        $this->assertSame(1, Transactions::where('user_id', $user->id)->where('type', 'debit')->count());
    }

    public function test_both_providers_refusing_results_in_one_refund(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'SERVICE_UNAVAILABLE']),
            $this->fakeProvider('fake_alt', ['status' => 'INSUFFICIENT_BALANCE']),
        );

        $outcome = $this->purchase($user);

        $this->assertFalse($outcome['result']['ok']);
        $this->assertSame(['fake', 'fake_alt'], $this->calls);
        $this->assertSame('5000.00', $this->balanceOf($user));
        $this->assertSame(1, WalletLedger::where('entry_type', WalletLedger::ENTRY_REFUND)->count());
    }

    /* =====================================================================
     | UNKNOWN — the case that must not be guessed at
     |=================================================================== */

    public function test_a_timeout_is_recorded_as_unknown_and_is_not_refunded(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'TIMEOUT', 'message' => 'Connection timed out.'], orderStatus: 'unknown'),
            $this->fakeProvider('fake_alt', ['status' => 'ORDER_RECEIVED']),
        );

        $outcome = $this->purchase($user);

        $this->assertFalse($outcome['result']['ok']);
        $this->assertSame(BillPaymentService::OUTCOME_UNKNOWN, $outcome['result']['outcome']);

        // CRITICAL: the second provider was never asked. Retrying a request
        // whose fate is unknown is how a customer gets vended twice.
        $this->assertSame(['fake'], $this->calls);

        // CRITICAL: no refund. The order may have been vended.
        $this->assertSame('4000.00', $this->balanceOf($user), 'The money must stay held, not refunded.');
        $this->assertSame(0, WalletLedger::where('entry_type', WalletLedger::ENTRY_REFUND)->count());

        $transaction = $outcome['result']['transaction'];
        $this->assertSame('unknown', $transaction->status);
        $this->assertSame('processing', $transaction->payment_status);
        $this->assertTrue((bool) data_get($transaction->meta, 'unconfirmed'));
        $this->assertNotSame('failed', $transaction->status, 'UNKNOWN is not FAILED.');
    }

    public function test_an_invalid_response_is_unknown_not_a_failure(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'INVALID_RESPONSE', 'message' => 'Malformed response'], orderStatus: 'unknown'),
        );

        $outcome = $this->purchase($user);

        $this->assertSame(BillPaymentService::OUTCOME_UNKNOWN, $outcome['result']['outcome']);
        $this->assertSame('4000.00', $this->balanceOf($user));
        $this->assertSame('unknown', $outcome['result']['transaction']->status);
    }

    public function test_a_null_response_is_unknown_not_a_failure(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders($this->fakeProvider('fake', null, orderStatus: 'unknown'));

        $outcome = $this->purchase($user);

        $this->assertSame(BillPaymentService::OUTCOME_UNKNOWN, $outcome['result']['outcome']);
        $this->assertSame('4000.00', $this->balanceOf($user));
    }

    public function test_an_unknown_outcome_the_provider_then_confirms_as_success_is_settled(): void
    {
        $user = $this->user('5000.00');

        // The purchase call times out, but a status query says it vended.
        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'TIMEOUT'], orderStatus: 'success'),
        );

        $outcome = $this->purchase($user);

        // The provider confirmed it, so this is a success and the money is owed.
        $this->assertSame('success', $outcome['result']['transaction']->status);
        $this->assertSame('4000.00', $this->balanceOf($user));
        $this->assertSame(0, WalletLedger::where('entry_type', WalletLedger::ENTRY_REFUND)->count());
    }

    public function test_an_unknown_outcome_the_provider_then_confirms_as_failed_is_refunded(): void
    {
        $user = $this->user('5000.00');

        // The purchase call times out; the status query says it never vended.
        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'TIMEOUT'], orderStatus: 'failed'),
        );

        $outcome = $this->purchase($user);

        $this->assertSame('failed', $outcome['result']['transaction']->status);
        $this->assertSame('refunded', $outcome['result']['transaction']->payment_status);
        $this->assertSame('5000.00', $this->balanceOf($user), 'A provider-confirmed failure must be refunded.');
        $this->assertSame(1, WalletLedger::where('entry_type', WalletLedger::ENTRY_REFUND)->count());
    }

    public function test_a_pending_provider_answer_leaves_the_purchase_unconfirmed(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'TIMEOUT'], orderStatus: 'pending'),
        );

        $outcome = $this->purchase($user);

        $this->assertSame(BillPaymentService::OUTCOME_UNKNOWN, $outcome['result']['outcome']);
        $this->assertSame('unknown', $outcome['result']['transaction']->status);
        $this->assertSame('4000.00', $this->balanceOf($user));
    }

    /* =====================================================================
     | Refund idempotency
     | =================================================================== */

    public function test_a_refund_cannot_be_issued_twice(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'INVALID_RECIPIENT', 'message' => 'Invalid phone number.']),
        );

        $outcome = $this->purchase($user);
        $transaction = $outcome['result']['transaction'];

        $this->assertSame('5000.00', $this->balanceOf($user));

        // A second refund of the same charge must be refused outright.
        $second = app(BillPaymentService::class)->refund($transaction->fresh(), 'Duplicate attempt');

        $this->assertFalse($second, 'A second refund must be refused.');
        $this->assertSame('5000.00', $this->balanceOf($user), 'The balance must not move twice.');
        $this->assertSame(1, WalletLedger::where('entry_type', WalletLedger::ENTRY_REFUND)->count());
        $this->assertSame(1, Transactions::where('service_type', 'refund')->count());
    }

    /* =====================================================================
     | Duplicate request via the idempotency key
     |=================================================================== */

    public function test_a_duplicate_request_with_the_same_key_charges_once(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-2']),
        );

        /*
         * The short-window "identical purchase" guard exists to stop a
         * double-click. It is deliberately switched off here so that what is
         * being tested is the *database* idempotency key — the actual financial
         * control — and not the cache-based UX guard in front of it.
         */
        config(['bills.limits.duplicate_window' => 0]);

        $key = 'test-idempotency-key-0001';

        $first = $this->purchase($user, $key);
        $this->assertTrue($first['result']['ok']);
        $this->assertSame('4000.00', $this->balanceOf($user));

        $second = $this->purchase($user, $key);

        $this->assertSame('4000.00', $this->balanceOf($user), 'A replayed request must not debit again.');
        $this->assertSame(1, WalletLedger::where('entry_type', WalletLedger::ENTRY_DEBIT)->count());
        $this->assertSame(1, Transactions::where('type', 'debit')->count());
        $this->assertSame($first['result']['transaction']->id, $second['result']['transaction']->id);
    }

    public function test_the_idempotency_authority_is_the_database_not_the_cache(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-4']),
        );

        config(['bills.limits.duplicate_window' => 0]);

        $key = 'test-idempotency-key-0003';

        $this->purchase($user, $key);
        $this->assertSame('4000.00', $this->balanceOf($user));

        /*
         * Flush every cache store, which is exactly what a deploy, a restart or
         * an operator running `cache:clear` does. Under the previous
         * cache-only implementation this reset every replay guard in the
         * platform and the next retry charged the customer a second time.
         */
        \Illuminate\Support\Facades\Cache::flush();

        $replay = $this->purchase($user, $key);

        $this->assertSame('4000.00', $this->balanceOf($user), 'A cache flush must not reopen the replay window.');
        $this->assertSame(1, Transactions::where('type', 'debit')->count());
        $this->assertSame($replay['result']['transaction']->id, Transactions::where('type', 'debit')->first()->id);
    }

    public function test_the_same_key_with_a_different_amount_is_refused(): void
    {
        $user = $this->user('5000.00');

        $this->withProviders(
            $this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-3']),
        );

        $key = 'test-idempotency-key-0002';

        $this->purchase($user, $key, amount: '1000.00');
        $this->assertSame('4000.00', $this->balanceOf($user));

        // Reusing a processed key for a *different* purchase must be refused,
        // either loudly or by answering with the original — never by executing.
        try {
            $this->purchase($user, $key, amount: '2000.00');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('already', strtolower($e->getMessage()));
        }

        $this->assertSame('4000.00', $this->balanceOf($user));
        $this->assertSame(1, Transactions::where('type', 'debit')->count());
    }

    /* =====================================================================
     | Insufficient balance
     |=================================================================== */

    public function test_an_insufficient_balance_never_reaches_a_provider(): void
    {
        $user = $this->user('500.00');

        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED']));

        try {
            $this->purchase($user, amount: '1000.00');
            $this->fail('A purchase beyond the balance must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient wallet balance', $e->getMessage());
        }

        $this->assertSame('500.00', $this->balanceOf($user));
        $this->assertSame([], $this->calls, 'No provider may be contacted when the customer cannot pay.');
        $this->assertSame(0, Transactions::where('type', 'debit')->count());
    }

    /* =====================================================================
     | WalletService is the only debit path
     | =================================================================== */

    public function test_the_pipeline_debits_through_the_ledger(): void
    {
        $user = $this->user('2000.00');

        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED']));

        $this->purchase($user, amount: '1000.00');

        $verification = app(WalletService::class)->verify(app(WalletService::class)->forUser($user));

        $this->assertTrue($verification['consistent'], 'The purchase must leave the ledger reconciling to the balance.');
    }
}

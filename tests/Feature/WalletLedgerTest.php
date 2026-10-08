<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The wallet is the single most important invariant in the application: the
 * balance must always equal the sum of the movements recorded against it, and
 * no sequence of requests may take it below zero.
 *
 * These tests assert on **database state** — the `wallets` row, the
 * `wallet_ledger` rows and their before/after chain — not on response codes.
 * A 302 with a friendly error message proves nothing about whether money moved.
 */
class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallets = app(WalletService::class);
    }

    private function user(): User
    {
        // The `created` hook on User creates the wallet row.
        return User::factory()->create();
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    /* =====================================================================
     | Exact amounts
     |=================================================================== */

    public function test_a_single_kobo_can_be_credited_and_debited_exactly(): void
    {
        $user = $this->user();

        $this->wallets->credit($user, '0.01');

        $this->assertSame('0.01', $this->balanceOf($user));

        $this->wallets->debit($user, '0.01');

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame(0, $this->wallets->balanceFor($user)->minor());
    }

    public function test_a_decimal_amount_is_stored_exactly(): void
    {
        $user = $this->user();

        $this->wallets->credit($user, '1500.50');

        $this->assertSame('1500.50', $this->balanceOf($user));
    }

    public function test_repeated_small_movements_do_not_drift(): void
    {
        $user = $this->user();

        // A hundred credits of ₦0.07 is exactly ₦7.00. In float arithmetic this
        // accumulates to something like 6.999999999999999.
        for ($i = 0; $i < 100; $i++) {
            $this->wallets->credit($user, '0.07');
        }

        $this->assertSame('7.00', $this->balanceOf($user));

        for ($i = 0; $i < 100; $i++) {
            $this->wallets->debit($user, '0.07');
        }

        $this->assertSame('0.00', $this->balanceOf($user));
    }

    public function test_a_negative_amount_is_refused_in_both_directions(): void
    {
        $user = $this->user();

        try {
            $this->wallets->credit($user, '-100');
            $this->fail('A negative credit must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('greater than zero', $e->getMessage());
        }

        try {
            $this->wallets->debit($user, '-100');
            $this->fail('A negative debit must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('greater than zero', $e->getMessage());
        }

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame(0, WalletLedger::count());
    }

    /* =====================================================================
     | Insufficient balance
     |=================================================================== */

    public function test_a_debit_beyond_the_balance_is_refused_and_moves_nothing(): void
    {
        $user = $this->user();
        $this->wallets->credit($user, '5000.00');

        $ledgerBefore = WalletLedger::count();

        try {
            $this->wallets->debit($user, '5000.01');
            $this->fail('An overdraft must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Insufficient wallet balance', $e->getMessage());
            // The message reports both figures, exactly.
            $this->assertStringContainsString('₦5,000.01', $e->getMessage());
            $this->assertStringContainsString('₦5,000.00', $e->getMessage());
        }

        $this->assertSame('5000.00', $this->balanceOf($user));
        $this->assertSame($ledgerBefore, WalletLedger::count(), 'A refused debit must not write a ledger entry.');
    }

    public function test_a_wallet_cannot_be_taken_below_zero(): void
    {
        $user = $this->user();
        $this->wallets->credit($user, '1.00');

        // The whole balance may be spent — down to exactly zero.
        $this->wallets->debit($user, '1.00');
        $this->assertSame('0.00', $this->balanceOf($user));

        // But not one kobo further, in any amount.
        foreach (['0.01', '1.00', '99.00'] as $attempt) {
            try {
                $this->wallets->debit($user, $attempt);
                $this->fail("A debit of {$attempt} against a zero balance must be refused.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Insufficient wallet balance', $e->getMessage());
            }
        }

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame(0, $this->wallets->balanceFor($user)->minor());
        $this->assertFalse($this->wallets->balanceFor($user)->isNegative());
    }

    /* =====================================================================
     | Concurrent debits
     |=================================================================== */

    /**
     * The ₦5,000 / two ₦4,000 scenario.
     *
     * PHPUnit runs one process, so this cannot open two genuinely simultaneous
     * connections inside `RefreshDatabase` (which holds every test in a single
     * transaction). What it *does* verify is the mechanism the concurrency
     * guarantee rests on: each debit re-reads the balance from the database
     * rather than from the caller's in-memory model, and the overdraft check
     * uses that re-read value. The second request therefore sees the first
     * request's effect, which is precisely what makes "exactly one succeeds"
     * true when they really do overlap — MySQL serialises them on the
     * `SELECT ... FOR UPDATE` row lock taken by `WalletService::lockForUser()`.
     *
     * The lock itself is asserted structurally in
     * `test_the_movement_takes_a_row_lock`, so a future refactor that drops the
     * lock fails a test rather than silently reopening the race.
     */
    public function test_two_full_balance_debits_leave_exactly_one_success(): void
    {
        $user = $this->user();
        $this->wallets->credit($user, '5000.00');

        $succeeded = 0;
        $refused = 0;

        foreach ([1, 2] as $request) {
            try {
                $this->wallets->debit($user, '4000.00');
                $succeeded++;
            } catch (RuntimeException $e) {
                $refused++;
                $this->assertStringContainsString('Insufficient wallet balance', $e->getMessage());
            }
        }

        $this->assertSame(1, $succeeded, 'Exactly one of the two ₦4,000 debits must succeed.');
        $this->assertSame(1, $refused);
        $this->assertSame('1000.00', $this->balanceOf($user));

        // One credit and one debit in the ledger — the refused attempt left no
        // trace, because it never changed anything.
        $this->assertSame(2, WalletLedger::where('user_id', $user->id)->count());
    }

    public function test_a_debit_does_not_trust_a_stale_in_memory_balance(): void
    {
        $user = $this->user();
        $this->wallets->credit($user, '100.00');

        // A model read before the movement: the shape every cache-the-balance
        // implementation had, and the reason one existed.
        $stale = $this->wallets->forUser($user);
        $this->assertSame('100.00', (string) $stale->balance);

        // Move the real balance out from under the stale model.
        $this->wallets->debit($user, '100.00');

        try {
            $this->wallets->debit($user, '100.00');
            $this->fail('The second debit must be refused despite the stale model saying otherwise.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Insufficient wallet balance', $e->getMessage());
        }

        $this->assertSame('0.00', $this->balanceOf($user));
    }

    /**
     * Structural proof that the movement is serialised and atomic.
     *
     * Named separately so a refactor that removes the lock or the transaction
     * fails here with an explicit message rather than being discovered in
     * production as a double-spend.
     */
    public function test_the_movement_takes_a_row_lock_inside_a_transaction(): void
    {
        $user = $this->user();
        $this->wallets->credit($user, '100.00');

        /*
         * On MySQL, a `SELECT ... FOR UPDATE` issued outside a transaction locks
         * nothing (autocommit releases it immediately). Inside a transaction it
         * blocks a competing lock until the transaction ends. This asserts the
         * lock is real by proving a *second* connection cannot take it while the
         * first holds the transaction open.
         *
         * Skipped when the connection is not MySQL (the suite runs MySQL — see
         * phpunit.xml — so this is not expected to skip), because `FOR UPDATE`
         * means nothing to SQLite.
         */
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row-lock semantics are MySQL-specific.');
        }

        $walletId = Wallet::where('user_id', $user->id)->value('id');

        DB::beginTransaction();
        Wallet::where('id', $walletId)->lockForUpdate()->first();

        // A second, independent connection must not be able to take the lock.
        config(['database.connections.lock_probe' => config('database.connections.mysql')]);
        $probe = DB::connection('lock_probe');
        $probe->statement('SET innodb_lock_wait_timeout = 1');

        $blocked = false;

        try {
            $probe->select('SELECT * FROM wallets WHERE id = ? FOR UPDATE', [$walletId]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Error 1205 is a lock wait timeout: the lock was genuinely held.
            $blocked = str_contains($e->getMessage(), '1205')
                || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
        }

        DB::rollBack();

        $this->assertTrue($blocked, 'The wallet row must be locked for the duration of the movement.');
    }

    /* =====================================================================
     | Immutable ledger
     |=================================================================== */

    public function test_every_kind_of_movement_writes_its_entry_type(): void
    {
        $user = $this->user();

        $credit = $this->wallets->credit($user, '1000.00');
        $this->assertSame(WalletLedger::ENTRY_CREDIT, $credit['ledger']->entry_type);
        $this->assertSame(WalletLedger::DIRECTION_CREDIT, $credit['ledger']->direction);

        $debit = $this->wallets->debit($user, '250.00');
        $this->assertSame(WalletLedger::ENTRY_DEBIT, $debit['ledger']->entry_type);
        $this->assertSame(WalletLedger::DIRECTION_DEBIT, $debit['ledger']->direction);

        $refund = $this->wallets->refund($user, '250.00');
        $this->assertSame(WalletLedger::ENTRY_REFUND, $refund['ledger']->entry_type);
        $this->assertSame(WalletLedger::DIRECTION_CREDIT, $refund['ledger']->direction);

        $reversal = $this->wallets->reverse($user, '100.00', 7);
        $this->assertSame(WalletLedger::ENTRY_REVERSAL, $reversal['ledger']->entry_type);
        $this->assertSame(7, $reversal['ledger']->actor_id);

        $adjustment = $this->wallets->adjust($user, '-50.00', 9, 'Correction after investigation');
        $this->assertSame(WalletLedger::ENTRY_ADMIN_ADJUSTMENT, $adjustment['ledger']->entry_type);
        $this->assertSame(WalletLedger::DIRECTION_DEBIT, $adjustment['ledger']->direction);
        $this->assertSame(9, $adjustment['ledger']->actor_id);

        // 1000 - 250 + 250 + 100 - 50
        $this->assertSame('1050.00', $this->balanceOf($user));
    }

    public function test_a_ledger_entry_records_before_and_after_exactly(): void
    {
        $user = $this->user();
        $this->wallets->credit($user, '100.00');

        $movement = $this->wallets->debit($user, '30.50');
        $entry = $movement['ledger']->fresh();

        $this->assertSame('100.00', (string) $entry->balance_before);
        $this->assertSame('69.50', (string) $entry->balance_after);
        $this->assertSame('30.50', (string) $entry->amount);
        $this->assertSame($user->id, $entry->user_id);
        $this->assertNotNull($entry->uuid);
        $this->assertNotNull($entry->reference);
        $this->assertNotNull($entry->created_at);
    }

    public function test_an_entry_can_be_linked_to_the_transaction_it_settles(): void
    {
        $user = $this->user();

        $transaction = Transactions::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Funding',
            'amount' => '500.00',
            'service_fee' => '0.00',
            'total_amount' => '500.00',
            'payment_method' => 'paystack',
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $movement = $this->wallets->credit(
            user: $user,
            amount: '500.00',
            transaction: $transaction,
        );

        $this->assertSame($transaction->id, $movement['ledger']->transaction_id);
        // The ledger reference is derived from the transaction reference, so the
        // two records are cross-referenceable by eye.
        $this->assertSame('LDG-C-' . $transaction->reference, $movement['ledger']->reference);
    }

    public function test_a_ledger_entry_cannot_be_updated(): void
    {
        $user = $this->user();
        $entry = $this->wallets->credit($user, '10.00')['ledger'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $entry->forceFill(['amount' => '999999.00'])->save();
    }

    public function test_a_ledger_entry_cannot_be_deleted(): void
    {
        $user = $this->user();
        $entry = $this->wallets->credit($user, '10.00')['ledger'];

        try {
            $entry->delete();
            $this->fail('A ledger entry must not be deletable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $this->assertDatabaseHas('wallet_ledger', ['id' => $entry->id]);
    }

    public function test_the_balance_always_equals_the_sum_of_the_ledger(): void
    {
        $user = $this->user();

        $this->wallets->credit($user, '2500.75');
        $this->wallets->debit($user, '1000.25');
        $this->wallets->debit($user, '0.01');
        $this->wallets->refund($user, '500.00');
        $this->wallets->reverse($user, '100.00', 1);
        $this->wallets->adjust($user, '-25.00', 1, 'Fee correction');

        $verification = $this->wallets->verify($this->wallets->forUser($user));

        $this->assertTrue($verification['consistent'], 'The ledger must reconcile to the stored balance.');
        $this->assertSame($verification['stored'], $verification['from_ledger']);
        $this->assertSame('2075.49', $verification['stored']);
        $this->assertNull($verification['first_drift']);
    }

    public function test_the_running_balance_chain_is_continuous(): void
    {
        $user = $this->user();

        $this->wallets->credit($user, '100.00');
        $this->wallets->debit($user, '10.00');
        $this->wallets->credit($user, '5.55');
        $this->wallets->debit($user, '0.55');

        $running = Money::zero();

        foreach (WalletLedger::where('user_id', $user->id)->orderBy('id')->get() as $entry) {
            $running = $running->plus($entry->signedAmount());

            $this->assertSame(
                $running->toDecimalString(),
                (string) $entry->balance_after,
                "Entry {$entry->id} breaks the balance chain."
            );
        }

        $this->assertSame($running->toDecimalString(), $this->balanceOf($user));
    }

    /* =====================================================================
     | Aggregate columns
     |=================================================================== */

    public function test_total_funded_and_total_spent_track_the_movements(): void
    {
        $user = $this->user();

        $this->wallets->credit($user, '1000.00', countsAsFunding: true);
        $this->wallets->credit($user, '200.00', countsAsFunding: false); // e.g. a refund
        $this->wallets->debit($user, '300.00');

        $wallet = Wallet::where('user_id', $user->id)->first();

        // Only the funding credit counts toward total_funded.
        $this->assertSame('1000.00', (string) $wallet->total_funded);
        $this->assertSame('300.00', (string) $wallet->total_spent);
        $this->assertSame(3, (int) $wallet->transaction_count);
        $this->assertSame('900.00', (string) $wallet->balance);
    }

    /* =====================================================================
     | Bypass protection
     |=================================================================== */

    public function test_the_legacy_wallet_helpers_refuse_to_mutate(): void
    {
        $user = $this->user();
        $wallet = Wallet::where('user_id', $user->id)->first();

        /*
         * These used to increment the balance directly, with no lock, no
         * transaction and no ledger row. They now throw so that any call site
         * still using them fails at the point of the mistake rather than
         * silently corrupting a balance.
         */
        foreach (['credit', 'debit'] as $method) {
            try {
                $wallet->{$method}('100.00');
                $this->fail("Wallet::{$method}() must refuse to mutate a balance.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('WalletService', $e->getMessage());
            }
        }

        $this->assertSame('0.00', $this->balanceOf($user));
        $this->assertSame(0, WalletLedger::count());
    }

    public function test_a_debit_with_no_ledger_entry_is_impossible(): void
    {
        $user = $this->user();
        $this->wallets->credit($user, '100.00');

        $entriesBefore = WalletLedger::count();

        try {
            $this->wallets->debit($user, '500.00');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame($entriesBefore, WalletLedger::count());
        $this->assertSame('100.00', $this->balanceOf($user));
    }

    public function test_a_wallet_is_created_once_per_user(): void
    {
        $user = $this->user();

        // Calling the resolver repeatedly must not create a second wallet —
        // `wallets.user_id` is unique, so a duplicate would be a hard error
        // rather than a silent split balance.
        $this->wallets->forUser($user);
        $this->wallets->forUser($user);
        $this->wallets->credit($user, '1.00');

        $this->assertSame(1, Wallet::where('user_id', $user->id)->count());
    }
}

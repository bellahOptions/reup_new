<?php

namespace App\Services;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The only approved application-level path for moving wallet value.
 *
 * ## What "only path" means here
 *
 * Every write to `wallets.balance`, `total_funded`, `total_spent` and
 * `transaction_count` happens inside one of the movement methods below, inside
 * a database transaction, holding a `SELECT ... FOR UPDATE` row lock, with a
 * matching immutable `wallet_ledger` row written in the same transaction.
 *
 * There is no other supported way to change a balance. Direct writes —
 * `$wallet->increment('balance', ...)`, `$wallet->update([...])`,
 * `Wallet::credit()` — all bypass the lock, the ledger and the audit trail, and
 * are the mechanism by which balances drifted from their transactions. The
 * legacy `Wallet::credit()`/`debit()` helpers throw rather than run.
 *
 * ## Money
 *
 * Amounts are accepted as anything `App\Support\Money` can parse exactly
 * (decimal string, int, float, or a `Money`). They are converted to integer
 * kobo immediately and every balance calculation is integer arithmetic on the
 * kobo value, so `₦0.01` credits and hundreds of sequential movements stay
 * exact. Only the storage representation is decimal, because that is the
 * existing schema.
 *
 * ## Invariants enforced on every movement
 *
 *   1. the wallet row is locked before its balance is read;
 *   2. a debit may not take the balance below zero;
 *   3. `balance_after` = `balance_before` ± amount, exactly;
 *   4. a ledger row exists for every balance change, with matching before/after;
 *   5. the amounts are positive (a negative "credit" is a debit written by
 *      accident, and is rejected).
 */
class WalletService
{
    /* =====================================================================
     | Resolution
     |=================================================================== */

    /**
     * Resolve the wallet for a user, creating it if the `created` hook missed it.
     *
     * `firstOrCreate` rather than "read, then create": the `wallets.user_id`
     * unique index makes the create safe under concurrency, where two
     * simultaneous first requests would otherwise both insert and leave the
     * customer with two wallets and a randomly-selected balance.
     */
    public function forUser(User $user): Wallet
    {
        $wallet = $user->wallet()->first();

        if ($wallet) {
            return $wallet;
        }

        return Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'balance' => '0.00',
                'pending_balance' => '0.00',
                'total_funded' => '0.00',
                'total_spent' => '0.00',
                'transaction_count' => 0,
            ]
        );
    }

    /**
     * Re-read a wallet under a row lock.
     *
     * Must be called inside a database transaction: outside one, MySQL releases
     * the lock immediately and the guarantee is gone.
     */
    public function lockForUser(User $user): Wallet
    {
        $this->forUser($user);

        return Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * The authoritative current balance.
     *
     * Reads the database rather than a possibly-stale in-memory model, because
     * callers use this to decide whether a purchase is affordable and an
     * in-memory value can be arbitrarily out of date.
     */
    public function balanceFor(User $user): Money
    {
        return Money::fromDatabase($this->forUser($user)->balance);
    }

    /* =====================================================================
     | Movements
     |=================================================================== */

    /**
     * Move funds into a wallet.
     *
     * @param  string  $entryType     one of WalletLedger::ENTRY_*
     * @param  array<string,mixed>  $metadata
     */
    public function credit(
        User $user,
        $amount,
        bool $countsAsFunding = true,
        string $entryType = WalletLedger::ENTRY_CREDIT,
        ?string $description = null,
        ?Transactions $transaction = null,
        array $metadata = [],
        ?int $actorId = null
    ): array {
        $money = Money::fromNaira($amount);

        if (! $money->isPositive()) {
            throw new RuntimeException('Credit amount must be greater than zero.');
        }

        return $this->move(
            user: $user,
            money: $money,
            direction: WalletLedger::DIRECTION_CREDIT,
            entryType: $entryType,
            countsAsFunding: $countsAsFunding,
            description: $description ?? 'Wallet credit',
            transaction: $transaction,
            metadata: $metadata,
            actorId: $actorId,
        );
    }

    /**
     * Move funds out of a wallet, refusing to overdraw.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function debit(
        User $user,
        $amount,
        string $entryType = WalletLedger::ENTRY_DEBIT,
        ?string $description = null,
        ?Transactions $transaction = null,
        array $metadata = [],
        ?int $actorId = null
    ): array {
        $money = Money::fromNaira($amount);

        if (! $money->isPositive()) {
            throw new RuntimeException('Debit amount must be greater than zero.');
        }

        return $this->move(
            user: $user,
            money: $money,
            direction: WalletLedger::DIRECTION_DEBIT,
            entryType: $entryType,
            countsAsFunding: false,
            description: $description ?? 'Wallet debit',
            transaction: $transaction,
            metadata: $metadata,
            actorId: $actorId,
        );
    }

    /**
     * Take the funding service fee, after the funding itself is credited.
     *
     * ## Why the fee is here and not on the checkout
     *
     * The customer pays the amount they chose and is credited that amount, in
     * full. The service fee is then a separate, recorded debit, so the *net*
     * increase is the amount minus the fee and nothing the customer is asked to
     * pay is ever larger than the figure they typed.
     *
     * ## Contract
     *
     *   * **Idempotent.** The fee is a `transactions` row of its own, keyed by a
     *     reference derived from the funding row, and that reference is unique.
     *     A webhook replay, a browser callback and the reconciliation sweep can
     *     all arrive for the same funding and exactly one fee is taken — the
     *     second caller is told `already_charged` because the row is there.
     *   * **Never overdrafts.** The debit goes through the same locked path as
     *     every other movement, so a balance that cannot cover the fee is refused
     *     rather than driven negative. The funding credit is *not* rolled back:
     *     see the return value.
     *   * **Fails soft.** Anything that goes wrong is caught, recorded on the
     *     funding row and returned as `failed` — never thrown. This runs inside
     *     the settlement transaction, and an exception here would roll back a
     *     credit the customer has already paid for. That is the one outcome worse
     *     than an uncollected fee, so the fee is the thing that gives way.
     *   * **Recoverable.** A failure sets `meta.funding_fee_status = 'failed'`,
     *     which `payments:charge-funding-fees` retries. The fee is not silently
     *     lost.
     *
     * @param  array<string,mixed>  $metadata
     * @return array{status:string,fee:string,transaction:?Transactions,message:?string}
     *         status: charged | already_charged | none | failed
     */
    public function chargeFundingFee(User $user, Transactions $funding, array $metadata = []): array
    {
        $fee = $this->fundingFeeFor(Money::fromDatabase($funding->amount));

        if (! $fee->isPositive()) {
            return ['status' => 'none', 'fee' => '0.00', 'transaction' => null, 'message' => null];
        }

        $reference = self::fundingFeeReference((string) $funding->reference);

        // Serialise against a concurrent settlement of the same funding. The
        // unique index on `reference` is the structural guarantee; this is what
        // turns a duplicate-key race into the ordinary `already_charged` answer.
        $existing = Transactions::where('reference', $reference)->lockForUpdate()->first();

        if ($existing) {
            return ['status' => 'already_charged', 'fee' => (string) $existing->amount, 'transaction' => $existing, 'message' => null];
        }

        try {
            $feeTransaction = Transactions::create([
                'user_id' => $user->id,
                'reference' => $reference,
                'type' => 'debit',
                /*
                 * Its own service type, so the statement reads as "service fee"
                 * rather than as a purchase. It is not `funding`, because that
                 * would make the fee look like money the customer added, and it
                 * is not a bill type, because nothing was vended.
                 *
                 * `fee` is a widened value on the `transactions.service_type`
                 * ENUM — see the migration that adds it. Writing anything not in
                 * that list is silently *truncated* by MySQL rather than
                 * rejected, which is why the list is extended rather than
                 * bypassed.
                 */
                'service_type' => 'fee',
                'description' => 'Service fee on wallet funding',
                'amount' => $fee->toDecimalString(),
                'service_fee' => '0.00',
                'total_amount' => $fee->toDecimalString(),
                'recipient' => 'ReUp',
                'provider' => 'reup',
                // Not a gateway payment: this is our own charge, settled from the
                // balance the funding above just created. Marking it `paystack`
                // would invite a future reconciliation sweep to go looking for it
                // at the gateway.
                'payment_method' => 'wallet',
                'payment_status' => 'success',
                'status' => 'success',
                'paid_at' => now(),
                'completed_at' => now(),
                'meta' => array_merge($metadata, [
                    'kind' => 'funding_service_fee',
                    'funding_transaction_id' => $funding->getKey(),
                    'funding_reference' => $funding->reference,
                    'funded_amount' => (string) $funding->amount,
                    'charged_at' => now()->toDateTimeString(),
                ]),
            ]);

            $movement = $this->debit(
                user: $user,
                amount: $fee,
                entryType: WalletLedger::ENTRY_DEBIT,
                description: 'Service fee on wallet funding',
                transaction: $feeTransaction,
                metadata: [
                    'kind' => 'funding_service_fee',
                    'funding_transaction_id' => $funding->getKey(),
                ],
            );

            $feeTransaction->forceFill([
                'balance_before' => $movement['balance_before'],
                'balance_after' => $movement['balance_after'],
                'meta' => array_merge($feeTransaction->meta ?? [], [
                    'ledger_entry_id' => $movement['ledger']->id,
                ]),
            ])->save();

            return ['status' => 'charged', 'fee' => $fee->toDecimalString(), 'transaction' => $feeTransaction, 'message' => null];
        } catch (Throwable $e) {
            /*
             * Almost always "insufficient balance": the customer funded and then
             * spent the money before the fee was collected. The funding credit
             * stands — it is theirs — and the fee is marked for the retry sweep.
             *
             * The fee row is removed first: it was written before the debit
             * succeeded, and leaving a successful-looking debit for money that
             * never moved would make the statement lie.
             */
            if (isset($feeTransaction)) {
                $feeTransaction->forceFill(['status' => 'failed', 'payment_status' => 'failed', 'status_message' => $e->getMessage()])->save();
            }

            Log::warning('Funding service fee could not be collected', [
                'user_id' => $user->id,
                'funding_transaction_id' => $funding->getKey(),
                'fee' => $fee->toDecimalString(),
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'fee' => $fee->toDecimalString(), 'transaction' => null, 'message' => $e->getMessage()];
        }
    }

    /**
     * The service fee for a funded amount, in exact kobo.
     *
     * `percentage` is taken of the amount, `additional` is flat, `cap` bounds
     * their sum. Capped at the funded amount as well: a fee larger than the
     * credit would take money the customer did not just add.
     *
     * Public so the funding page can disclose the same figure it will be charged
     * — a preview computed by a second implementation is a preview that will
     * eventually be wrong.
     */
    public function fundingFeeFor(Money $amount): Money
    {
        $config = config('wallet.funding_fee', []);

        $fee = $amount->percentage((float) ($config['percentage'] ?? 0))
            ->plus(Money::fromDatabase($config['additional'] ?? 0));

        if (isset($config['cap']) && $config['cap'] !== null) {
            $fee = $fee->min(Money::fromDatabase($config['cap']));
        }

        return $fee->min($amount);
    }

    /**
     * The deterministic reference a funding row's fee will use.
     *
     * Derived rather than random, because the whole idempotency guarantee rests
     * on the same funding always producing the same fee reference. A random one
     * would let two callers each create a fee for the same funding.
     */
    public static function fundingFeeReference(string $fundingReference): string
    {
        return 'FEE-' . $fundingReference;
    }

    /**
     * Compensating credit for a failed purchase.
     *
     * Recorded as REFUND, not CREDIT, so a statement distinguishes money the
     * customer paid in from money given back.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function refund(        User $user,
        $amount,
        ?string $description = null,
        ?Transactions $transaction = null,
        array $metadata = [],
        ?int $actorId = null
    ): array {
        $money = Money::fromNaira($amount);

        if (! $money->isPositive()) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }

        return $this->move(
            user: $user,
            money: $money,
            direction: WalletLedger::DIRECTION_CREDIT,
            entryType: WalletLedger::ENTRY_REFUND,
            countsAsFunding: false,
            description: $description ?? 'Refund',
            transaction: $transaction,
            metadata: $metadata,
            actorId: $actorId,
        );
    }

    /**
     * Reverse a settled debit (admin cancellation of a completed purchase).
     *
     * Distinct from a refund so the audit trail records *who* reversed it and
     * why. A REVERSAL entry must always carry the acting administrator.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function reverse(
        User $user,
        $amount,
        int $actorId,
        ?string $description = null,
        ?Transactions $transaction = null,
        array $metadata = []
    ): array {
        $money = Money::fromNaira($amount);

        if (! $money->isPositive()) {
            throw new RuntimeException('Reversal amount must be greater than zero.');
        }

        return $this->move(
            user: $user,
            money: $money,
            direction: WalletLedger::DIRECTION_CREDIT,
            entryType: WalletLedger::ENTRY_REVERSAL,
            countsAsFunding: false,
            description: $description ?? 'Transaction reversed',
            transaction: $transaction,
            metadata: $metadata + ['reversal' => true],
            actorId: $actorId,
        );
    }

    /**
     * An operator moving a balance by hand.
     *
     * The amount may be negative, which makes it the one entry point that can
     * debit without a positive amount — an adjustment *is* signed. It is
     * deliberately separate from `credit()`/`debit()` so that an accidental
     * minus sign cannot turn a purchase into a credit.
     *
     * Every use must carry the acting administrator and a reason; both are
     * recorded on the ledger row.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function adjust(
        User $user,
        $amount,
        int $actorId,
        string $reason,
        array $metadata = []
    ): array {
        $money = Money::fromNaira($amount);

        if ($money->isZero()) {
            throw new RuntimeException('An adjustment of zero would change nothing.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('An administrative adjustment requires a reason.');
        }

        return $this->move(
            user: $user,
            money: $money->absolute(),
            direction: $money->isPositive() ? WalletLedger::DIRECTION_CREDIT : WalletLedger::DIRECTION_DEBIT,
            entryType: WalletLedger::ENTRY_ADMIN_ADJUSTMENT,
            countsAsFunding: false,
            description: $reason,
            transaction: null,
            metadata: $metadata + [
                'signed_amount' => $money->toDecimalString(),
                'reason' => $reason,
            ],
            actorId: $actorId,
        );
    }

    /* =====================================================================
     | The single movement implementation
     |=================================================================== */

    /**
     * Apply one movement to a locked wallet and record it.
     *
     * Every public movement method funnels through here, so there is exactly
     * one place where a balance changes and exactly one place where the ledger
     * is written. That is what makes "WalletService is the only mutation path"
     * checkable rather than aspirational.
     *
     * @param  array<string,mixed>  $metadata
     * @return array{
     *     wallet: Wallet,
     *     ledger: WalletLedger,
     *     balance_before: float,
     *     balance_after: float,
     *     amount: float,
     *     entry_type: string,
     *     direction: string
     * }
     */
    private function move(
        User $user,
        Money $money,
        string $direction,
        string $entryType,
        bool $countsAsFunding,
        string $description,
        ?Transactions $transaction,
        array $metadata,
        ?int $actorId
    ): array {
        if (! in_array($entryType, [
            WalletLedger::ENTRY_CREDIT,
            WalletLedger::ENTRY_DEBIT,
            WalletLedger::ENTRY_REFUND,
            WalletLedger::ENTRY_REVERSAL,
            WalletLedger::ENTRY_ADMIN_ADJUSTMENT,
        ], true)) {
            throw new RuntimeException("Unknown ledger entry type [{$entryType}].");
        }

        return DB::transaction(function () use (
            $user, $money, $direction, $entryType, $countsAsFunding, $description, $transaction, $metadata, $actorId
        ) {
            $wallet = $this->lockForUser($user);

            $before = Money::fromDatabase($wallet->balance);
            $after = $direction === WalletLedger::DIRECTION_CREDIT
                ? $before->plus($money)
                : $before->minus($money);

            // Overdraft guard. This is the check that makes two concurrent
            // ₦4,000 debits against a ₦5,000 balance resolve to one success and
            // one insufficient-balance error: the second request blocks on the
            // row lock here until the first has committed, and then reads the
            // post-debit balance.
            if ($after->isNegative()) {
                throw new RuntimeException(sprintf(
                    'Insufficient wallet balance. Required %s, available %s.',
                    $money->format(),
                    $before->format()
                ));
            }

            $wallet->balance = $after->toDecimalString();

            if ($countsAsFunding && $direction === WalletLedger::DIRECTION_CREDIT) {
                $wallet->total_funded = Money::fromDatabase($wallet->total_funded)->plus($money)->toDecimalString();
            }

            if ($direction === WalletLedger::DIRECTION_DEBIT) {
                $wallet->total_spent = Money::fromDatabase($wallet->total_spent)->plus($money)->toDecimalString();
            }

            $wallet->transaction_count = (int) $wallet->transaction_count + 1;
            $wallet->save();

            $ledger = WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'transaction_id' => $transaction?->getKey(),
                'amount' => $money->toDecimalString(),
                'currency' => Money::CURRENCY,
                'direction' => $direction,
                'entry_type' => $entryType,
                'balance_before' => $before->toDecimalString(),
                'balance_after' => $after->toDecimalString(),
                'reference' => $this->ledgerReference($entryType, $transaction),
                'description' => $description,
                'metadata' => $metadata ?: null,
                'actor_id' => $actorId,
            ]);

            return [
                'wallet' => $wallet,
                'ledger' => $ledger,
                /*
                 * Exact value objects, for callers doing further arithmetic or
                 * formatting. Added alongside the float keys below rather than
                 * replacing them, because a dozen call sites already assign
                 * `balance_before`/`balance_after` straight onto `decimal(15,2)`
                 * columns — where a `Money` object would be cast to its string
                 * form, which happens to be correct but only by accident.
                 */
                'before' => $before,
                'after' => $after,
                'amount_money' => $money,
                // Legacy float shape, kept for existing callers.
                'balance_before' => $before->toFloat(),
                'balance_after' => $after->toFloat(),
                'amount' => $money->toFloat(),
                'entry_type' => $entryType,
                'direction' => $direction,
            ];
        });
    }

    /**
     * A unique reference for a ledger row.
     *
     * Derived from the linked transaction where there is one, so a ledger entry
     * and the transaction it belongs to can be cross-referenced by eye. The
     * prefix keeps the two namespaces distinct, and `unique()` on the column
     * means a bug that tries to write the same movement twice fails the insert
     * rather than duplicating it.
     */
    private function ledgerReference(string $entryType, ?Transactions $transaction): string
    {
        $prefix = match ($entryType) {
            WalletLedger::ENTRY_DEBIT => 'LDG-D',
            WalletLedger::ENTRY_CREDIT => 'LDG-C',
            WalletLedger::ENTRY_REFUND => 'LDG-R',
            WalletLedger::ENTRY_REVERSAL => 'LDG-V',
            WalletLedger::ENTRY_ADMIN_ADJUSTMENT => 'LDG-A',
            default => 'LDG',
        };

        if ($transaction && $transaction->reference) {
            return $prefix . '-' . $transaction->reference;
        }

        return $prefix . '-' . strtoupper(Str::random(16));
    }

    /* =====================================================================
     | References
     |=================================================================== */

    /**
     * Generate a collision-resistant, non-sequential transaction reference.
     *
     * The previous implementation used `uniqid()`, which is derived from the
     * system clock and therefore guessable — combined with a lookup that was
     * not scoped to the authenticated user, that made references an attack
     * surface rather than just an identifier.
     */
    public function generateReference(string $prefix = 'TXN'): string
    {
        do {
            $reference = $prefix . '-' . now()->format('ymd') . '-' . strtoupper(Str::random(12));
        } while (Transactions::where('reference', $reference)->exists());

        return $reference;
    }

    /* =====================================================================
     | Verification
     |=================================================================== */

    /**
     * Recompute a wallet balance from its ledger.
     *
     * This is the invariant the ledger exists to make checkable: the current
     * balance must equal the sum of every signed movement recorded against the
     * wallet. Used by the balance-consistency test and available to an operator
     * (`wallet:verify`) when a customer disputes a balance.
     *
     * @return array{
     *     wallet_id:int,
     *     stored:string,
     *     from_ledger:string,
     *     consistent:bool,
     *     entries:int,
     *     first_drift:array|null,
     *     expected_from_transactions:string
     * }
     */
    public function verify(Wallet $wallet): array
    {
        $entries = WalletLedger::where('wallet_id', $wallet->id)
            ->orderBy('id')
            ->get();

        $running = Money::zero();
        $driftAt = null;

        foreach ($entries as $index => $entry) {
            $running = $running->plus($entry->signedAmount());

            if (! $running->equals($entry->balance_after)) {
                $driftAt = $driftAt ?? ['entry_id' => $entry->id, 'index' => $index];
            }
        }

        $stored = Money::fromDatabase($wallet->balance);

        /*
         * The movement total across the wallet's transaction history. Reported,
         * but deliberately NOT part of `consistent`.
         *
         * The ledger chain is the authority: it is written in the same
         * transaction as every balance change, so `from_ledger == stored` is a
         * hard invariant. The transaction sum is not, and cannot be made into
         * one retrospectively — rows predating the wallet service were written
         * by four different controllers that each maintained balances
         * differently, and a refund that was recorded on the transaction but
         * never credited (or vice versa) is precisely the historical defect the
         * ledger was introduced to stop. Surfacing the difference lets an
         * operator see it; failing a consistency check on history nobody can
         * reconstruct would be noise.
         */
        $credits = Money::fromDatabase(
            Transactions::where('user_id', $wallet->user_id)
                ->where('type', 'credit')
                ->where('status', 'success')
                ->where('service_type', '!=', 'refund')
                ->sum('total_amount')
        );

        $debits = Money::fromDatabase(
            Transactions::where('user_id', $wallet->user_id)
                ->where('type', 'debit')
                ->where('status', 'success')
                ->sum('total_amount')
        );

        return [
            'wallet_id' => $wallet->id,
            'stored' => $stored->toDecimalString(),
            'from_ledger' => $running->toDecimalString(),
            'consistent' => $running->equals($stored),
            'entries' => $entries->count(),
            'first_drift' => $driftAt,
            'expected_from_transactions' => $credits->minus($debits)->toDecimalString(),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single source of truth for wallet value movement.
 *
 * Before this existed, four controllers each re-implemented crediting by
 * writing `$user->update(['wallet_balance' => ...])` (a column that does not
 * exist and is not fillable, so the write was silently dropped) and *also*
 * mutating the `wallets` row by hand. The two stores drifted, balances were
 * read from a stale in-memory model after the write, and `total_spent` was
 * never incremented on most debit paths.
 *
 * Every mutation here happens inside a transaction with a row lock, so
 * concurrent purchases cannot both pass the funds check.
 */
class WalletService
{
    /**
     * Resolve the wallet for a user, creating it if the creating hook missed it.
     */
    public function forUser(User $user): Wallet
    {
        $wallet = $user->wallet()->first();

        if (! $wallet) {
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'balance' => 0,
                'pending_balance' => 0,
                'total_funded' => 0,
                'total_spent' => 0,
                'transaction_count' => 0,
            ]);
        }

        return $wallet;
    }

    /**
     * Re-read a wallet under a row lock.
     */
    public function lockForUser(User $user): Wallet
    {
        $this->forUser($user);

        return Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Move funds into a wallet. Idempotency is the caller's responsibility —
     * callers must already have established that the credit has not happened.
     *
     * @return array{wallet: Wallet, balance_before: float, balance_after: float}
     */
    public function credit(User $user, float $amount, bool $countsAsFunding = true): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Credit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($user, $amount, $countsAsFunding) {
            $wallet = $this->lockForUser($user);
            $before = (float) $wallet->balance;

            $wallet->balance = $before + $amount;
            if ($countsAsFunding) {
                $wallet->total_funded = (float) $wallet->total_funded + $amount;
            }
            $wallet->transaction_count = (int) $wallet->transaction_count + 1;
            $wallet->save();

            return [
                'wallet' => $wallet,
                'balance_before' => $before,
                'balance_after' => (float) $wallet->balance,
            ];
        });
    }

    /**
     * Remove funds from a wallet, rejecting the operation when the balance is
     * insufficient. The lock makes this safe under concurrency.
     *
     * @return array{wallet: Wallet, balance_before: float, balance_after: float}
     */
    public function debit(User $user, float $amount): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Debit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($user, $amount) {
            $wallet = $this->lockForUser($user);
            $before = (float) $wallet->balance;

            if (bccomp((string) $before, (string) $amount, 2) < 0) {
                throw new RuntimeException(
                    'Insufficient wallet balance. Required ₦' . number_format($amount, 2)
                    . ', available ₦' . number_format($before, 2) . '.'
                );
            }

            $wallet->balance = $before - $amount;
            $wallet->total_spent = (float) $wallet->total_spent + $amount;
            $wallet->transaction_count = (int) $wallet->transaction_count + 1;
            $wallet->save();

            return [
                'wallet' => $wallet,
                'balance_before' => $before,
                'balance_after' => (float) $wallet->balance,
            ];
        });
    }

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
}

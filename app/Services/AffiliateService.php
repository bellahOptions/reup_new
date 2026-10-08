<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\Transactions;
use App\Models\User;
use App\Models\WalletLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Referral rewards.
 *
 * ## The rule
 *
 * When a user who was referred funds their wallet for the first time with at
 * least ₦1,000, the person who referred them is credited ₦200, once.
 *
 * ## Why this is not just "credit ₦200 on funding"
 *
 * Money. Every decision below exists to make a double payout or a false payout
 * impossible rather than merely unlikely.
 *
 *   1. **One reward per referred user, forever.** Enforced by a unique index on
 *      `referrals.referred_id`, so a user cannot farm rewards by funding
 *      repeatedly, and cannot be "re-referred" by someone else later.
 *
 *   2. **One reward per funding transaction.** A unique index on
 *      `referrals.transaction_id`. Paystack retries webhooks until it gets a
 *      2xx; without this, the retry is a second payout.
 *
 *   3. **The threshold is measured on the wallet, not the event.** Qualifying
 *      uses `wallets.total_funded`, which accumulates across every funding
 *      method. A user who funds ₦600 by card and then ₦600 by transfer has
 *      funded ₦1,200 and does qualify — checking a single transaction amount
 *      would wrongly refuse them.
 *
 *   4. **Self-referral is refused.** Without this, a user refers themselves with
 *      a second account and mints ₦200 for ₦1,000 of their own money — a 20%
 *      cashback on a round trip through their own wallet.
 *
 *   5. **The reward is a normal wallet credit.** It goes through
 *      WalletService::credit() with an audit transaction row, so it appears in
 *      the referrer's history like any other credit. It is explicitly NOT
 *      counted as funding, so a reward cannot itself trigger another reward.
 *
 * The whole thing is wrapped in a transaction and rows are locked, because the
 * webhook that credits a wallet and the webhook that pays a referral can arrive
 * concurrently.
 */
class AffiliateService
{
    /** Reward paid to the referrer, in naira. */
    public const REWARD_AMOUNT = 200.00;

    /** Minimum lifetime funding for the referred user to qualify. */
    public const QUALIFYING_AMOUNT = 1000.00;

    public function __construct(private WalletService $wallets)
    {
    }

    /**
     * Evaluate a referral reward after a funding credit.
     *
     * Safe to call on every credit: it returns early when there is nothing to
     * do, and is idempotent when there is.
     *
     * @return Referral|null The reward that was paid, or null if none was due.
     */
    public function rewardIfEligible(User $referred, Transactions $fundingTransaction): ?Referral
    {
        // Nobody was referred, or the referrer has since been deleted.
        if (! $referred->referred_by_user_id) {
            return null;
        }

        // Already rewarded: one per referred user, forever.
        if (Referral::where('referred_id', $referred->id)->exists()) {
            return null;
        }

        $referrer = User::find($referred->referred_by_user_id);

        if (! $referrer) {
            Log::warning('Referral points at a missing referrer', [
                'referred_id' => $referred->id,
                'referrer_id' => $referred->referred_by_user_id,
            ]);

            return null;
        }

        // Self-referral guard. Also covers the case where the column was set by
        // a path that bypassed validation.
        if ($referrer->id === $referred->id) {
            Log::warning('Self-referral reward refused', ['user_id' => $referred->id]);

            return null;
        }

        // Lifetime funding, across every method. Read from the wallet so this
        // works whether the qualifying amount arrived in one payment or five.
        $totalFunded = (float) ($referred->wallet?->fresh()->total_funded ?? 0);

        if ($totalFunded < self::QUALIFYING_AMOUNT) {
            return null;
        }

        try {
            return DB::transaction(function () use ($referrer, $referred, $fundingTransaction, $totalFunded) {
                $reward = Referral::create([
                    'referrer_id' => $referrer->id,
                    'referred_id' => $referred->id,
                    'transaction_id' => $fundingTransaction->id,
                    'reward_amount' => self::REWARD_AMOUNT,
                    'qualifying_amount' => $totalFunded,
                    'reward_reference' => 'REF-' . strtoupper(Str::random(10)),
                    'paid_at' => now(),
                ]);

                /*
                 * A real transaction row, so the credit is visible in history
                 * and reconcilable like any other money movement. Created before
                 * the credit so the ledger entry can reference it — ledger rows
                 * are immutable, so nothing can point one at this row afterwards.
                 */
                $rewardTransaction = Transactions::create([
                    'user_id' => $referrer->id,
                    'reference' => 'AFF-' . now()->format('ymd') . '-' . strtoupper(Str::random(8)),
                    'type' => 'credit',
                    'service_type' => 'transfer',
                    'description' => 'Referral reward — ' . Str::limit($referred->name, 40, ''),
                    'amount' => self::REWARD_AMOUNT,
                    'service_fee' => '0.00',
                    'total_amount' => self::REWARD_AMOUNT,
                    'recipient' => $referrer->email,
                    'provider' => 'ReUp',
                    'payment_method' => 'wallet',
                    'payment_status' => 'success',
                    'status' => 'success',
                    'paid_at' => now(),
                    'completed_at' => now(),
                    // Unique per referral, so a replayed funding webhook cannot
                    // pay the same reward twice even if it got past the ledger.
                    'payment_reference' => 'REFERRAL-' . $reward->id,
                    'meta' => [
                        'referral_id' => $reward->id,
                        'referred_user_id' => $referred->id,
                        'trigger_transaction_id' => $fundingTransaction->id,
                    ],
                ]);

                /*
                 * WalletService locks the referrer's wallet before crediting, so
                 * two referrals landing at the same instant serialise instead of
                 * both reading the same balance, and the matching ledger entry is
                 * written in this same transaction.
                 */
                $movement = $this->wallets->credit(
                    user: $referrer,
                    amount: self::REWARD_AMOUNT,
                    // Not funding: a reward must not itself count toward a
                    // funding threshold, or it could cascade.
                    countsAsFunding: false,
                    entryType: WalletLedger::ENTRY_CREDIT,
                    description: 'Referral reward',
                    transaction: $rewardTransaction,
                    metadata: [
                        'referral_id' => $reward->id,
                        'referred_user_id' => $referred->id,
                        'trigger_transaction_id' => $fundingTransaction->id,
                    ],
                );

                $rewardTransaction->forceFill([
                    'balance_before' => $movement['balance_before'],
                    'balance_after' => $movement['balance_after'],
                ])->save();

                Log::info('Referral reward paid', [
                    'referrer_id' => $referrer->id,
                    'referred_id' => $referred->id,
                    'amount' => self::REWARD_AMOUNT,
                ]);

                return $reward;
            });
        } catch (QueryException $e) {
            /*
             * The unique indexes firing means a concurrent request already paid
             * this reward. That is the guard working, not an error — swallow it
             * so the funding webhook still returns 200 and Paystack stops
             * retrying.
             */
            if ($this->isUniqueViolation($e)) {
                Log::info('Referral reward already paid (unique constraint)', [
                    'referred_id' => $referred->id,
                ]);

                return null;
            }

            throw $e;
        }
    }

    /** How much this user has earned from referrals. */
    public function totalEarned(User $user): float
    {
        return (float) Referral::where('referrer_id', $user->id)->sum('reward_amount');
    }

    /** How many of this user's referrals have qualified. */
    public function qualifiedCount(User $user): int
    {
        return Referral::where('referrer_id', $user->id)->count();
    }

    /** Users this person has invited, qualified or not. */
    public function invitedCount(User $user): int
    {
        return User::where('referred_by_user_id', $user->id)->count();
    }

    /**
     * Resolve a referral code to the user who owns it.
     *
     * Case-insensitive and trimmed, because these codes get typed, pasted and
     * read aloud. Returns null for blank input rather than matching a user whose
     * code happens to be empty.
     */
    public function userForCode(?string $code): ?User
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        return User::where('referral_code', $code)->first();
    }

    /** Give a user a code if they do not have one yet. */
    public function ensureCode(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        do {
            $code = strtoupper(Str::random(8));
        } while (User::where('referral_code', $code)->exists());

        $user->forceFill(['referral_code' => $code])->saveQuietly();

        return $code;
    }

    /** The shareable sign-up URL for a user. */
    public function shareUrl(User $user): string
    {
        return route('register', ['ref' => $this->ensureCode($user)]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000 is the SQL standard integrity-constraint class; MySQL reports
        // 1062 for a duplicate key, SQLite 19.
        return in_array((string) $e->getCode(), ['23000', '23505'], true)
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}

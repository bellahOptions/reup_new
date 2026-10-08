<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use App\Models\Transactions;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

/**
 * Bank-level controls on money movement.
 *
 * Five mechanisms, all applied before a debit:
 *
 *   1. **Transaction PIN** — hashed like a password, never logged, required
 *      again for every purchase. Verified with a constant-time check.
 *   2. **PIN lockout** — five wrong PINs locks *purchases* for 15 minutes. The
 *      lock is time-boxed and clears itself; an ordinary mistyping never
 *      permanently locks an account.
 *   3. **Spending limits** — per-transaction, daily and monthly ceilings, and a
 *      platform-wide daily payout cap. This bounds the blast radius of a
 *      compromised account to one day's limit rather than the whole balance.
 *   4. **Velocity limiting** — a per-user rate limit on purchase attempts.
 *   5. **Idempotency** — a repeated request (double-click, retry, refreshed
 *      POST) returns the original outcome instead of charging twice.
 *
 * All monetary inputs and comparisons go through `App\Support\Money`, so a
 * limit of ₦500,000 and a purchase of ₦499,999.99 compare exactly.
 */
class SecurityService
{
    private const PIN_MAX_ATTEMPTS = 5;
    private const PIN_LOCK_MINUTES = 15;

    /**
     * How long a reserved idempotency key is honoured, and how long it is kept
     * for cleanup.
     *
     * A day is comfortably longer than any retry a real client performs, and
     * short enough that the table stays small.
     */
    private const IDEMPOTENCY_TTL_HOURS = 24;

    /* =====================================================================
     | Transaction PIN
     |=================================================================== */

    public function hasPin(User $user): bool
    {
        return ! empty($user->transaction_pin);
    }

    public function setPin(User $user, string $pin): void
    {
        if (! preg_match('/^[0-9]{4}$/', $pin)) {
            throw new RuntimeException('Your transaction PIN must be exactly 4 digits.');
        }

        // Obvious PINs are refused at set time. Weak-PIN rejection is a UX
        // guard, not a security control: the lockout below is what actually
        // makes guessing infeasible.
        if (preg_match('/^(.)\1{3}$/', $pin)
            || in_array($pin, ['1234', '0123', '4321', '1111', '0000', '2222', '9999'], true)
            || $this->isSequential($pin)) {
            throw new RuntimeException('That PIN is too easy to guess. Choose a less obvious one.');
        }

        $user->forceFill([
            'transaction_pin' => Hash::make($pin),
            'transaction_pin_set_at' => now(),
            'failed_pin_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();

        Log::info('Transaction PIN set', ['user_id' => $user->id]);
    }

    /**
     * Verify a PIN, applying lockout on repeated failure.
     *
     * @throws RuntimeException when the PIN is wrong, absent, or locked out.
     */
    public function verifyPin(User $user, ?string $pin): void
    {
        // Rate limit first: the lockout below is per-account and time-boxed,
        // but a distributed attempt across many accounts is better stopped
        // before it starts. This also keeps the failure path cheap.
        $this->assertPinAttemptsAllowed($user);

        $this->assertNotLocked($user);

        if (! $this->hasPin($user)) {
            throw new RuntimeException('Set a transaction PIN in your profile before making a purchase.');
        }

        if (! $pin) {
            throw new RuntimeException('Enter your transaction PIN to continue.');
        }

        /*
         * Hash::check is constant-time. The PIN itself is never logged, never
         * included in an exception message and never returned — a rejected PIN
         * is reported as "incorrect" and nothing else.
         */
        if (! Hash::check($pin, $user->transaction_pin)) {
            $this->recordFailedPin($user);

            throw new RuntimeException('That transaction PIN is incorrect.');
        }

        if ((int) $user->failed_pin_attempts > 0 || $user->pin_locked_until) {
            $user->forceFill(['failed_pin_attempts' => 0, 'pin_locked_until' => null])->save();
        }

        RateLimiter::clear($this->pinRateKey($user));
    }

    /** Per-user ceiling on PIN submissions, which bounds an online guess. */
    private function assertPinAttemptsAllowed(User $user): void
    {
        $key = $this->pinRateKey($user);
        $perMinute = (int) config('bills.limits.pin_attempts_per_minute', 5);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            Log::warning('Transaction PIN submissions throttled', [
                'user_id' => $user->id,
                'available_in' => RateLimiter::availableIn($key),
            ]);

            throw new RuntimeException(
                'Too many PIN attempts. Please wait ' . RateLimiter::availableIn($key) . ' second(s).'
            );
        }

        RateLimiter::hit($key, 60);
    }

    private function pinRateKey(User $user): string
    {
        return 'pin-attempt:' . $user->id;
    }

    private function assertNotLocked(User $user): void
    {
        if ($user->pin_locked_until && $user->pin_locked_until->isFuture()) {
            $seconds = (int) now()->diffInSeconds($user->pin_locked_until, false);

            throw new RuntimeException(
                'Too many incorrect PIN attempts. Try again in ' . max(1, (int) ceil($seconds / 60)) . ' minute(s).'
            );
        }
    }

    /**
     * Count a failed attempt, locking the PIN after too many.
     *
     * The lock is temporary and clears itself, and the attempt counter resets
     * when it is applied, so a lockout can never stack into a permanent one.
     */
    private function recordFailedPin(User $user): void
    {
        $attempts = (int) $user->failed_pin_attempts + 1;

        $attributes = ['failed_pin_attempts' => $attempts];

        if ($attempts >= self::PIN_MAX_ATTEMPTS) {
            $attributes['pin_locked_until'] = now()->addMinutes(self::PIN_LOCK_MINUTES);
            $attributes['failed_pin_attempts'] = 0;

            /*
             * A security event, not an ordinary application warning: it is the
             * signal that an account is being attacked, and it is what an
             * operator looks for. No PIN material is included.
             */
            Log::warning('SECURITY: transaction PIN locked after repeated failures', [
                'event' => 'pin_lockout',
                'user_id' => $user->id,
                'attempts' => self::PIN_MAX_ATTEMPTS,
                'locked_for_minutes' => self::PIN_LOCK_MINUTES,
                'ip' => request()?->ip(),
            ]);
        } else {
            Log::notice('SECURITY: failed transaction PIN attempt', [
                'event' => 'pin_failure',
                'user_id' => $user->id,
                'attempt' => $attempts,
                'remaining' => max(0, self::PIN_MAX_ATTEMPTS - $attempts),
            ]);
        }

        $user->forceFill($attributes)->save();
    }

    private function isSequential(string $pin): bool
    {
        $ascending = true;
        $descending = true;

        for ($i = 1; $i < strlen($pin); $i++) {
            $delta = (int) $pin[$i] - (int) $pin[$i - 1];

            if ($delta !== 1) {
                $ascending = false;
            }

            if ($delta !== -1) {
                $descending = false;
            }
        }

        return $ascending || $descending;
    }

    /* =====================================================================
     | Spending limits
     |=================================================================== */

    /**
     * Enforce every configured ceiling for a single purchase.
     *
     * All arithmetic is on integer kobo via `Money`; the per-user and
     * platform-wide sums are read from the database, not from a cache, because
     * a stale "spent today" is a limit that can be exceeded.
     *
     * @throws RuntimeException on the first breach.
     */
    public function assertWithinLimits(User $user, $amount, string $serviceType): void
    {
        $amount = Money::fromNaira($amount);

        $perTransaction = Money::fromDatabase(config('bills.limits.per_transaction', 200000));
        $daily = Money::fromDatabase(config('bills.limits.daily_per_user', 500000));
        $monthly = Money::fromDatabase(config('bills.limits.monthly_per_user', 5000000));

        if ($amount->greaterThan($perTransaction)) {
            throw new RuntimeException(
                'The most you can spend in a single transaction is ' . $perTransaction->format() . '.'
            );
        }

        // Service-specific ceilings, e.g. a lower cap on electricity.
        $serviceCap = config('bills.limits.per_service.' . $serviceType);

        if ($serviceCap && $amount->greaterThan(Money::fromDatabase($serviceCap))) {
            throw new RuntimeException(
                'The most you can spend on this service in one transaction is '
                . Money::fromDatabase($serviceCap)->format() . '.'
            );
        }

        $spentToday = Money::fromDatabase(
            Transactions::where('user_id', $user->id)
                ->where('type', 'debit')
                ->whereIn('status', ['processing', 'success', 'unknown'])
                ->whereDate('created_at', today())
                ->sum('total_amount')
        );

        if ($spentToday->plus($amount)->greaterThan($daily)) {
            throw new RuntimeException(
                'This would exceed your daily spending limit. You have '
                . $daily->minus($spentToday)->max(Money::zero())->format() . ' left today.'
            );
        }

        $spentThisMonth = Money::fromDatabase(
            Transactions::where('user_id', $user->id)
                ->where('type', 'debit')
                ->whereIn('status', ['processing', 'success', 'unknown'])
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('total_amount')
        );

        if ($spentThisMonth->plus($amount)->greaterThan($monthly)) {
            throw new RuntimeException('This would exceed your monthly spending limit.');
        }

        // Platform-wide ceiling: bounds total exposure if many accounts are
        // compromised at once, and catches runaway automation.
        $platformDaily = Money::fromDatabase(config('bills.limits.platform_daily', 0));

        if ($platformDaily->isPositive()) {
            $platformSpent = Money::fromDatabase(
                Transactions::where('type', 'debit')
                    ->whereIn('status', ['processing', 'success', 'unknown'])
                    ->whereDate('created_at', today())
                    ->sum('total_amount')
            );

            if ($platformSpent->plus($amount)->greaterThan($platformDaily)) {
                Log::critical('Platform daily payout ceiling reached', [
                    'spent' => $platformSpent->toDecimalString(),
                    'attempted' => $amount->toDecimalString(),
                    'ceiling' => $platformDaily->toDecimalString(),
                ]);

                throw new RuntimeException(
                    'Purchases are temporarily paused for a routine reconciliation. Please try again later.'
                );
            }
        }
    }

    /* =====================================================================
     | Velocity limiting
     |=================================================================== */

    /**
     * @throws RuntimeException when the user is submitting too fast.
     */
    public function assertNotThrottled(User $user): void
    {
        $key = 'purchase:' . $user->id;
        $perMinute = (int) config('bills.limits.per_minute', 6);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw new RuntimeException(
                'You are making purchases too quickly. Please wait '
                . RateLimiter::availableIn($key) . ' second(s).'
            );
        }

        RateLimiter::hit($key, 60);
    }

    /* =====================================================================
     | Idempotency
     |====================================================================
     |
     | The database is the authority. `idempotency_keys.key` carries a UNIQUE
     | index, so two concurrent requests presenting the same key cannot both
     | proceed — the second INSERT raises a duplicate-key error, which is the
     | only mechanism that works across processes, across application servers,
     | and across a cache flush.
     |
     | The cache is retained purely as a fast path for the *response lookup*
     | (so a retry that arrives after the row exists does not need to touch the
     | database to learn the outcome). It is never what decides whether a
     | request may proceed. If the cache is empty, cold, or flushed, correctness
     | is unchanged.
     */

    /**
     * Reserve a key, or report that it has already been used.
     *
     * @return array{status:string,record:IdempotencyKey,replay:bool,transaction:?Transactions,
     *               request_hash:?string,hash_mismatch:bool}
     */
    public function reserve(string $key, string $scope, User $user, ?string $requestHash = null): array
    {
        $key = $this->normaliseKey($key);

        try {
            $record = IdempotencyKey::create([
                'key' => $key,
                'scope' => $scope,
                'request_hash' => $requestHash,
                'user_id' => $user->id,
                'status' => IdempotencyKey::STATUS_RESERVED,
                'expires_at' => now()->addHours(self::IDEMPOTENCY_TTL_HOURS),
            ]);

            return [
                'status' => 'reserved',
                'record' => $record,
                'replay' => false,
                'transaction' => null,
                'request_hash' => $requestHash,
                'hash_mismatch' => false,
            ];
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }
        }

        // Somebody already holds this key. Re-read it and report the outcome.
        $existing = IdempotencyKey::where('key', $key)->first();

        if (! $existing) {
            /*
             * The unique-key violation fired but the row is not visible. That
             * happens when a concurrent transaction that inserted the key has
             * not committed yet (MySQL blocks the second INSERT until the first
             * commits, then raises the duplicate error — so by the time we get
             * here the row normally *is* visible). Treat it as "in flight" and
             * refuse rather than proceed: proceeding is the double-charge.
             */
            throw new RuntimeException('This request is already being processed. Please wait a moment.');
        }

        /*
         * A key reused with a different request body is not a replay.
         *
         * It is either a client bug or an attempt to have one processed key
         * answer for a second, different purchase. Both must be refused: the
         * first because it silently loses a purchase, the second because it
         * would fulfil a second purchase without charging for it.
         */
        $hashMismatch = $existing->request_hash !== null
            && $requestHash !== null
            && ! hash_equals($existing->request_hash, $requestHash);

        if ($hashMismatch) {
            Log::warning('Idempotency key reused with a different request body', [
                'key' => $key,
                'scope' => $scope,
                'user_id' => $user->id,
                'transaction_id' => $existing->transaction_id,
            ]);
        }

        // A key belonging to another user must never reveal that user's
        // transaction, and must never be honoured for this one.
        if ($existing->user_id !== null && (int) $existing->user_id !== (int) $user->id) {
            Log::warning('Idempotency key presented by a different user than it was issued to', [
                'key' => $key,
                'scope' => $scope,
                'presenting_user_id' => $user->id,
            ]);

            throw new RuntimeException('This request has already been processed.');
        }

        return [
            'status' => $existing->status,
            'record' => $existing,
            'replay' => true,
            'transaction' => $existing->transaction,
            'request_hash' => $existing->request_hash,
            'hash_mismatch' => $hashMismatch,
        ];
    }

    /**
     * Mark a reserved key as completed and store the outcome.
     *
     * @param  array<string,mixed>|null  $response
     */
    public function complete(IdempotencyKey $record, ?Transactions $transaction = null, ?array $response = null): void
    {
        if ($record->status === IdempotencyKey::STATUS_COMPLETED) {
            return;
        }

        $record->forceFill([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'transaction_id' => $transaction?->getKey() ?? $record->transaction_id,
            'response' => $response,
        ])->save();

        // Fast path only. Losing this entry costs a database read, nothing more.
        try {
            Cache::put($this->cacheKey($record->key), [
                'transaction_id' => $record->transaction_id,
                'status' => $record->status,
            ], now()->addHours(self::IDEMPOTENCY_TTL_HOURS));
        } catch (Throwable $e) {
            Log::debug('Idempotency cache write failed; database remains authoritative', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Release a reservation that produced no transaction.
     *
     * Only safe when the work definitely did not happen — an exception thrown
     * before any debit. Marked `failed` rather than deleted so a retry is
     * visible in the table instead of vanishing.
     */
    public function release(IdempotencyKey $record, ?string $reason = null): void
    {
        $record->forceFill([
            'status' => IdempotencyKey::STATUS_FAILED,
            'response' => ['released' => true, 'reason' => $reason],
        ])->save();
    }

    /**
     * Find the transaction a previously-used key produced.
     *
     * Retained for the pre-existing call sites that only need the outcome.
     */
    public function replay(?string $key): ?Transactions
    {
        if (! $key) {
            return null;
        }

        $key = $this->normaliseKey($key);

        $record = IdempotencyKey::where('key', $key)->first();

        if (! $record || $record->status !== IdempotencyKey::STATUS_COMPLETED) {
            return null;
        }

        return $record->transaction;
    }

    /** @deprecated Use reserve()/complete(); kept for callers mid-migration. */
    public function remember(string $key, Transactions $transaction): void
    {
        $key = $this->normaliseKey($key);

        $record = IdempotencyKey::firstOrNew(['key' => $key]);
        $record->fill([
            'scope' => $record->scope ?: 'bill_purchase',
            'user_id' => $record->user_id ?: $transaction->user_id,
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'transaction_id' => $transaction->getKey(),
            'expires_at' => now()->addHours(self::IDEMPOTENCY_TTL_HOURS),
        ])->save();
    }

    /**
     * The stable fingerprint of a purchase request.
     *
     * Used to decide whether a reused key describes the same operation. Built
     * from the values that determine what is bought and for how much, not from
     * the whole request (which carries a CSRF token and timestamp and would
     * therefore differ on every submission).
     *
     * @param  array<string,mixed>  $parts
     */
    public function requestHash(User $user, array $parts): string
    {
        $canonical = json_encode([
            'user' => $user->id,
            'parts' => $this->canonicalise($parts),
        ]);

        return hash('sha256', (string) $canonical);
    }

    /**
     * Recursively sort an array by key so that two requests with the same
     * parameters in a different order hash identically.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function canonicalise($value)
    {
        if (! is_array($value)) {
            return is_string($value) ? trim($value) : $value;
        }

        if (! $this->isAssociative($value)) {
            return array_map(fn ($item) => $this->canonicalise($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->canonicalise($item), $value);
    }

    /** @param array<mixed> $value */
    private function isAssociative(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * Keys are opaque client-generated tokens. Constraining the shape keeps an
     * attacker from using a megabyte key as a storage amplifier, and keeps the
     * `unique` index inside its maximum length.
     */
    private function normaliseKey(string $key): string
    {
        if (! preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $key)) {
            throw new RuntimeException('Invalid request signature.');
        }

        return $key;
    }

    private function cacheKey(string $key): string
    {
        return 'idem:' . $key;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        // 23000 is the SQLSTATE class for integrity constraint violations;
        // 1062 is MySQL's specific "duplicate entry" error.
        return $e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1062;
    }

    /* =====================================================================
     | Duplicate-submission guard (no key supplied)
     |====================================================================
     |
     | Forms that do not carry an idempotency key still need protection from a
     | double-click. This is a short cache window and is therefore a UX guard,
     | not the financial control — the key above is the control. It is
     | intentionally documented as such so nobody later mistakes it for one.
     */

    public function assertNotDuplicate(User $user, string $serviceType, $amount, string $recipient): void
    {
        $fingerprint = hash('sha256', implode('|', [
            $user->id,
            $serviceType,
            Money::fromNaira($amount)->toDecimalString(),
            trim($recipient),
        ]));

        $key = 'dup:' . $fingerprint;

        if (Cache::has($key)) {
            throw new RuntimeException(
                'An identical purchase was just submitted. Please wait a moment before trying again.'
            );
        }

        Cache::put($key, true, now()->addSeconds((int) config('bills.limits.duplicate_window', 20)));
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Bank-level controls on money movement.
 *
 * Four mechanisms, all applied before a debit:
 *
 *   1. **Transaction PIN** — hashed like a password, never logged, required
 *      again for every purchase. Verified with a constant-time check.
 *   2. **PIN lockout** — five wrong PINs locks purchases for 15 minutes. Without
 *      this a stolen session is also a stolen PIN, because there is no limit on
 *      guesses.
 *   3. **Spending limits** — per-transaction, daily and monthly ceilings, and a
 *      platform-wide daily payout cap. This bounds the blast radius of a
 *      compromised account to one day's limit rather than the whole balance.
 *   4. **Idempotency** — a repeated request (double-click, retry, refreshed
 *      POST) returns the original outcome instead of charging twice.
 */
class SecurityService
{
    private const PIN_MAX_ATTEMPTS = 5;
    private const PIN_LOCK_MINUTES = 15;

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

        if (preg_match('/^(.)\1{3}$/', $pin) || in_array($pin, ['1234', '0123', '1111', '0000'], true)) {
            throw new RuntimeException('That PIN is too easy to guess. Choose a less obvious one.');
        }

        $user->forceFill([
            'transaction_pin' => Hash::make($pin),
            'transaction_pin_set_at' => now(),
            'failed_pin_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();
    }

    /**
     * Verify a PIN, applying lockout on repeated failure.
     *
     * @throws RuntimeException when the PIN is wrong, absent, or locked out.
     */
    public function verifyPin(User $user, ?string $pin): void
    {
        $this->assertNotLocked($user);

        if (! $this->hasPin($user)) {
            throw new RuntimeException('Set a transaction PIN in your profile before making a purchase.');
        }

        if (! $pin) {
            throw new RuntimeException('Enter your transaction PIN to continue.');
        }

        if (! Hash::check($pin, $user->transaction_pin)) {
            $this->recordFailedPin($user);

            throw new RuntimeException('That transaction PIN is incorrect.');
        }

        if ((int) $user->failed_pin_attempts > 0 || $user->pin_locked_until) {
            $user->forceFill(['failed_pin_attempts' => 0, 'pin_locked_until' => null])->save();
        }
    }

    private function assertNotLocked(User $user): void
    {
        if ($user->pin_locked_until && $user->pin_locked_until->isFuture()) {
            $minutes = (int) ceil(now()->diffInSeconds($user->pin_locked_until) / 60);

            throw new RuntimeException(
                "Too many incorrect PIN attempts. Try again in {$minutes} minute(s)."
            );
        }
    }

    private function recordFailedPin(User $user): void
    {
        $attempts = (int) $user->failed_pin_attempts + 1;

        $attributes = ['failed_pin_attempts' => $attempts];

        if ($attempts >= self::PIN_MAX_ATTEMPTS) {
            $attributes['pin_locked_until'] = now()->addMinutes(self::PIN_LOCK_MINUTES);
            $attributes['failed_pin_attempts'] = 0;

            Log::warning('Transaction PIN locked after repeated failures', ['user_id' => $user->id]);
        }

        $user->forceFill($attributes)->save();
    }

    /* =====================================================================
     | Spending limits
     |=================================================================== */

    /**
     * Enforce every configured ceiling for a single purchase.
     *
     * @throws RuntimeException on the first breach.
     */
    public function assertWithinLimits(User $user, float $amount, string $serviceType): void
    {
        $perTransaction = (float) config('bills.limits.per_transaction', 200000);
        $daily = (float) config('bills.limits.daily_per_user', 500000);
        $monthly = (float) config('bills.limits.monthly_per_user', 5000000);

        if ($amount > $perTransaction) {
            throw new RuntimeException(
                'The most you can spend in a single transaction is ₦' . number_format($perTransaction, 2) . '.'
            );
        }

        // Service-specific ceilings, e.g. a lower cap on electricity.
        $serviceCap = config('bills.limits.per_service.' . $serviceType);

        if ($serviceCap && $amount > (float) $serviceCap) {
            throw new RuntimeException(
                'The most you can spend on this service in one transaction is ₦'
                . number_format((float) $serviceCap, 2) . '.'
            );
        }

        $spentToday = \App\Models\Transactions::where('user_id', $user->id)
            ->where('type', 'debit')
            ->whereIn('status', ['processing', 'success'])
            ->whereDate('created_at', today())
            ->sum('total_amount');

        if ($spentToday + $amount > $daily) {
            $remaining = max(0, $daily - $spentToday);

            throw new RuntimeException(
                'This would exceed your daily spending limit. You have ₦'
                . number_format($remaining, 2) . ' left today.'
            );
        }

        $spentThisMonth = \App\Models\Transactions::where('user_id', $user->id)
            ->where('type', 'debit')
            ->whereIn('status', ['processing', 'success'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('total_amount');

        if ($spentThisMonth + $amount > $monthly) {
            throw new RuntimeException('This would exceed your monthly spending limit.');
        }

        // Platform-wide ceiling: bounds total exposure if many accounts are
        // compromised at once, and catches runaway automation.
        $platformDaily = (float) config('bills.limits.platform_daily', 0);

        if ($platformDaily > 0) {
            $platformSpent = \App\Models\Transactions::where('type', 'debit')
                ->whereIn('status', ['processing', 'success'])
                ->whereDate('created_at', today())
                ->sum('total_amount');

            if ($platformSpent + $amount > $platformDaily) {
                Log::critical('Platform daily payout ceiling reached', [
                    'spent' => $platformSpent,
                    'attempted' => $amount,
                    'ceiling' => $platformDaily,
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
     |=================================================================== */

    /**
     * Reserve an idempotency key, or return the outcome of the original request.
     *
     * The key is supplied by the form and stored on the transaction itself, so
     * a replay finds the existing row rather than creating a second one.
     *
     * @return \App\Models\Transactions|null the original transaction if this is a replay
     */
    public function replay(?string $key): ?\App\Models\Transactions
    {
        if (! $key) {
            return null;
        }

        $reference = Cache::get($this->idempotencyKey($key));

        if (! $reference) {
            return null;
        }

        return \App\Models\Transactions::where('reference', $reference)->first();
    }

    public function remember(string $key, \App\Models\Transactions $transaction): void
    {
        Cache::put($this->idempotencyKey($key), $transaction->reference, now()->addHours(24));
    }

    private function idempotencyKey(string $key): string
    {
        if (! preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $key)) {
            throw new RuntimeException('Invalid request signature.');
        }

        return 'idem:' . $key;
    }

    /**
     * Guard against the same form being submitted twice within a few seconds,
     * when no idempotency key was supplied.
     */
    public function assertNotDuplicate(User $user, string $serviceType, float $amount, string $recipient): void
    {
        $fingerprint = md5($user->id . '|' . $serviceType . '|' . $amount . '|' . $recipient);
        $key = 'dup:' . $fingerprint;

        if (Cache::has($key)) {
            throw new RuntimeException(
                'An identical purchase was just submitted. Please wait a moment before trying again.'
            );
        }

        Cache::put($key, true, now()->addSeconds((int) config('bills.limits.duplicate_window', 20)));
    }
}

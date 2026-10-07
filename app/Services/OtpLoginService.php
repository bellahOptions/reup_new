<?php

namespace App\Services;

use App\Models\LoginOtp;
use App\Models\User;
use App\Notifications\CustomLoginOtp;
use App\Notifications\CustomPinOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One-time sign-in codes.
 *
 * This is a password alternative, not a second factor: presenting a valid code
 * is sufficient to sign in. That single fact drives every decision below.
 *
 * ## What is hashed and why
 *
 * Only a bcrypt hash of the 6-digit code is stored. A 6-digit space is small
 * (10^6), so a hash alone is not much protection against an offline attack —
 * but it does mean a database leak or a read-only SQL injection does not hand
 * over live codes. The real defence against guessing is the attempt cap below,
 * which is enforced before the hash comparison.
 *
 * Codes are generated with random_int(), not rand()/mt_rand(): the latter are
 * predictable from their seed, and predictable here means account takeover.
 *
 * ## The four limits
 *
 *   1. Resend cooldown       — per account, so one account cannot be used to
 *                              spam a mailbox.
 *   2. Hourly request cap    — per account AND per IP, so neither a single
 *                              mailbox nor a single host can be flooded.
 *   3. Attempt cap per code  — 5, which makes brute force non-viable against a
 *                              10-minute window.
 *   4. Single use            — consumed codes are never honoured again, so a
 *                              replayed request cannot re-authenticate.
 *
 * ## Timing
 *
 * Issuing a code for an unknown address performs the same work and returns the
 * same result as for a known one. Callers must never branch on existence: the
 * controller always shows the same "check your email" page. Without that, the
 * form becomes an account-enumeration oracle.
 */
class OtpLoginService
{
    /** How long a code stays valid. */
    public const TTL_MINUTES = 10;

    /** Wrong guesses allowed against a single code before it dies. */
    public const MAX_ATTEMPTS = 5;

    /** Seconds a user must wait between code requests. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Codes a single account may request per hour. */
    public const MAX_PER_HOUR_PER_ACCOUNT = 6;

    /** Codes a single IP may request per hour. */
    public const MAX_PER_HOUR_PER_IP = 20;

    /** Signing in. */
    public const PURPOSE = 'login';

    /** Authorising a transaction-PIN set or change. */
    public const PURPOSE_PIN = 'pin';

    /**
     * Create and send a code, or decline to.
     *
     * Returns true when a code was issued. A false return must NOT be surfaced
     * differently from a true one — see the timing note on the class.
     */
    public function issue(User $user, ?string $ip, ?string $userAgent, string $purpose = self::PURPOSE): bool
    {
        if (! $this->canIssue($user, $ip)) {
            Log::notice('OTP issue suppressed by rate limit', ['user_id' => $user->id]);

            return false;
        }

        // Retire any outstanding code first: only the newest one should work, so
        // a code the user already has in their inbox cannot be used after they
        // request a fresh one.
        $this->invalidateOutstanding($user, $purpose);

        $code = $this->generateCode();

        LoginOtp::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'attempts' => 0,
            'requested_ip' => $ip,
            'requested_user_agent' => $userAgent ? Str::limit($userAgent, 255, '') : null,
        ]);

        $this->hitLimits($user, $ip);

        // Sent last: if delivery throws, the throttle counters are already set,
        // which fails closed rather than allowing a retry storm.
        //
        // The notification matches the purpose. A PIN authorisation that arrived
        // titled "Your sign-in code" would be indistinguishable from a phishing
        // attempt, and customers are told to ignore exactly that.
        $user->notify($purpose === self::PURPOSE_PIN
            ? new CustomPinOtp($code, self::TTL_MINUTES, $ip)
            : new CustomLoginOtp($code, self::TTL_MINUTES, $ip));

        return true;
    }

    /**
     * Verify a submitted code for a user.
     *
     * @return bool True when the code was accepted and consumed.
     */
    public function verify(User $user, string $code, string $purpose = self::PURPOSE): bool
    {
        // Lock the row for the duration of the check so two concurrent requests
        // cannot both consume the same code, and so the attempt counter cannot
        // be lost to a race.
        return DB::transaction(function () use ($user, $code, $purpose) {
            $otp = LoginOtp::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                return false;
            }

            // Expired: burn it so it cannot be retried, and fail.
            if ($otp->expires_at->isPast()) {
                $otp->forceFill(['consumed_at' => now()])->save();

                return false;
            }

            // Cap reached before the comparison, so a locked-out code cannot be
            // brute forced by racing the counter.
            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                $otp->forceFill(['consumed_at' => now()])->save();

                return false;
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                // Kill the code as soon as the cap is hit rather than leaving it
                // to expire, so the window closes immediately.
                if ($otp->attempts >= self::MAX_ATTEMPTS) {
                    $otp->forceFill(['consumed_at' => now()])->save();
                }

                return false;
            }

            // Success. Mark consumed before returning: this is what makes the
            // code genuinely single-use.
            $otp->forceFill(['consumed_at' => now()])->save();

            return true;
        });
    }

    /** The newest code still awaiting input, if any. */
    public function outstanding(User $user, string $purpose = self::PURPOSE): ?LoginOtp
    {
        return LoginOtp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();
    }

    /** Seconds until the account may request another code. */
    public function secondsUntilResend(User $user): int
    {
        $key = $this->cooldownKey($user);

        return RateLimiter::tooManyAttempts($key, 1)
            ? RateLimiter::availableIn($key)
            : 0;
    }

    /**
     * Invalidate every code outstanding for a user, for one purpose.
     *
     * Purpose-scoped so issuing a sign-in code cannot silently kill a PIN code
     * the user is midway through entering, and vice versa.
     */
    public function invalidateOutstanding(User $user, string $purpose = self::PURPOSE): void
    {
        LoginOtp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }

    /** 6 digits, uniformly distributed across 000000–999999. */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function canIssue(User $user, ?string $ip): bool
    {
        if (RateLimiter::tooManyAttempts($this->cooldownKey($user), 1)) {
            return false;
        }

        if (RateLimiter::tooManyAttempts($this->accountKey($user), self::MAX_PER_HOUR_PER_ACCOUNT)) {
            return false;
        }

        if ($ip !== null && RateLimiter::tooManyAttempts($this->ipKey($ip), self::MAX_PER_HOUR_PER_IP)) {
            return false;
        }

        return true;
    }

    private function hitLimits(User $user, ?string $ip): void
    {
        RateLimiter::hit($this->cooldownKey($user), self::RESEND_COOLDOWN_SECONDS);
        RateLimiter::hit($this->accountKey($user), 3600);

        if ($ip !== null) {
            RateLimiter::hit($this->ipKey($ip), 3600);
        }
    }

    private function cooldownKey(User $user): string
    {
        return 'otp.cooldown.' . $user->id;
    }

    private function accountKey(User $user): string
    {
        return 'otp.account.' . $user->id;
    }

    private function ipKey(string $ip): string
    {
        return 'otp.ip.' . $ip;
    }
}

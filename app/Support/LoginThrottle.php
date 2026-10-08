<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Per-account credential throttling.
 *
 * ## Why the route middleware is not enough
 *
 * `throttle:10,1` on the login route keys on the client IP. That stops one host
 * hammering the form, and nothing else. An attacker with a botnet — or with a
 * residential proxy pool, which is cheap — gets ten attempts per minute *per
 * address*, and the account under attack sees an unbounded number of guesses.
 * The IP is also not a stable identity: a mobile user changes it between
 * requests.
 *
 * This adds the key the IP cannot provide: **the account being targeted**.
 * Either key tripping is enough to refuse.
 *
 * ## Why it is deliberately lenient per email
 *
 * Locking an account out on failed logins is a denial-of-service primitive: an
 * attacker who knows a customer's email can lock them out of their own money by
 * getting it wrong on purpose. So this is a *rate limit*, not a lockout — it
 * always expires, the counter clears on success, and the wait is short. The
 * thing standing between an attacker and an account is still the password
 * hash; this only removes the "unlimited guesses" part.
 */
class LoginThrottle
{
    /** Attempts allowed per email address, per window. */
    private const PER_EMAIL = 5;

    /** Attempts allowed per IP address, per window. */
    private const PER_IP = 20;

    /** Window length, in seconds. */
    private const DECAY_SECONDS = 60;

    public static function emailKey(string $email): string
    {
        // Lower-cased and hashed: an email address in a cache key is personal
        // data, and cache keys end up in logs and in `redis-cli` output.
        return 'login-email:' . hash('sha256', Str::lower(trim($email)));
    }

    public static function ipKey(Request $request): string
    {
        return 'login-ip:' . $request->ip();
    }

    /**
     * Whether this attempt must be refused, and how long to wait.
     *
     * @return array{blocked:bool,retry_after:int}
     */
    public static function check(Request $request, string $email): array
    {
        $emailKey = self::emailKey($email);
        $ipKey = self::ipKey($request);

        if (RateLimiter::tooManyAttempts($emailKey, self::PER_EMAIL)) {
            return ['blocked' => true, 'retry_after' => RateLimiter::availableIn($emailKey)];
        }

        if (RateLimiter::tooManyAttempts($ipKey, self::PER_IP)) {
            return ['blocked' => true, 'retry_after' => RateLimiter::availableIn($ipKey)];
        }

        return ['blocked' => false, 'retry_after' => 0];
    }

    /** Count a failed attempt against both keys. */
    public static function recordFailure(Request $request, string $email): void
    {
        RateLimiter::hit(self::emailKey($email), self::DECAY_SECONDS);
        RateLimiter::hit(self::ipKey($request), self::DECAY_SECONDS);
    }

    /**
     * Clear the account's counter after a successful sign-in.
     *
     * Deliberately does not clear the IP counter: a host that has been guessing
     * across many accounts should stay throttled even when it finally gets one
     * right, because that is the shape of a credential-stuffing run.
     */
    public static function clear(string $email): void
    {
        RateLimiter::clear(self::emailKey($email));
    }
}

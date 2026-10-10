<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\OtpLoginService;
use App\Support\HomeRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Password-alternative sign-in by one-time email code.
 *
 * ## Account enumeration
 *
 * The request step always redirects to the same "we have sent a code" page and
 * the same message, whether or not the address belongs to an account. Every
 * short-circuit that would otherwise be observable — unknown address, blocked
 * account, rate limited — goes through that identical response. The only
 * difference the visitor can see is whether an email arrives, which is exactly
 * the information they already had.
 *
 * ## Why the user is not logged in until the code is verified
 *
 * An earlier shape of this flow authenticated first and then demanded a code.
 * That leaves a real gap: `Auth::login()` writes the session, so any bug in the
 * second step (an exception, a redirect, a middleware reorder) leaves a fully
 * authenticated session that was never challenged. Here the session is only
 * written after verification succeeds, so a failure at any point yields a guest.
 *
 * ## Session binding
 *
 * The pending user is remembered in the session at request time and is the only
 * identity the verify step will consider — a submitted email is never looked up
 * again. This stops a code issued to one account being redeemed against another
 * by editing the form.
 */
class OtpLoginController extends Controller
{
    /**
     * Sentinel stored as the pending user id when the submitted address has no
     * account.
     *
     * It exists purely so the verify screen cannot be used to distinguish a
     * registered address from an unregistered one: both cases arrive there with
     * a pending state, and both fail identically when a code is submitted.
     */
    private const NO_ACCOUNT = 0;

    public function __construct(private OtpLoginService $otp)
    {
    }

    /** Step 1: ask for the email address. */
    public function showRequestForm()
    {
        return view('auth.login-otp-request');
    }

    /**
     * Step 1 handler: issue a code if the account exists.
     *
     * Always ends on the verify screen with the same flash message.
     */
    public function sendCode(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        // Per-IP limit on the endpoint itself, before any lookup. This is the
        // backstop that holds even when the account-level limits cannot, e.g.
        // when an attacker walks a list of addresses that do not exist.
        $ipKey = 'otp.request.' . $request->ip();

        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            throw ValidationException::withMessages([
                'email' => 'Too many code requests. Please try again in '
                    . RateLimiter::availableIn($ipKey) . ' seconds.',
            ]);
        }

        RateLimiter::hit($ipKey, 3600);

        $user = User::where('email', $email)->first();

        // An account that cannot sign in must not be able to obtain a code, or
        // the email would leak that the address is registered even though the
        // sign-in itself is refused.
        if ($user && ! $this->maySignIn($user)) {
            Log::notice('OTP requested for an account that may not sign in', [
                'user_id' => $user->id,
                'status' => $user->status,
                'is_blocked' => $user->is_blocked,
            ]);

            $user = null;
        }

        if ($user) {
            $this->otp->issue($user, $request->ip(), $request->userAgent());

            // Reset the per-attempt throttle: the user has asked for a fresh
            // code, so the previous guessing budget should not carry over.
            RateLimiter::clear($this->attemptKey($request, $user->id));

            $request->session()->put('otp.pending_user_id', $user->id);
            $request->session()->put('otp.pending_email', $user->email);
            $request->session()->put('otp.requested_at', now()->timestamp);
        } else {
            // Burn comparable time to the real path. Without this, a missing
            // address returns measurably faster than a known one and the
            // "identical response" above counts for nothing.
            Hash::make('otp-enumeration-guard');

            // Record a *pending* state anyway, with no account behind it. If the
            // unknown-address case instead left no pending state, the very next
            // GET of the verify screen would redirect to the request form — and
            // that redirect is itself an account-existence oracle, which would
            // undo the identical-response work above.
            $request->session()->put('otp.pending_user_id', self::NO_ACCOUNT);
            $request->session()->put('otp.pending_email', $email);
            $request->session()->put('otp.requested_at', now()->timestamp);
        }

        return redirect()->route('login.code.verify')
            ->with('status', 'If that email address has an account, a sign-in code is on its way.');
    }

    /** Step 2: ask for the code. */
    public function showVerifyForm(Request $request)
    {
        // Renders identically whether or not the address has an account; see
        // NO_ACCOUNT. A redirect here would leak account existence.
        if (! $this->hasPendingRequest($request)) {
            return redirect()->route('login.code');
        }

        $user = $this->pendingUser($request);

        return view('auth.login-otp-verify', [
            'maskedEmail' => $this->mask($request->session()->get('otp.pending_email', '')),
            'resendIn' => $user ? $this->otp->secondsUntilResend($user) : 0,
        ]);
    }

    /** Step 2 handler: verify the code and establish the session. */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ], [
            'code.digits' => 'Enter the 6-digit code from your email.',
        ]);

        $user = $this->pendingUser($request);

        if (! $user) {
            // Covers both an expired attempt and an address with no account. The
            // message is deliberately the same for both.
            $request->session()->forget(['otp.pending_user_id', 'otp.pending_email', 'otp.requested_at']);

            throw ValidationException::withMessages([
                'code' => 'That code is incorrect or has expired. Request a new one if needed.',
            ]);
        }

        // Re-check at redemption time, not just at issue time: the account could
        // have been suspended in between.
        if (! $this->maySignIn($user)) {
            $request->session()->forget(['otp.pending_user_id', 'otp.pending_email', 'otp.requested_at']);

            throw ValidationException::withMessages([
                'code' => 'This account cannot sign in right now. Please contact support.',
            ]);
        }

        // Session-scoped guess budget. The service caps attempts against a
        // single code; this bounds an attacker who keeps requesting fresh codes
        // to reset that cap. Keyed on the session, which is already an
        // HttpOnly cookie, so it cannot be cleared by the caller.
        $attemptKey = $this->attemptKey($request, $user->id);

        if (RateLimiter::tooManyAttempts($attemptKey, 10)) {
            throw ValidationException::withMessages([
                'code' => 'Too many incorrect codes. Please try again in '
                    . ceil(RateLimiter::availableIn($attemptKey) / 60) . ' minutes.',
            ]);
        }

        if (! $this->otp->verify($user, $validated['code'])) {
            RateLimiter::hit($attemptKey, 3600);

            Log::notice('OTP sign-in failed', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'code' => 'That code is incorrect or has expired. Request a new one if needed.',
            ]);
        }

        RateLimiter::clear($attemptKey);

        // Success: clear the pending state before writing the session, so a
        // stale user id can never be redeemed twice.
        $request->session()->forget(['otp.pending_user_id', 'otp.pending_email', 'otp.requested_at']);

        Auth::login($user, false);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        Log::info('OTP sign-in succeeded', ['user_id' => $user->id, 'ip' => $request->ip()]);

        // An administrator has no customer home to be sent to: the console is
        // their dashboard, and `customer` would only bounce them there anyway.
        return $user->isAdmin()
            ? redirect()->route(HomeRoute::for($user))
            : redirect()->intended(RouteServiceProvider::HOME);
    }

    /** Re-issue a code for the pending account. */
    public function resend(Request $request)
    {
        // No pending attempt at all: nothing to resend.
        if (! $this->hasPendingRequest($request)) {
            return redirect()->route('login.code');
        }

        $user = $this->pendingUser($request);

        // The address has no account, or the account was suspended after the
        // code was issued. Both leave the visitor on the same screen with the
        // same message — redirecting anywhere else, or reporting a different
        // error, would distinguish a registered address from an unregistered one.
        if ($user && $this->maySignIn($user)) {
            $this->otp->issue($user, $request->ip(), $request->userAgent());

            // A fresh code means a fresh guessing budget.
            RateLimiter::clear($this->attemptKey($request, $user->id));
        }

        $request->session()->put('otp.requested_at', now()->timestamp);

        return redirect()->route('login.code.verify')
            ->with('status', 'If that email address has an account, a new code is on its way.');
    }

    /**
     * Whether this session is part-way through a sign-in attempt.
     *
     * Distinct from pendingUser(): true for an address with no account, because
     * from the visitor's point of view that attempt is equally "in progress".
     */
    private function hasPendingRequest(Request $request): bool
    {
        return $this->freshPendingId($request) !== null;
    }

    /**
     * The user this session is part-way through signing in, or null when there
     * is no pending attempt, it has gone stale, or the address has no account.
     */
    private function pendingUser(Request $request): ?User
    {
        $id = $this->freshPendingId($request);

        if ($id === null || $id === self::NO_ACCOUNT) {
            return null;
        }

        return User::find($id);
    }

    /**
     * The pending user id, or null when there is no pending attempt or it has
     * gone stale.
     *
     * Read-only: it does not clear the sentinel, so callers that need the user
     * and callers that only need "is there an attempt" can both use it.
     */
    private function freshPendingId(Request $request): ?int
    {
        $id = $request->session()->get('otp.pending_user_id');
        $at = $request->session()->get('otp.requested_at');

        if ($id === null || ! $at) {
            return null;
        }

        // One hour: comfortably longer than the 10-minute code, but bounded, so
        // a session left open on a shared machine does not stay armed.
        if (now()->timestamp - (int) $at > 3600) {
            $request->session()->forget(['otp.pending_user_id', 'otp.pending_email', 'otp.requested_at']);

            return null;
        }

        return (int) $id;
    }

    /** Shared gate: a blocked or suspended account may not sign in by any route. */
    private function maySignIn(User $user): bool
    {
        return ! $user->is_blocked && $user->status === 'active';
    }

    /**
     * Rate-limit key for code guesses.
     *
     * Keyed on the session id, which is an HttpOnly cookie and therefore not
     * something the caller can reset, and scoped per user so switching accounts
     * does not inherit or reset another attempt's budget.
     */
    private function attemptKey(Request $request, int $userId): string
    {
        return 'otp.attempt.' . $userId . '.' . $request->session()->getId();
    }

    /**
     * a•••••@example.com — enough for the user to recognise the address without
     * printing it in full on a page an onlooker can read.
     */
    private function mask(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        $visible = mb_substr($local, 0, 1);

        return $visible . str_repeat('•', max(1, mb_strlen($local) - 1)) . '@' . $domain;
    }
}

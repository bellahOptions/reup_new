<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use App\Support\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create()
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * Rate limiting is layered:
     *
     *   * `throttle:10,1` on the route bounds attempts per client IP;
     *   * `LoginThrottle` adds a per-*account* counter, which is the key an IP
     *     cannot provide — otherwise a credential-stuffing run from a proxy pool
     *     gets ten guesses per address per minute and the target account sees no
     *     limit at all.
     *
     * A refusal must not reveal whether the address is registered, so the
     * message is the same generic one used for wrong credentials.
     */
    public function store(LoginRequest $request)
    {
        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        $throttle = LoginThrottle::check($request, (string) $credentials['email']);

        if ($throttle['blocked']) {
            $seconds = max(1, $throttle['retry_after']);

            \Illuminate\Support\Facades\Log::notice('SECURITY: sign-in attempts throttled', [
                'event' => 'login_throttled',
                'retry_after' => $seconds,
                'ip' => $request->ip(),
            ]);

            return back()->withErrors([
                'email' => "Too many sign-in attempts. Please try again in {$seconds} second(s).",
            ])->withInput();
        }

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            LoginThrottle::recordFailure($request, (string) $credentials['email']);

            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->withInput();
        }

        // Log the user in securely
        Auth::login($user, $remember);

        // Regenerate session to prevent fixation attacks
        $request->session()->regenerate();

        // A successful sign-in clears this account's counter.
        LoginThrottle::clear((string) $credentials['email']);

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}

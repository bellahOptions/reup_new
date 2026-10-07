<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::check() && Auth::user()->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    /**
     * Admin sign-in.
     *
     * Additions over the previous implementation:
     *   - credentials are checked with Auth::attempt() first, so a wrong
     *     password is indistinguishable from an unknown account (the old code
     *     leaked account existence with "You do not have admin privileges");
     *   - attempts are rate limited per email+IP;
     *   - a session is only established for a verified admin.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $throttleKey = mb_strtolower($credentials['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Try again in '
                    . RateLimiter::availableIn($throttleKey) . ' seconds.',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 300);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $user = Auth::user();

        // An authenticated non-admin must not hold a session that can reach
        // the console; log them straight back out.
        if (! $user->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'is_online' => true,
        ])->save();

        AdminLog::log($user->id, 'login', [
            'remember' => $request->boolean('remember'),
            'ip' => $request->ip(),
        ]);

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Bootstrap registration form.
     *
     * Reachable only while the platform has no administrator. The previous
     * guard was
     *     Auth::check() && !Auth::user()->isSuperAdmin() && User::admins()->exists()
     * which evaluates to false for an anonymous visitor, so the check was
     * skipped entirely and anyone could POST /admin/register.
     */
    public function showRegisterForm()
    {
        if (! $this->bootstrapAllowed()) {
            abort(404);
        }

        return view('admin.auth.register');
    }

    public function register(Request $request)
    {
        if (! $this->bootstrapAllowed()) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:12|confirmed',
            'phone' => 'nullable|string|max:20',
        ]);

        // Closed inside a transaction with a lock so two concurrent bootstrap
        // requests cannot both succeed.
        $admin = DB::transaction(function () use ($validated) {
            if (User::admins()->lockForUpdate()->exists()) {
                abort(404);
            }

            return User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'is_admin' => true,
                'is_super_admin' => true,
                'admin_role' => 'admin',
                'admin_permissions' => Permissions::ALL,
                'email_verified_at' => now(),
            ]);
        });

        AdminLog::log($admin->id, 'bootstrap_super_admin', ['email' => $admin->email]);

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')
            ->with('success', 'Founding administrator account created. Add further administrators from Admins → New.');
    }

    public function logout(Request $request)
    {
        $user = Auth::user();

        if ($user && $user->isAdmin()) {
            AdminLog::log($user->id, 'logout');
            $user->forceFill(['is_online' => false])->saveQuietly();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'You have been signed out.');
    }

    /**
     * Registration is open only when there is no administrator at all.
     */
    private function bootstrapAllowed(): bool
    {
        return ! User::admins()->exists();
    }
}

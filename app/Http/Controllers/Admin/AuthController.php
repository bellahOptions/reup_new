<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\AdminLog;

class AuthController extends Controller
{
    // Show admin login form
    public function showLoginForm()
    {
        return view('admin.auth.login');
    }

    // Admin login
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:8',
        ]);

        $credentials = $request->only('email', 'password');
        
        // First check if user exists and is admin
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user) {
            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->onlyInput('email');
        }

        // Check if user is admin
        if (!$user->isAdmin()) {
            return back()->withErrors([
                'email' => 'You do not have admin privileges.',
            ])->onlyInput('email');
        }

        // Attempt to authenticate
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Update last login
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip()
            ]);

            // Log admin login
            AdminLog::log($user->id, 'login', [
                'type' => 'admin',
                'remember' => $request->boolean('remember')
            ]);

            return redirect()->intended(route('admin.dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    // Show admin registration form (only for super admins)
    public function showRegisterForm()
    {
        // Only allow registration if no admins exist or current user is super admin
        if (Auth::check() && !Auth::user()->isSuperAdmin() && User::admins()->exists()) {
            return redirect()->route('admin.dashboard')->withErrors([
                'error' => 'Only super admins can register new admin users.'
            ]);
        }

        return view('admin.auth.register');
    }

    // Admin registration
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'admin_role' => 'required|in:admin,moderator,support',
            'phone' => 'nullable|string|max:20',
        ]);

        // Check if current user can register admins
        if (Auth::check() && !Auth::user()->isSuperAdmin() && User::admins()->exists()) {
            return redirect()->route('admin.dashboard')->withErrors([
                'error' => 'Only super admins can register new admin users.'
            ]);
        }

        // Create admin user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'is_admin' => true,
            'is_super_admin' => false,
            'admin_role' => $request->admin_role,
            'admin_permissions' => $this->getDefaultPermissions($request->admin_role),
            'email_verified_at' => now(), // Auto-verify admin emails
        ]);

        // Log admin registration
        AdminLog::log(Auth::id() ?? $user->id, 'register_admin', [
            'admin_id' => $user->id,
            'role' => $request->admin_role
        ]);

        // If not logged in, login the new admin
        if (!Auth::check()) {
            Auth::login($user);
            return redirect()->route('admin.dashboard')->with('success', 'Admin account created successfully!');
        }

        return redirect()->route('admin.users.index')->with('success', 'Admin user created successfully!');
    }

    // Admin logout
    public function logout(Request $request)
    {
        $user = Auth::user();
        
        // Log admin logout
        if ($user && $user->isAdmin()) {
            AdminLog::log($user->id, 'logout');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'You have been logged out successfully.');
    }

    // Get default permissions based on role
    private function getDefaultPermissions($role)
    {
        $permissions = [
            'admin' => [
                'view_dashboard',
                'manage_users',
                'view_transactions',
                'manage_transactions',
                'view_reports',
                'manage_settings'
            ],
            'moderator' => [
                'view_dashboard',
                'view_users',
                'view_transactions',
                'manage_transactions',
                'view_reports'
            ],
            'support' => [
                'view_dashboard',
                'view_users',
                'view_transactions',
                'view_reports'
            ]
        ];

        return $permissions[$role] ?? [];
    }
}
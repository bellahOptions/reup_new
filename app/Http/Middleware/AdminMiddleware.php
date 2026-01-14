<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next, $permission = null)
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return redirect()->route('admin.login')->withErrors([
                'email' => 'Please login to access the admin area.'
            ]);
        }

        $user = Auth::user();

        // Check if user is admin
        if (!$user->isAdmin()) {
            return redirect()->route('home')->withErrors([
                'error' => 'You do not have permission to access the admin area.'
            ]);
        }

        // Check specific permission if provided
        if ($permission && !$user->hasPermission($permission)) {
            return redirect()->route('admin.dashboard')->with('error', 'Insufficient permissions.');
        }

        // Update last activity
        if ($user->isAdmin()) {
            $user->update(['last_activity_at' => now()]);
        }

        // Log admin access
        \App\Models\AdminLog::log(Auth::id(), 'admin_access', [
            'route' => $request->route()->getName(),
            'url' => $request->fullUrl()
        ]);

        return $next($request);
    }
}
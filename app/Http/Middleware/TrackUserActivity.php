<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TrackUserActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // Update last activity timestamp
            $user->update([
                'last_activity' => now(),
                'is_online' => true
            ]);
            
            // Update last login if it's their first request in this session
            if (!session()->has('login_tracked')) {
                $user->update(['last_login_at' => now()]);
                session(['login_tracked' => true]);
            }
        }

        return $next($request);
    }
}

// Register this middleware in app/Http/Kernel.php:
// 
// protected $middlewareGroups = [
//     'web' => [
//         // ... other middleware
//         \App\Http\Middleware\TrackUserActivity::class,
//     ],
// ];
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class AdminMiddleware
{
    /**
     * Gate the admin console.
     *
     * Usage in routes: `->middleware('admin')` or `->middleware('admin:manage_users')`.
     *
     * This previously did two things on *every* admin request: an unconditional
     * UPDATE on the users row and an AdminLog INSERT. On a polling dashboard
     * that is a write per notification tick. Both are now throttled, and the
     * activity timestamp is only written when it is actually stale.
     */
    public function handle(Request $request, Closure $next, ?string $permission = null)
    {
        if (! Auth::check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->route('admin.login');
        }

        $user = Auth::user();

        if (! $user->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            abort(403, 'You do not have access to the admin console.');
        }

        if ($permission && ! $user->hasPermission($permission)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Insufficient permissions.'], 403);
            }

            abort(403, 'Your admin role does not include the "' . $permission . '" permission.');
        }

        $this->touchActivity($user, $request);

        return $next($request);
    }

    /**
     * Record liveness at most once a minute per admin, and only when the
     * stored value is older than that.
     */
    private function touchActivity($user, Request $request): void
    {
        if (Cache::has('admin.activity.' . $user->id)) {
            return;
        }

        Cache::put('admin.activity.' . $user->id, true, now()->addMinute());

        if ($user->last_activity_at === null || $user->last_activity_at->lt(now()->subMinute())) {
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        // Access log: only meaningful navigations, not background polling.
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            \App\Models\AdminLog::log($user->id, 'admin_access', [
                'route' => optional($request->route())->getName(),
            ]);
        }
    }
}

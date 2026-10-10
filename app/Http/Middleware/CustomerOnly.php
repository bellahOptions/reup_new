<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Keep administrators out of the customer-facing application.
 *
 * ## Why this exists
 *
 * Administrators and customers are rows in the same `users` table behind the
 * same `web` guard — `is_admin` / `is_super_admin` are flags on a customer
 * account, not a separate identity. Nothing in the customer area checked them,
 * so an administrator who signed in and then navigated to `/dashboard` landed
 * on the **customer** dashboard: a wallet, a fund button, a purchase flow. An
 * administrator is not a customer, and the console already has its own screen
 * for every one of those questions.
 *
 * Two things went wrong when that happened, and only the first is cosmetic:
 *
 *   1. the administrator sees the wrong application, with no route back except
 *      typing the console URL;
 *   2. the customer area **writes** to the account — a wallet funding row, a
 *      virtual account, a purchase — against an account that is also trusted to
 *      approve refunds and move wallets in the console. Segregation of duties
 *      fails at exactly the point an auditor would test it.
 *
 * So the answer is not "hide the links". Every customer route redirects.
 *
 * ## Why a redirect and not a 403
 *
 * The account is authenticated and authorised for *something* — just not this.
 * A 403 would present the administrator with a dead end and no way forward,
 * which is how "the admin area is broken" gets reported. `redirect()->intended`
 * is not used either: an administrator who was mid-way through a customer URL
 * has no legitimate reason to be returned to it after signing into the console.
 *
 * ## Scope
 *
 * Applied to the customer `auth` group and to the handful of `auth`-only routes
 * outside it (avatar, e-mail verification, password confirmation, and the
 * customer registration form). It is deliberately **not** applied to public
 * pages — the landing page, terms, contact, the pricelist — because an
 * administrator reading the marketing site or the public price list is not
 * crossing a boundary; the boundary is the authenticated customer application.
 */
class CustomerOnly
{
    /**
     * The console route an administrator is sent to.
     *
     * Resolved by name at request time rather than cached, so a deployment
     * whose admin routes are not registered cannot leave this middleware
     * pointing at a route that does not exist.
     */
    private const ADMIN_HOME = 'admin.dashboard';

    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user()->isAdmin()) {
            /*
             * A JSON caller gets a machine-readable answer instead of a 302 to
             * an HTML page. Without this, the admin console's own background
             * polling — if it ever reaches a customer URL — would receive a
             * login-page body with a 200 and fail in a way that looks like a
             * parsing bug.
             */
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Administrator accounts use the admin console.',
                    'redirect' => route(self::ADMIN_HOME),
                ], 403);
            }

            return redirect()
                ->route(self::ADMIN_HOME)
                ->with('info', 'You are signed in as an administrator, so you were taken to the admin console.');
        }

        return $next($request);
    }
}

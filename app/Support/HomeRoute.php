<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Where a freshly authenticated account belongs.
 *
 * ## Why this is one place
 *
 * Administrators and customers share the `users` table and the `web` guard, so
 * "you have signed in, go home" has two different answers. Four entry points ask
 * it — password sign-in, one-time-code sign-in, registration, and the `guest`
 * middleware it hits when an authenticated visitor opens /login — and when they
 * answered it separately they disagreed: an administrator signing in was sent to
 * `/dashboard`, which then bounced them to the console through
 * {@see \App\Http\Middleware\CustomerOnly}. Two redirects to reach the right
 * page is visible, and the intermediate hop is a page the account may not use.
 *
 * ## Why it is not `RouteServiceProvider::HOME`
 *
 * That constant is used by tests and by the framework's own redirects as the
 * *customer* home, and it should keep meaning exactly that. This resolves the
 * destination for a specific account instead.
 */
class HomeRoute
{
    /**
     * The named route an authenticated account should land on.
     *
     * Falls back to the customer home when the console is not registered on this
     * deployment, so a missing admin route cannot turn sign-in into a 500.
     */
    public static function for(?User $user): string
    {
        if ($user !== null && $user->isAdmin() && Route::has('admin.dashboard')) {
            return 'admin.dashboard';
        }

        return 'dashboard';
    }
}

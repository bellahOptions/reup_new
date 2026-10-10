<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use App\Support\HomeRoute;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @param  string|null  ...$guards
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next, ...$guards)
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                $user = Auth::guard($guard)->user();

                /*
                 * An authenticated administrator opening /login is sent to the
                 * console, not to the customer dashboard. `RouteServiceProvider
                 * ::HOME` is the customer home, and using it here would land the
                 * administrator on a page `customer` immediately redirects away
                 * from — a second hop, and a flash of the wrong application.
                 */
                return redirect()->route(HomeRoute::for($user));
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\ServiceGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses, or announces, a service that is not live.
 *
 * ## Why this exists as middleware rather than a check in each controller
 *
 * A service has several entry points — its landing page, its history, its purchase
 * endpoint, its AJAX helpers — and a flag checked in the controller of the first one
 * leaves the others open. That is not a cosmetic problem: a purchase endpoint that
 * stays reachable for a service whose provider is not contracted is a way to take a
 * customer's money for something nobody can deliver.
 *
 * Applied to a route **group**, one line covers every entry point at once, including
 * the ones added later. A new route inside a guarded group is guarded by default,
 * which is the direction this needs to fail in.
 *
 * ## What each state returns
 *
 *   * **live** — the request continues untouched;
 *   * **coming soon** — a 200 with a page that says so. A 200 because the page is a
 *     real destination with real content, and because a link in an email or an
 *     advertisement should land somewhere that explains itself;
 *   * **disabled** — a 503. Not a 404: the route exists and the customer did not
 *     mistype it, so telling them "not found" is a lie about their own navigation.
 *     503 is also honest about the state — the service is not being served.
 *
 * ## Live requests are not touched at all
 *
 * The live path adds one array lookup and returns. There is no attribute written to
 * the request, no view shared, no query — so a service that is switched on behaves
 * exactly as it did before this middleware existed.
 */
class EnsureServiceEnabled
{
    public function __construct(
        private readonly ServiceGate $gate,
    ) {
    }

    public function handle(Request $request, Closure $next, string $service): Response
    {
        if ($this->gate->isLive($service)) {
            return $next($request);
        }

        $definition = $this->gate->definition($service);

        /*
         * An unknown service key is a routing mistake, and refusing is the right
         * answer — see `ServiceGate::state()`. It is reported as disabled rather than
         * announced, because "coming soon" is a promise and this code cannot make one
         * about a service it does not know.
         */
        $state = $definition === null ? ServiceGate::DISABLED : $this->gate->state($service);

        return response()->view('services.unavailable', [
            'service' => $service,
            'label' => $this->gate->label($service),
            'state' => $state,
            'copy' => (array) config('features.copy', []),
            'availableNow' => $this->gate->availableNowRoute(),
            /*
             * A refused request is not a page anyone should find by searching, and a
             * coming-soon page is a placeholder rather than content. Both say so.
             */
            'noindex' => true,
        ], $state === ServiceGate::COMING_SOON ? 200 : 503);
    }
}

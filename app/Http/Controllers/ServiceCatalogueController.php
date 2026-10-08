<?php

namespace App\Http\Controllers;

use App\Catalogue\ServiceCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The service catalogue, for the storefront.
 *
 * §51.42 requires every dynamic value a customer sees — available services, service
 * categories, products, availability, and the status vocabulary — to come from the
 * backend rather than a template. This is the endpoint that supplies them.
 *
 * ## What it will not return
 *
 * **No prices.** A price is produced by the pricing engine for a specific product,
 * quantity and moment, and is quoted again at checkout. Putting a price in a
 * browsing payload creates a second source of truth that goes stale — and a stale
 * price shown to a customer is a price they believe they agreed to (§51.38).
 *
 * **No providers, costs or margins** (§51.36, §51.37). The payload has no field for
 * them, so no future template can render one by accident.
 *
 * ## Two shapes, one implementation
 *
 * The JSON form exists for a frontend that renders client-side; the Blade form is the
 * server-rendered storefront. Both read the same `payload()`, so a service cannot
 * appear on the dashboard and be missing from the API — they cannot disagree, because
 * there is only one answer.
 */
class ServiceCatalogueController extends Controller
{
    public function __construct(
        private readonly ServiceCatalogue $catalogue,
    ) {
    }

    /**
     * The catalogue as JSON.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->catalogue->payload())
            /*
             * Private, because the payload is assembled for an authenticated customer,
             * and short-lived, because availability changes the moment a provider is
             * withdrawn. A cached "available" is how a customer reaches a checkout
             * that then refuses them.
             */
            ->header('Cache-Control', 'private, max-age=60');
    }

    /**
     * The catalogue as a browsable page.
     */
    public function show(): View
    {
        return view('services.index', [
            'catalogue' => $this->catalogue->payload(),
        ]);
    }
}

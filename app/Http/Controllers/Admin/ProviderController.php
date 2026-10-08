<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductAlert;
use App\Models\Provider;
use App\Providers\ProviderMonitor;
use App\Providers\ProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Provider health, float and routing, for the Super Admin.
 *
 * ## Why the three live on one screen
 *
 * They are the three answers to one question: *is a customer's order going to be
 * served, and if not, why not?* A provider can be healthy with no float, have float
 * but reject our credential, or be perfectly well and switched off by an operator.
 * Splitting that across three screens means an operator diagnosing a failed order
 * visits three places and reconciles them by hand.
 *
 * ## The routing controls
 *
 * `providers.priority`, `providers.is_active` and `providers.is_primary` are the
 * whole routing configuration — `ProviderRegistry` orders candidates by them. Rather
 * than introduce a second routing table, this screen edits those fields, so there is
 * exactly one source of truth for which provider serves a capability. A second table
 * would inevitably disagree with the first, and the disagreement would show up as an
 * order routed somewhere nobody expected.
 *
 * ## Permissions
 *
 * `manage_providers` is SUPER_ADMIN_ONLY. The middleware is the control; the
 * `abort_unless` in each write action is a second lock, so a route registered without
 * the middleware cannot be written through.
 */
class ProviderController extends Controller
{
    public function __construct(
        private readonly ProviderMonitor $monitor,
        private readonly ProviderRegistry $registry,
    ) {
    }

    /**
     * The overview: every provider's health, float, routing and open alerts.
     */
    public function index()
    {
        return view('admin.providers.index', [
            'providers' => $this->monitor->overview(),
            'alerts' => $this->monitor->openAlerts(50),
            'capabilities' => $this->registry->routableCapabilities(),
            'sandbox' => (bool) config('providers.sandbox', false),
            'thresholds' => [
                'cost_change_bps' => (int) config('providers.alerts.cost_change_bps', 1000),
                'failure_rate_bps' => (int) config('providers.alerts.failure_rate_bps', 1000),
                'failures_before_down' => (int) config('providers.alerts.failures_before_down', 3),
            ],
            'staged' => \App\Models\ProviderCatalogueItem::awaitingMapping()->present()->count(),
        ]);
    }

    /**
     * One provider: its probe history and the cost movements behind its products.
     */
    public function show(Provider $provider)
    {
        $adapter = $this->registry->hasAdapter($provider->driver)
            ? $this->registry->adapterFor($provider)
            : null;

        return view('admin.providers.show', [
            'provider' => $provider,
            'adapter' => $adapter,
            'history' => $this->monitor->history($provider, 100),
            'alerts' => ProductAlert::where('provider_id', $provider->getKey())
                ->alerts()
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'offerings' => $provider->providerProducts()
                ->with('serviceProduct')
                ->orderByDesc('cost_changed_at')
                ->limit(100)
                ->get(),
            'priceHistory' => \App\Models\ProviderPriceSnapshot::query()
                ->whereIn('provider_product_id', $provider->providerProducts()->select('id'))
                ->orderByDesc('recorded_at')
                ->limit(100)
                ->get(),
            'pendingMapping' => \App\Models\ProviderCatalogueItem::where('provider_id', $provider->getKey())
                ->awaitingMapping()
                ->present()
                ->limit(100)
                ->get(),
            'routable' => $adapter !== null && $adapter->isOperational(),
        ]);
    }

    /**
     * Update routing and thresholds for a provider.
     *
     * Deliberately limited to routing and monitoring fields. The driver, the slug and
     * the credential prefix are not editable here: changing a driver re-points every
     * order at a different integration, and changing a credential prefix silently
     * unconfigures a provider. Both are deploys, not clicks.
     */
    public function update(Request $request, Provider $provider)
    {
        $this->assertCanManage();

        $validated = $request->validate([
            'is_active' => 'nullable|boolean',
            'is_primary' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0|max:10000',
            'low_balance_threshold_minor' => 'nullable|integer|min:0',
        ]);

        /*
         * Normalised explicitly, because the `boolean` validation rule accepts `'1'`
         * and `'0'` without casting them and an unchecked checkbox posts `'0'`. A
         * comparison against `true` on the raw value would silently never match — which
         * is exactly how the two-primary conflict below went unchecked.
         */
        $isPrimary = array_key_exists('is_primary', $validated)
            ? filter_var($validated['is_primary'], FILTER_VALIDATE_BOOLEAN)
            : null;

        $isActive = array_key_exists('is_active', $validated)
            ? filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN)
            : null;

        /*
         * Only one provider may be primary for a capability. `is_primary` is a
         * tie-breaker within the priority ordering, and two primaries would make the
         * ordering depend on the database's row order — which is to say, on nothing an
         * operator chose.
         */
        if ($isPrimary === true) {
            $conflicts = Provider::query()
                ->where('is_primary', true)
                ->where('id', '!=', $provider->getKey())
                ->get()
                ->filter(fn (Provider $other) => array_intersect(
                    (array) $other->capabilities,
                    (array) $provider->capabilities,
                ) !== []);

            if ($conflicts->isNotEmpty()) {
                return back()->withErrors([
                    'is_primary' => 'These providers already serve one of the same capabilities as primary: '
                        . $conflicts->pluck('name')->implode(', ')
                        . '. Clear one first — two primaries make the routing order arbitrary.',
                ])->withInput();
            }
        }

        $provider->fill(array_filter([
            'is_active' => $isActive,
            'is_primary' => $isPrimary,
            'priority' => $validated['priority'] ?? null,
            'low_balance_threshold_minor' => array_key_exists('low_balance_threshold_minor', $validated)
                ? (int) $validated['low_balance_threshold_minor']
                : null,
        ], fn ($value) => $value !== null));

        $provider->save();

        $this->registry->flush();

        return redirect()
            ->route('admin.providers.show', $provider)
            ->with('status', "Routing updated for {$provider->name}.");
    }

    /**
     * Probe one provider now and show the result.
     *
     * A manual probe exists because the scheduled one runs every five minutes and an
     * operator who has just topped up a wallet or rotated a key should not have to wait
     * to find out whether it worked.
     */
    public function check(Provider $provider)
    {
        $this->assertCanManage();

        $result = $this->monitor->probe($provider);

        return back()->with(
            $result['available'] ? 'status' : 'error',
            $result['available']
                ? "{$provider->name} responded normally."
                : "{$provider->name} did not respond: " . ($result['message'] ?? 'no detail'),
        );
    }

    public function acknowledgeAlert(ProductAlert $alert)
    {
        $this->assertCanManage();

        $alert->acknowledge((int) Auth::id());

        return back()->with('status', 'Alert acknowledged.');
    }

    /**
     * Resolve an alert.
     *
     * Resolution is what re-arms the monitor: the database enforces one *open* alert
     * per condition, so resolving this one allows the condition to be raised again if
     * it recurs. Acknowledging deliberately does not.
     */
    public function resolveAlert(ProductAlert $alert)
    {
        $this->assertCanManage();

        $alert->resolve();

        return back()->with('status', 'Alert resolved.');
    }

    private function assertCanManage(): void
    {
        abort_unless(Auth::user()?->hasPermission('manage_providers'), 403);
    }
}

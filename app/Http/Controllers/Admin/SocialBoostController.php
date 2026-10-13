<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\ProviderProduct;
use App\Models\ServiceOrder;
use App\Models\ServiceProduct;
use App\Orders\ServiceOrderService;
use App\Providers\ProviderMonitor;
use App\Providers\ProviderRegistry;
use App\Providers\Support\ProviderCredentials;
use App\Smm\SmmCatalogue;
use App\Smm\SmmOrders;
use App\Smm\SmmPricing;
use App\Smm\SmmTargetValidator;
use App\Support\Money;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The Super Admin Social Boost console.
 *
 * ## Permissions, and why these five screens are split the way they are
 *
 * The module reuses the existing commercial permissions rather than inventing a
 * parallel set. Every one of them is already in `Permissions::SUPER_ADMIN_ONLY`
 * or already means exactly this, so a support agent promoted to administrator
 * does not silently acquire the ability to change what a boost costs:
 *
 *   * **overview** — `view_profit`. It shows provider cost, gross profit and
 *     margin, which is precisely what `view_profit` gates everywhere else.
 *   * **catalogue** — `view_pricing` to read (rates are cost), `manage_catalogue`
 *     to publish or pause a service.
 *   * **pricing preview** — `view_pricing` to read a margin, and the save itself
 *     goes through the existing pricing console, so there is no second place a
 *     rule can be written from.
 *   * **orders** — `view_transactions` to read, `manage_transactions` to
 *     reconcile or refund. Refunds go through `ServiceOrderService`, which
 *     delegates to the application's single refund implementation.
 *   * **provider health** — `manage_providers`, the existing gate for anything
 *     that reveals or changes provider configuration.
 *
 * Every write action re-checks its permission in the controller as well as in the
 * route, because a route registered without its middleware would otherwise be a
 * financial endpoint.
 */
class SocialBoostController extends Controller
{
    public function __construct(
        private readonly SmmCatalogue $catalogue,
        private readonly SmmOrders $orders,
        private readonly SmmPricing $pricing,
        private readonly SmmTargetValidator $targets,
        private readonly ServiceOrderService $serviceOrders,
        private readonly ProviderRegistry $registry,
        private readonly ProviderMonitor $monitor,
    ) {
    }

    /* =====================================================================
     | A. Overview
     | =================================================================== */

    public function index(Request $request)
    {
        $this->assertCan('view_social_boost');

        $range = $this->range($request);

        $orders = ServiceOrder::query()
            ->whereHas('transaction', fn ($q) => $q->where('service_type', SmmOrders::SERVICE_TYPE))
            ->whereBetween('created_at', [$range['from'], $range['to']]);

        $totals = $this->totals($range);

        return view('admin.social-boost.index', [
            'range' => $range,
            'totals' => $totals,
            'statusCounts' => $this->statusCounts($range),
            'provider' => $this->nitro(),
            'health' => $this->heartbeat(),
            'catalogue' => $this->catalogueState(),
            'recent' => (clone $orders)->with(['serviceProduct', 'user', 'pricingSnapshot'])
                ->latest('id')->limit(10)->get(),
        ]);
    }

    /* =====================================================================
     | B. Catalogue
     | =================================================================== */

    public function catalogue(Request $request)
    {
        $this->assertCan('view_social_boost');

        $filters = $request->validate([
            'platform' => 'nullable|string|max:60',
            'state' => 'nullable|in:sellable,unavailable,unpriced,paused',
            'q' => 'nullable|string|max:80',
        ]);

        $provider = $this->nitro();

        $offerings = ProviderProduct::query()
            ->when($provider, fn ($q) => $q->where('provider_id', $provider->getKey()))
            ->with(['serviceProduct', 'provider'])
            ->when(filled($filters['platform'] ?? null), fn ($q) => $q->where('platform', $filters['platform']))
            ->when(filled($filters['q'] ?? null), fn ($q) => $q->where(function ($inner) use ($filters) {
                $term = '%' . trim((string) $filters['q']) . '%';
                $inner->where('provider_name', 'like', $term)
                    ->orWhere('provider_product_id', 'like', $term);
            }))
            ->when(($filters['state'] ?? null) === 'sellable', fn ($q) => $q
                ->whereNull('unavailable_at')->whereNotNull('provider_rate_minor'))
            ->when(($filters['state'] ?? null) === 'unavailable', fn ($q) => $q->whereNotNull('unavailable_at'))
            ->when(($filters['state'] ?? null) === 'unpriced', fn ($q) => $q->whereNull('provider_rate_minor'))
            ->when(($filters['state'] ?? null) === 'paused', fn ($q) => $q->whereHas(
                'serviceProduct',
                fn ($inner) => $inner->where('status', ServiceProduct::STATUS_PAUSED)
            ))
            ->orderBy('platform')
            ->orderBy('provider_name')
            ->paginate(50)
            ->withQueryString();

        /*
         * The markup each offering currently earns, computed through the same
         * `SmmPricing` the purchase uses. A figure derived any other way would be
         * a second opinion about the margin an operator is looking at.
         */
        $markups = $offerings->getCollection()->mapWithKeys(function (ProviderProduct $offering) {
            return [$offering->getKey() => $this->markupVerdict($offering)];
        });

        return view('admin.social-boost.catalogue', [
            'offerings' => $offerings,
            'markups' => $markups,
            'filters' => $filters,
            'platforms' => $this->platformFacets($provider),
            'provider' => $provider,
            'minimumMarkupBps' => $this->pricing->minimumMarkupBps(),
            'lastSyncedAt' => $provider
                ? ProviderProduct::where('provider_id', $provider->getKey())->max('cost_synced_at')
                : null,
        ]);
    }

    /**
     * Publish, pause or flag a provider service.
     *
     * Only the *ReUp product's* status is changed, never the provider's offering.
     * Pausing is what an operator does when a service is mispriced or being
     * abused; the offering row is the synchronisation's to maintain, and letting
     * an operator edit it would mean the next sync silently reverting their
     * decision — or, worse, persisting a rate that the provider never quoted.
     */
    public function updateService(Request $request, ProviderProduct $offering)
    {
        $this->assertCan('manage_catalogue');

        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', [
                ServiceProduct::STATUS_ACTIVE,
                ServiceProduct::STATUS_PAUSED,
                ServiceProduct::STATUS_REVIEW,
                ServiceProduct::STATUS_DISCONTINUED,
            ]),
            'reason' => 'nullable|string|max:300',
        ]);

        $product = $offering->serviceProduct;

        if (! $product) {
            return back()->with('error', 'That provider service is not mapped to a ReUp product yet.');
        }

        /*
         * An unmapped or unpriced service cannot be published. Publishing it would
         * put it on the storefront with no price, and the customer would hit the
         * refusal at checkout instead — which is the dead end the catalogue's
         * "only sellable services are listed" rule exists to prevent.
         */
        if ($validated['status'] === ServiceProduct::STATUS_ACTIVE && $this->markupVerdict($offering)['sellable'] !== true) {
            return back()->with(
                'error',
                'That service cannot be published: it has no usable provider rate, or its markup is below the '
                . $this->percent($this->pricing->minimumMarkupBps()) . ' minimum. Configure a pricing rule for it first.'
            );
        }

        $before = $product->status;

        $product->forceFill(['status' => $validated['status']])->save();

        \App\Models\AdminLog::log(Auth::id(), 'smm_service_status_changed', [
            'service_product_id' => $product->getKey(),
            'product_key' => $product->product_key,
            'provider_product_id' => $offering->getKey(),
            'provider_service_id' => $offering->provider_product_id,
            'before' => $before,
            'after' => $validated['status'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return back()->with('status', $product->name . ' is now ' . strtolower($validated['status']) . '.');
    }

    /* =====================================================================
     | C. Pricing preview
     | =================================================================== */

    /**
     * What a service costs, what it sells for, and what the floor would require.
     *
     * Read-only, and computed by `SmmPricing` — the same object the purchase uses.
     * It deliberately cannot write a rule: the pricing console is the one place a
     * rule is created, and a second write path would be a second place for the
     * mandatory markup floor to be bypassed.
     */
    public function pricingPreview(Request $request, ProviderProduct $offering)
    {
        $this->assertCan('view_social_boost');

        $quantity = (int) $request->input('quantity', $offering->min_quantity ?: 1000);

        $priced = $this->pricing->quote($offering, max(1, $quantity), $this->catalogue->pricingContext($offering));

        $cost = $priced['cost'];
        $quote = $priced['quote'];
        $minimumBps = $this->pricing->minimumMarkupBps();

        return response()->json([
            'service' => $offering->provider_name,
            'provider_service_id' => $offering->provider_product_id,
            'quantity' => max(1, $quantity),
            'rate' => $offering->provider_rate_minor === null
                ? null
                : Money::fromMinor((int) $offering->provider_rate_minor)->toDecimalString(),
            'rate_divisor' => $offering->rate_divisor,
            'provider_cost' => $cost ? Money::fromMinor($cost->costMinor)->toDecimalString() : null,
            'provider_cost_formatted' => $cost ? $cost->money()->format() : null,
            'cost_verified_at' => $cost?->verifiedAt,
            'sellable' => $priced['ok'],
            'refusal' => $priced['ok'] ? null : ($priced['message'] ?? null),
            'price' => $quote ? Money::fromMinor($quote->customerPriceMinor)->toDecimalString() : null,
            'price_formatted' => $quote ? $quote->price()->format() : null,
            'markup_bps' => $priced['effective_markup_bps'],
            'markup_label' => $priced['effective_markup_bps'] === null
                ? null
                : $this->percent($priced['effective_markup_bps']),
            /*
             * Markup and margin are reported side by side and never converted into
             * one another. A 10% markup on ₦1,000 earns ₦100, which is a 9.09%
             * margin — quoting either as the other understates or overstates the
             * business by design.
             */
            'margin_bps' => $quote?->profitMarginBps,
            'margin_label' => $quote?->grossMarginLabel(),
            'gross_profit' => $quote ? Money::fromMinor($quote->grossProfitMinor)->toDecimalString() : null,
            'gross_profit_formatted' => $quote ? $quote->grossProfit()->format() : null,
            'minimum_markup_bps' => $minimumBps,
            'minimum_markup_label' => $this->percent($minimumBps),
            'minimum_price_formatted' => $cost
                ? $this->pricing->minimumPriceFor($cost->costMinor)->format()
                : null,
            'shortfall_formatted' => $quote && $this->pricing->shortfallMinor($quote) > 0
                ? Money::fromMinor($this->pricing->shortfallMinor($quote))->format()
                : null,
            'rule_id' => $quote?->pricingRuleId,
            'rule_name' => $quote?->pricingRuleName,
            'loss' => $quote?->isLoss() ?? false,
        ]);
    }

    /* =====================================================================
     | D. Orders
     | =================================================================== */

    public function orders(Request $request)
    {
        $this->assertCan('view_social_boost');

        $filters = $request->validate([
            'q' => 'nullable|string|max:80',
            'status' => 'nullable|string|max:20',
            'platform' => 'nullable|string|max:60',
        ]);

        $orders = ServiceOrder::query()
            ->whereHas('transaction', fn ($q) => $q->where('service_type', SmmOrders::SERVICE_TYPE))
            ->with(['serviceProduct', 'user', 'pricingSnapshot', 'providerProduct'])
            ->when(filled($filters['q'] ?? null), function ($q) use ($filters) {
                $term = '%' . trim((string) $filters['q']) . '%';

                $q->where(function ($inner) use ($term) {
                    $inner->where('uuid', 'like', $term)
                        ->orWhere('provider_reference', 'like', $term)
                        ->orWhere('recipient', 'like', $term)
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $term)->orWhere('name', 'like', $term))
                    ->orWhereHas('transaction', fn ($t) => $t->where('reference', 'like', $term));
                });
            })
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['platform'] ?? null), fn ($q) => $q->whereHas(
                'providerProduct',
                fn ($inner) => $inner->where('platform', $filters['platform'])
            ))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.social-boost.orders', [
            'orders' => $orders,
            'filters' => $filters,
            /*
             * The canonical statuses, in lifecycle order rather than alphabetical,
             * so the filter reads the way an operator thinks about an order.
             */
            'allStatuses' => [
                ServiceOrder::STATUS_UNKNOWN,
                ServiceOrder::STATUS_PENDING,
                ServiceOrder::STATUS_PROCESSING,
                ServiceOrder::STATUS_SUCCESS,
                ServiceOrder::STATUS_PARTIAL,
                ServiceOrder::STATUS_RETRYABLE,
                ServiceOrder::STATUS_FAILED,
                ServiceOrder::STATUS_CANCELLED,
                ServiceOrder::STATUS_REFUNDED,
            ],
        ]);
    }

    public function showOrder(string $reference)
    {
        $this->assertCan('view_social_boost');

        $order = ServiceOrder::with([
            'serviceProduct', 'user', 'pricingSnapshot', 'providerProduct', 'provider',
            'providerTransactions',
        ])
            ->where(fn ($q) => $q->where('uuid', $reference)->orWhere('id', $reference))
            ->firstOrFail();

        return view('admin.social-boost.order', [
            'order' => $order,
            'canRefill' => $this->orders->canRefill($order),
            'canCancel' => $this->orders->canCancel($order),
            'canRefund' => $order->outcomeIsKnown()
                && ! in_array($order->status, [ServiceOrder::STATUS_SUCCESS, ServiceOrder::STATUS_REFUNDED, ServiceOrder::STATUS_PARTIAL], true),
        ]);
    }

    /**
     * Reconcile an order on demand.
     *
     * The safe retry the brief asks for, made reachable: this asks the provider
     * what happened. It never re-submits — `SmmOrders::reconcileAndSettle()`
     * queries, and the only path that sends an order again is
     * `ServiceOrderService::retrySafely()`, which refuses anything whose outcome
     * is not known to be a non-fulfilment.
     */
    public function reconcileOrder(string $reference)
    {
        $this->assertCan('manage_transactions');

        $order = ServiceOrder::where('uuid', $reference)->firstOrFail();

        try {
            $result = $this->orders->reconcileAndSettle($order);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'That order could not be reconciled: ' . $e->getMessage());
        }

        \App\Models\AdminLog::log(Auth::id(), 'smm_order_reconciled', [
            'order_id' => $order->getKey(),
            'reference' => $order->uuid,
            'provider_reference' => $order->provider_reference,
            'status' => $order->fresh()->status,
            'provider_status' => $order->fresh()->provider_status,
        ]);

        return back()->with('status', 'Order checked. It is now ' . strtolower($order->fresh()->status) . '.');
    }

    /**
     * Refund an order the provider confirmed it did not fulfil.
     *
     * Delegates to `ServiceOrderService::refundUnfulfilled()`, which refuses an
     * unresolved outcome and a partially delivered order and delegates the money
     * movement to the application's single refund implementation. There is no
     * second refund path here, and an operator cannot use this screen to credit a
     * customer for something that may have been delivered.
     */
    public function refundOrder(Request $request, string $reference)
    {
        $this->assertCan('manage_transactions');

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:300',
        ]);

        $order = ServiceOrder::where('uuid', $reference)->firstOrFail();

        $refunded = $this->serviceOrders->refundUnfulfilled(
            $order,
            $validated['reason'],
            (int) Auth::id(),
        );

        if (! $refunded) {
            return back()->with(
                'error',
                'That order was not refunded. Only an order whose outcome is known and was not delivered can be '
                . 'refunded, and each order can be refunded once.'
            );
        }

        return back()->with('status', 'Refunded ' . $order->fresh()->money()->format() . ' to the customer.');
    }

    /* =====================================================================
     | E. Provider health
     | =================================================================== */

    public function provider()
    {
        $this->assertCan('view_social_boost');

        $provider = $this->nitro();

        return view('admin.social-boost.provider', [
            'provider' => $provider,
            'health' => $this->heartbeat(),
            'catalogue' => $this->catalogueState(),
            /*
             * The adapter is reported for its *declared* state — its label and
             * whether it considers itself operational — and is never called.
             * A page load must not spend a provider request, and a balance read
             * in a view would do exactly that on every refresh. The balance below
             * comes from the health probe's last recorded result instead.
             */
            'adapter' => $provider && $this->registry->hasAdapter($provider->driver)
                ? $this->registry->adapterFor($provider)
                : null,
            'configured' => $provider ? ProviderCredentials::isConfigured($provider) : false,
            'envPrefix' => $provider->credential_env_prefix ?? 'NITRO',
            'recentTransactions' => $provider
                ? \App\Models\ProviderTransaction::where('provider_id', $provider->getKey())
                    ->with('serviceOrder')
                    ->latest('id')->limit(30)->get()
                : collect(),
            'rateLimitPerMinute' => (int) config('providers.nitro.rate_limit_per_minute', 300),
        ]);
    }

    /* =====================================================================
     | Reading
     | =================================================================== */

    /**
     * The figures the overview reports, all from `pricing_snapshots`.
     *
     * Revenue is the customer price, cost is the provider cost, and profit is the
     * difference — read from the immutable snapshot the purchase wrote, never
     * recomputed from today's rates. A dashboard that recomputed would restate
     * last month's margin every time a provider changed a rate.
     *
     * @return array<string,mixed>
     */
    private function totals(array $range): array
    {
        $rows = DB::table('service_orders')
            ->join('transactions', 'transactions.id', '=', 'service_orders.transaction_id')
            ->join('pricing_snapshots', 'pricing_snapshots.service_order_id', '=', 'service_orders.id')
            ->where('transactions.service_type', SmmOrders::SERVICE_TYPE)
            ->whereBetween('service_orders.created_at', [$range['from'], $range['to']])
            ->where('service_orders.status', ServiceOrder::STATUS_SUCCESS)
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(pricing_snapshots.customer_price_minor) as revenue_minor')
            ->selectRaw('SUM(pricing_snapshots.base_cost_minor) as cost_minor')
            ->selectRaw('SUM(pricing_snapshots.gross_profit_minor) as profit_minor')
            ->first();

        $revenue = (int) ($rows->revenue_minor ?? 0);
        $cost = (int) ($rows->cost_minor ?? 0);
        $profit = (int) ($rows->profit_minor ?? 0);

        return [
            'orders' => (int) ($rows->orders ?? 0),
            'revenue_minor' => $revenue,
            'cost_minor' => $cost,
            'profit_minor' => $profit,
            'revenue' => Money::fromMinor($revenue)->format(),
            'cost' => Money::fromMinor($cost)->format(),
            'profit' => Money::fromMinor($profit)->format(),
            /*
             * Margin from the final figures, in basis points, so the overview and
             * a snapshot row agree. Margin is never derived from the markup.
             */
            'margin_bps' => $revenue > 0 ? app(\App\Pricing\PricingEngine::class)->marginBps($profit, $revenue) : 0,
        ];
    }

    /** @return array<string,int> */
    private function statusCounts(array $range): array
    {
        $counts = ServiceOrder::query()
            ->whereHas('transaction', fn ($q) => $q->where('service_type', SmmOrders::SERVICE_TYPE))
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        foreach ([
            ServiceOrder::STATUS_PENDING,
            ServiceOrder::STATUS_PROCESSING,
            ServiceOrder::STATUS_SUCCESS,
            ServiceOrder::STATUS_PARTIAL,
            ServiceOrder::STATUS_FAILED,
            ServiceOrder::STATUS_CANCELLED,
            ServiceOrder::STATUS_REFUNDED,
            ServiceOrder::STATUS_RETRYABLE,
            ServiceOrder::STATUS_UNKNOWN,
        ] as $status) {
            $counts[$status] = (int) ($counts[$status] ?? 0);
        }

        $counts['uncertain'] = $counts[ServiceOrder::STATUS_UNKNOWN];
        $counts['open'] = $counts[ServiceOrder::STATUS_PENDING]
            + $counts[ServiceOrder::STATUS_PROCESSING]
            + $counts[ServiceOrder::STATUS_UNKNOWN];

        return $counts;
    }

    /** @return array<string,mixed> */
    private function heartbeat(): array
    {
        $provider = $this->nitro();

        if (! $provider) {
            return [
                'status' => 'unconfigured',
                'label' => 'Not set up',
                'checked_at' => null,
                'balance' => null,
                'message' => 'No Nitro NG provider row exists yet.',
                'last_catalogue_sync' => null,
            ];
        }

        $latest = \App\Models\ProviderHealthCheck::where('provider_id', $provider->getKey())
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();

        return [
            'status' => $provider->health_status,
            'label' => ucfirst(str_replace('_', ' ', (string) $provider->health_status)),
            'checked_at' => $provider->health_checked_at,
            'balance' => $latest?->balance_minor === null
                ? null
                : Money::fromMinor((int) $latest->balance_minor)->format(),
            'balance_minor' => $latest?->balance_minor === null ? null : (int) $latest->balance_minor,
            'low_balance' => $provider->hasBalanceThreshold() && $this->monitor->isBelowThreshold($provider, $latest?->balance_minor),
            'message' => $provider->health_message,
            'latency_ms' => $latest?->latency_ms,
            'error_code' => $latest?->error_code,
            'is_active' => (bool) $provider->is_active,
            'priority' => (int) $provider->priority,
        ];
    }

    /** @return array<string,mixed> */
    private function catalogueState(): array
    {
        $provider = $this->nitro();

        if (! $provider) {
            return ['services' => 0, 'sellable' => 0, 'unavailable' => 0, 'unpriced' => 0, 'staged' => 0, 'last_synced_at' => null];
        }

        $offerings = ProviderProduct::where('provider_id', $provider->getKey());

        return [
            'services' => (clone $offerings)->count(),
            'sellable' => (clone $offerings)->whereNull('unavailable_at')->whereNotNull('provider_rate_minor')->count(),
            'unavailable' => (clone $offerings)->whereNotNull('unavailable_at')->count(),
            'unpriced' => (clone $offerings)->whereNull('provider_rate_minor')->count(),
            'staged' => \App\Models\ProviderCatalogueItem::where('provider_id', $provider->getKey())->awaitingMapping()->present()->count(),
            'last_synced_at' => (clone $offerings)->max('cost_synced_at'),
        ];
    }

    /**
     * The current price, cost, markup and margin for one offering.
     *
     * @return array{sellable:bool,price_minor:?int,price:?string,cost_minor:?int,cost:?string,markup_bps:?int,margin_bps:?int,profit:?string,refusal:?string,shortfall:?string}
     */
    private function markupVerdict(ProviderProduct $offering): array
    {
        $quantity = $offering->min_quantity ?: 1;

        $priced = $this->pricing->quote($offering, max(1, $quantity), $this->catalogue->pricingContext($offering));

        $quote = $priced['quote'];
        $cost = $priced['cost'];

        return [
            'sellable' => (bool) $priced['ok'],
            'price_minor' => $priced['ok'] && $quote ? $quote->customerPriceMinor : null,
            'price' => $priced['ok'] && $quote ? $quote->price()->format() : null,
            'cost_minor' => $cost?->costMinor,
            'cost' => $cost ? $cost->money()->format() : null,
            'markup_bps' => $priced['effective_markup_bps'],
            'margin_bps' => $quote?->profitMarginBps,
            'profit' => $quote ? $quote->grossProfit()->format() : null,
            'refusal' => $priced['ok'] ? null : ($priced['message'] ?? null),
            'shortfall' => $quote && $this->pricing->shortfallMinor($quote) > 0
                ? Money::fromMinor($this->pricing->shortfallMinor($quote))->format()
                : null,
            'below_floor' => ! $priced['ok'] && $quote !== null && $quote->isSellable(),
        ];
    }

    /** @return array<int, array{key:string,label:string,count:int}> */
    private function platformFacets(?Provider $provider): array
    {
        if (! $provider) {
            return [];
        }

        $rows = ProviderProduct::where('provider_id', $provider->getKey())
            ->selectRaw('platform, COUNT(*) as total')
            ->groupBy('platform')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        return $rows->map(fn ($row) => [
            'key' => (string) $row->platform,
            'label' => $this->targets->labelFor((string) $row->platform),
            'count' => (int) $row->total,
        ])->all();
    }

    private function nitro(): ?Provider
    {
        return Provider::where('slug', 'nitro')->first();
    }

    /**
     * Resolution of the overview's date range.
     *
     * @return array{days:int,from:\Illuminate\Support\Carbon,to:\Illuminate\Support\Carbon,label:string}
     */
    private function range(Request $request): array
    {
        $days = (int) $request->input('days', 30);
        $days = in_array($days, [1, 7, 30, 90, 365], true) ? $days : 30;

        return [
            'days' => $days,
            'from' => now()->subDays($days)->startOfDay(),
            'to' => now()->endOfDay(),
            'label' => $days === 1 ? 'Today' : 'Last ' . $days . ' days',
        ];
    }

    private function percent(?int $bps): string
    {
        if ($bps === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.') . '%';
    }

    /**
     * A second lock inside the action, so a route registered without its
     * middleware cannot become a financial endpoint.
     */
    private function assertCan(string $permission): void
    {
        abort_unless(
            Auth::user()?->hasPermission($permission) ?? false,
            403,
            'This action requires the ' . (Permissions::LABELS[$permission] ?? $permission) . ' permission.'
        );
    }
}

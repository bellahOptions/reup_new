<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Orders\ProfitRecorder;
use App\Models\Provider;
use App\Models\ServiceCategory;
use App\Models\ServiceOrder;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The Revenue & Profit dashboard.
 *
 * ## Only realized figures
 *
 * Every total comes from `profit_records` filtered to `is_realized = true`, and
 * `ProfitRecorder` is what decides a row is realized — on a finalized, fulfilled
 * order. A PENDING or UNKNOWN order therefore contributes nothing, so the
 * dashboard cannot report unearned revenue as profit.
 *
 * ## Nothing is computed in the browser
 *
 * The figures are aggregated in SQL over the accounting table and formatted on
 * the server. The chart receives numbers to draw, not data to interpret, so a
 * tampered request cannot change what is displayed as earned.
 */
class ProfitController extends Controller
{
    public function __construct(
        private readonly ProfitRecorder $profit,
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'period' => 'nullable|in:today,week,month,quarter,year,custom',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'product_key' => 'nullable|string|max:64',
            'provider_slug' => 'nullable|string|max:64',
            'category_slug' => 'nullable|string|max:64',
        ]);

        /*
         * `today`, `week` and `month` are the three the brief names, and they are
         * always computed so the page can show all three at once rather than one
         * at a time. `custom` and the longer periods are computed on demand.
         */
        $periods = [
            'today' => $this->profit->summary(now()->startOfDay(), now()->endOfDay(), $filters),
            'week' => $this->profit->summary(now()->startOfWeek(), now()->endOfWeek(), $filters),
            'month' => $this->profit->summary(now()->startOfMonth(), now()->endOfMonth(), $filters),
        ];

        $window = $this->resolveWindow($filters);

        $detail = $this->profit->summary($window['from'], $window['to'], $filters);

        return view('admin.profit.index', [
            'periods' => $periods,
            'detail' => $detail,
            'window' => $window,
            'filters' => $filters,
            'byProduct' => $this->profit->breakdown('product_key', $window['from'], $window['to']),
            'byProvider' => $this->profit->breakdown('provider_slug', $window['from'], $window['to']),
            'byCategory' => $this->profit->breakdown('category_slug', $window['from'], $window['to']),
            'daily' => $this->profit->daily($window['from'], $window['to']),
            'refundRate' => $this->profit->refundRate($window['from'], $window['to']),
            'products' => DB::table('service_products')->orderBy('name')->limit(500)->get(['product_key', 'name']),
            'providers' => Provider::orderBy('name')->get(['slug', 'name']),
            'categories' => ServiceCategory::orderBy('name')->get(['slug', 'name']),
            'operations' => $this->operationsSnapshot(),
        ]);
    }

    /**
     * A JSON view of the same figures, for the chart.
     *
     * Returns the numbers already computed and formatted, so the frontend does no
     * arithmetic on money.
     */
    public function data(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'product_key' => 'nullable|string|max:64',
            'provider_slug' => 'nullable|string|max:64',
        ]);

        $from = isset($validated['from']) ? \Illuminate\Support\Carbon::parse($validated['from']) : now()->subDays(30);
        $to = isset($validated['to']) ? \Illuminate\Support\Carbon::parse($validated['to']) : now();

        return response()->json([
            'summary' => $this->profit->summary($from, $to, $validated),
            'daily' => $this->profit->daily($from, $to),
            'by_product' => $this->profit->breakdown('product_key', $from, $to),
            'by_provider' => $this->profit->breakdown('provider_slug', $from, $to),
        ]);
    }

    /**
     * Operational counts that belong beside the profit figures but are not
     * financial: how many orders are unresolved, how many were refunded.
     *
     * Shown because a healthy margin on a dashboard with forty unresolved orders
     * is not a healthy state, and the two facts need to be visible together.
     *
     * @return array<string, int>
     */
    private function operationsSnapshot(): array
    {
        return [
            'orders_today' => ServiceOrder::whereDate('created_at', today())->count(),
            'unresolved' => ServiceOrder::unresolved()->count(),
            'unknown' => ServiceOrder::where('status', ServiceOrder::STATUS_UNKNOWN)->count(),
            'partial' => ServiceOrder::where('status', ServiceOrder::STATUS_PARTIAL)->count(),
            'refunded_today' => ServiceOrder::whereDate('refunded_at', today())->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{from:\Illuminate\Support\Carbon,to:\Illuminate\Support\Carbon,label:string}
     */
    private function resolveWindow(array $filters): array
    {
        $period = $filters['period'] ?? 'month';

        return match ($period) {
            'today' => ['from' => now()->startOfDay(), 'to' => now()->endOfDay(), 'label' => 'Today'],
            'week' => ['from' => now()->startOfWeek(), 'to' => now()->endOfWeek(), 'label' => 'This week'],
            'quarter' => ['from' => now()->startOfQuarter(), 'to' => now()->endOfQuarter(), 'label' => 'This quarter'],
            'year' => ['from' => now()->startOfYear(), 'to' => now()->endOfYear(), 'label' => 'This year'],
            'custom' => [
                'from' => isset($filters['from']) ? \Illuminate\Support\Carbon::parse($filters['from'])->startOfDay() : now()->subDays(30),
                'to' => isset($filters['to']) ? \Illuminate\Support\Carbon::parse($filters['to'])->endOfDay() : now(),
                'label' => 'Custom range',
            ],
            default => ['from' => now()->startOfMonth(), 'to' => now()->endOfMonth(), 'label' => 'This month'],
        };
    }

    /**
     * The margin watchlist: products whose current rule cannot meet its floor.
     *
     * Read live from the pricing engine rather than from a stored flag, so it
     * reflects a provider cost that changed this morning without waiting for a
     * scheduled job to notice.
     */
    public function marginWatchlist()
    {
        $engine = app(\App\Pricing\PricingEngine::class);

        $rows = [];

        $offerings = \App\Models\ProviderProduct::query()
            ->with(['provider', 'serviceProduct.category'])
            ->available()
            ->whereNotNull('provider_cost_minor')
            ->orderByDesc('cost_changed_at')
            ->limit(500)
            ->get();

        foreach ($offerings as $offering) {
            $product = $offering->serviceProduct;

            if (! $product) {
                continue;
            }

            $quote = $engine->quote((int) $offering->provider_cost_minor, 1, [
                'category_id' => $product->category_id,
                'service_product_id' => $product->getKey(),
                'provider_id' => $offering->provider_id,
                'provider_product_id' => $offering->getKey(),
            ]);

            if ($quote->isSellable() && $quote->profitability === 'ok') {
                continue;
            }

            $rows[] = [
                'offering' => $offering,
                'product' => $product,
                'quote' => $quote,
            ];
        }

        return view('admin.profit.watchlist', ['rows' => $rows]);
    }
}

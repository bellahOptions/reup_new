<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingRule;
use App\Models\PricingRuleVersion;
use App\Models\Provider;
use App\Models\ServiceCategory;
use App\Models\ServiceProduct;
use App\Pricing\PricingEngine;
use App\Pricing\PricingRuleService;
use App\Support\Money;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Super Admin pricing console.
 *
 * ## Authorisation
 *
 * Every route is gated by `admin:view_pricing` or `admin:manage_pricing`, and
 * those permissions are in `Permissions::SUPER_ADMIN_ONLY`. The middleware is the
 * control; the `abort_unless` calls in the write actions are a second lock, so a
 * future route registered without the middleware still cannot be used to change a
 * price.
 *
 * ## Read and write are separate permissions
 *
 * Viewing the console exposes provider cost and margin — commercially sensitive
 * figures that a moderator or support agent has no need for. `view_pricing` and
 * `manage_pricing` are therefore distinct, so an operator can let someone audit
 * margins without letting them change them.
 */
class PricingController extends Controller
{
    public function __construct(
        private readonly PricingEngine $engine,
        private readonly PricingRuleService $rules,
    ) {
    }

    /* =====================================================================
     | The dashboard
     | =================================================================== */

    /**
     * The pricing dashboard: every sellable product with its cost, price, markup
     * and margin.
     *
     * The figures are computed live from the active rule rather than read from a
     * denormalised column, because a stored price is a price that can disagree
     * with the rule that is supposed to produce it.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'category' => 'nullable|integer|exists:service_categories,id',
            /* Allowlisted sorting: an unvalidated column is an injection. */
            'sort' => 'nullable|in:name,profit,revenue,status',
            'direction' => 'nullable|in:asc,desc',
            'status' => 'nullable|in:' . implode(',', [
                ServiceProduct::STATUS_DISCOVERED,
                ServiceProduct::STATUS_REVIEW,
                ServiceProduct::STATUS_ACTIVE,
                ServiceProduct::STATUS_PAUSED,
                ServiceProduct::STATUS_OUT_OF_STOCK,
                ServiceProduct::STATUS_UNPROFITABLE,
                ServiceProduct::STATUS_PROVIDER_UNAVAILABLE,
                ServiceProduct::STATUS_DISCONTINUED,
            ]),
        ]);

        $isSuperAdmin = Auth::user()?->isSuperAdmin() ?? false;

        /*
         * Revenue and profit columns are only loaded for a super admin. A holder
         * of `view_pricing` alone (were one ever granted) sees cost and price, not
         * sales performance.
         */
        $showProfit = $isSuperAdmin || (Auth::user()?->hasPermission('view_profit') ?? false);

        $products = ServiceProduct::query()
            ->with(['category', 'providerProducts.provider'])
            ->when(! empty($filters['category']), fn ($q) => $q->where('category_id', $filters['category']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $rows = collect($products->items())->map(function (ServiceProduct $product) use ($showProfit) {
            return $this->priceRow($product, $showProfit);
        });

        [$sort, $direction] = $this->resolveSort($request);
        $rows = $this->sortRows($rows, $sort, $direction);

        return view('admin.pricing.index', [
            'products' => $products,
            'rows' => $rows,
            'categories' => ServiceCategory::orderBy('group')->orderBy('sort_order')->get(),
            'filters' => $filters,
            'showProfit' => $showProfit,
            'summary' => $this->catalogueSummary($rows),
        ]);
    }

    /**
     * Build the display row for one product.
     *
     * Priced from the cheapest available provider offering, which is the same
     * context the order pipeline uses — so what the console shows is what the
     * customer will be charged.
     *
     * @return array<string, mixed>
     */
    private function priceRow(ServiceProduct $product, bool $showProfit): array
    {
        $offering = $product->activeProviderProducts()
            ->whereNotNull('provider_cost_minor')
            ->orderBy('provider_cost_minor')
            ->first();

        if ($offering === null) {
            return [
                'product' => $product,
                'provider' => null,
                'provider_cost_minor' => null,
                'price_minor' => null,
                'quote' => null,
                'rule' => null,
                'status' => $product->status,
                'availability' => $product->availability,
                'problem' => 'No provider offering has a cost recorded. Sync the catalogue.',
                'cost_synced_at' => null,
            ];
        }

        $context = [
            'category_id' => $product->category_id,
            'service_product_id' => $product->getKey(),
            'provider_id' => $offering->provider_id,
            'provider_product_id' => $offering->getKey(),
        ];

        $quote = $this->engine->quote((int) $offering->provider_cost_minor, 1, $context);

        return [
            'product' => $product,
            'provider' => $offering->provider,
            'provider_offering' => $offering,
            'provider_cost_minor' => $quote->providerCostMinor,
            'price_minor' => $quote->isSellable() ? $quote->customerPriceMinor : null,
            'quote' => $quote,
            'rule' => $quote->pricingRuleId ? PricingRule::find($quote->pricingRuleId) : null,
            'status' => $product->status,
            'availability' => $product->availability,
            'problem' => $quote->isSellable() ? null : $quote->refusalReason,
            'cost_synced_at' => $offering->cost_synced_at,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function catalogueSummary($rows): array
    {
        $priced = $rows->filter(fn ($row) => $row['price_minor'] !== null);
        $unpriced = $rows->filter(fn ($row) => $row['price_minor'] === null);

        return [
            'total' => $rows->count(),
            'priced' => $priced->count(),
            'unpriced' => $unpriced->count(),
            'unprofitable' => $rows->filter(fn ($row) => ($row['quote'] ?? null) !== null && $row['quote']->isLoss())->count(),
            'warning' => $rows->filter(fn ($row) => ($row['quote']->profitability ?? '') === 'warning')->count(),
        ];
    }

    /**
     * Allowlisted sorting, applied to the already-materialised page.
     *
     * The rows are computed rather than stored, so they cannot be sorted in SQL.
     * Sorting the page rather than the result set is a deliberate, stated
     * limitation: computing a quote for every product in the catalogue to sort it
     * would be a per-request cost that grows with the catalogue, and the console
     * is a 50-row view.
     *
     * @return array{0:string,1:string}
     */
    private function resolveSort(Request $request): array
    {
        $allowed = ['name', 'profit', 'revenue', 'status'];
        $sort = (string) $request->query('sort', 'name');

        if (! in_array($sort, $allowed, true)) {
            $sort = 'name';
        }

        $direction = strtolower((string) $request->query('direction', 'asc'));

        return [$sort, $direction === 'desc' ? 'desc' : 'asc'];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function sortRows($rows, string $sort, string $direction)
    {
        $sorted = match ($sort) {
            'profit' => $rows->sortBy(fn ($row) => $row['quote']->grossProfitMinor ?? 0),
            'revenue' => $rows->sortBy(fn ($row) => $row['price_minor'] ?? 0),
            'status' => $rows->sortBy(fn ($row) => $row['status']),
            default => $rows->sortBy(fn ($row) => $row['product']->name),
        };

        return $direction === 'desc' ? $sorted->reverse()->values() : $sorted->values();
    }

    /* =====================================================================
     | Rules
     | =================================================================== */

    public function rules(Request $request)
    {
        $request->validate([
            'scope' => 'nullable|in:global,category,provider,product,provider_product',
        ]);

        $rules = PricingRule::query()
            ->with(['category', 'provider', 'serviceProduct', 'providerProduct', 'fallbackRule'])
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->input('scope')))
            ->orderByRaw("FIELD(scope, 'provider_product', 'product', 'provider', 'category', 'global')")
            ->orderBy('priority')
            ->paginate(50)
            ->withQueryString();

        return view('admin.pricing.rules', [
            'rules' => $rules,
            'scope' => $request->input('scope'),
        ]);
    }

    public function create()
    {
        return view('admin.pricing.rule-form', $this->ruleFormData(new PricingRule([
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'priority' => 100,
            'is_active' => true,
        ])));
    }

    public function store(Request $request)
    {
        $this->assertCanManage();

        $validated = $this->validateRule($request);

        try {
            $rule = $this->rules->create($validated, Auth::user(), $request->input('reason'));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['rule' => $e->getMessage()]);
        }

        return redirect()->route('admin.pricing.rules')
            ->with('success', 'Pricing rule created.');
    }

    public function edit(PricingRule $rule)
    {
        return view('admin.pricing.rule-form', $this->ruleFormData($rule));
    }

    public function update(Request $request, PricingRule $rule)
    {
        $this->assertCanManage();

        $validated = $this->validateRule($request);

        try {
            $this->rules->update($rule, $validated, Auth::user(), $request->input('reason'));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['rule' => $e->getMessage()]);
        }

        return redirect()->route('admin.pricing.rules')
            ->with('success', 'Pricing rule updated. The previous version is preserved in its history.');
    }

    public function toggle(PricingRule $rule)
    {
        $this->assertCanManage();

        $this->rules->setActive($rule, ! $rule->is_active, Auth::user());

        return back()->with('success', $rule->is_active
            ? 'Pricing rule deactivated.'
            : 'Pricing rule activated.');
    }

    public function destroy(PricingRule $rule)
    {
        $this->assertCanManage();

        try {
            $this->rules->delete($rule, Auth::user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.pricing.rules')->with('success', 'Pricing rule deleted.');
    }

    /**
     * The version history of one rule, rendered as a readable diff.
     */
    public function versions(PricingRule $rule)
    {
        $versions = PricingRuleVersion::with('changedBy')
            ->where('pricing_rule_id', $rule->getKey())
            ->orderByDesc('version')
            ->paginate(30);

        return view('admin.pricing.versions', compact('rule', 'versions'));
    }

    /* =====================================================================
     | Preview
     | =================================================================== */

    /**
     * Show what a proposed change would do, before it is saved.
     *
     * Computed with the same engine that prices real orders, so a preview cannot
     * disagree with the result. The response carries an explicit
     * `requires_confirmation` flag for a significant movement, which the view uses
     * to insist on a second, deliberate action.
     */
    public function preview(Request $request)
    {
        $validated = $request->validate([
            'rule_id' => 'required|integer|exists:pricing_rules,id',
            'sample_cost' => 'required|numeric|min:0.01|max:100000000',
            'quantity' => 'nullable|integer|min:1|max:1000000',
            'changes' => 'required|array',
        ]);

        $rule = PricingRule::findOrFail($validated['rule_id']);

        $changes = $this->normalisePreviewChanges($validated['changes']);

        $preview = $this->rules->preview(
            $rule,
            $changes,
            Money::fromNaira($validated['sample_cost'])->minor(),
            (int) ($validated['quantity'] ?? 1),
        );

        /*
         * A preview that would make a product unsellable is the most important
         * thing the screen can say, so it is surfaced as a flag rather than left
         * for the operator to infer from a "sellable: false" field.
         */
        $preview['would_become_unsellable'] = $preview['current']['sellable'] && ! $preview['proposed']['sellable'];

        return response()->json($preview);
    }

    /**
     * Coerce preview input the same way the rule service does, so a preview and
     * the save that follows it agree.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function normalisePreviewChanges(array $changes): array
    {
        $allowed = [
            'name', 'markup_type', 'markup_percentage_bps', 'markup_fixed_minor',
            'minimum_profit_minor', 'maximum_markup_minor',
            'minimum_selling_price_minor', 'maximum_selling_price_minor',
            'minimum_margin_bps', 'customer_fee_enabled', 'customer_fee_type',
            'customer_fee_minor', 'customer_fee_bps', 'discount_bps',
            'discount_fixed_minor', 'allow_negative_margin', 'rounding_step_minor',
            'rounding_mode', 'on_unprofitable', 'priority', 'is_active',
        ];

        return array_intersect_key($changes, array_flip($allowed));
    }

    /* =====================================================================
     | Bulk
     | =================================================================== */

    public function bulkForm()
    {
        return view('admin.pricing.bulk', [
            'categories' => ServiceCategory::orderBy('group')->orderBy('sort_order')->get(),
            'providers' => Provider::orderBy('name')->get(),
        ]);
    }

    /**
     * Apply one change across every matching rule.
     *
     * Every affected rule is versioned and audited individually — a bulk edit is
     * still N financial decisions, and the history has to show which rule changed
     * to what.
     */
    public function bulkUpdate(Request $request)
    {
        $this->assertCanManage();

        $validated = $request->validate([
            'scope' => 'required|in:category,provider',
            'category_id' => 'required_if:scope,category|nullable|integer|exists:service_categories,id',
            'provider_id' => 'required_if:scope,provider|nullable|integer|exists:providers,id',
            'reason' => 'required|string|min:5|max:500',
            'markup_percentage' => 'nullable|numeric|min:0|max:1000',
            'markup_fixed' => 'nullable|numeric|min:0|max:10000000',
            'rounding_step' => 'nullable|numeric|min:0|max:100000',
        ]);

        $filter = $validated['scope'] === 'category'
            ? ['scope' => PricingRule::SCOPE_CATEGORY, 'category_id' => (int) $validated['category_id']]
            : ['scope' => PricingRule::SCOPE_PROVIDER, 'provider_id' => (int) $validated['provider_id']];

        $changes = array_filter([
            // A percentage typed by a human becomes basis points here, once.
            'markup_percentage_bps' => isset($validated['markup_percentage'])
                ? (int) round($validated['markup_percentage'] * 100)
                : null,
            'markup_fixed_minor' => isset($validated['markup_fixed'])
                ? Money::fromNaira($validated['markup_fixed'])->minor()
                : null,
            'rounding_step_minor' => isset($validated['rounding_step'])
                ? Money::fromNaira($validated['rounding_step'])->minor()
                : null,
        ], fn ($value) => $value !== null);

        if ($changes === []) {
            throw ValidationException::withMessages(['markup_percentage' => 'Enter at least one value to change.']);
        }

        if (isset($validated['markup_fixed']) || isset($validated['markup_percentage'])) {
            // A bulk change that sets both numeric components must say so, or the
            // existing markup_type would silently ignore one of them.
            $changes['markup_type'] = PricingRule::MARKUP_PERCENTAGE_PLUS_FIXED;
        }

        $result = $this->rules->bulkUpdate($changes, $filter, Auth::user(), $validated['reason']);

        return redirect()->route('admin.pricing.rules')->with(
            'success',
            "{$result['updated']} rule(s) updated; {$result['skipped']} already matched."
        );
    }

    /* =====================================================================
     | Internals
     | =================================================================== */

    /**
     * A second authorisation check inside the write actions.
     *
     * The route middleware is the primary control. This exists because a route
     * registered without the middleware — a plausible future mistake — would
     * otherwise be a pricing-change endpoint, and pricing is a financial control.
     */
    private function assertCanManage(): void
    {
        abort_unless(Auth::user()?->hasPermission('manage_pricing'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'scope' => 'required|in:global,category,provider,product,provider_product',
            'category_id' => 'nullable|integer|exists:service_categories,id',
            'provider_id' => 'nullable|integer|exists:providers,id',
            'service_product_id' => 'nullable|integer|exists:service_products,id',
            'provider_product_id' => 'nullable|integer|exists:provider_products,id',

            'markup_type' => 'required|in:percentage,fixed,percentage_plus_fixed,none',
            'markup_percentage' => 'nullable|numeric|min:0|max:1000',
            'markup_fixed' => 'nullable|numeric|min:0|max:100000000',

            'minimum_profit' => 'nullable|numeric|min:0|max:100000000',
            'maximum_markup' => 'nullable|numeric|min:0|max:100000000',
            'minimum_selling_price' => 'nullable|numeric|min:0|max:100000000',
            'maximum_selling_price' => 'nullable|numeric|min:0|max:100000000',
            'minimum_margin' => 'nullable|numeric|min:0|max:100',

            'customer_fee_enabled' => 'nullable|boolean',
            'customer_fee_type' => 'required_if:customer_fee_enabled,1|nullable|in:fixed,percentage',
            'customer_fee_amount' => 'nullable|numeric|min:0|max:100000000',
            'customer_fee_percentage' => 'nullable|numeric|min:0|max:100',

            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0|max:100000000',
            'promotion_starts_at' => 'nullable|date',
            'promotion_ends_at' => 'nullable|date|after_or_equal:promotion_starts_at',

            'allow_negative_margin' => 'nullable|boolean',
            'rounding_step' => 'nullable|numeric|min:0|max:100000',
            'rounding_mode' => 'required|in:nearest,up,down',
            'on_unprofitable' => 'required|in:unavailable,warning,fallback,require_approval',
            'fallback_rule_id' => 'nullable|integer|exists:pricing_rules,id',
            'priority' => 'nullable|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        /*
         * Human-facing amounts and percentages are converted to the integer
         * representation the columns use, here at the boundary, so the service
         * only ever receives kobo and basis points.
         */
        return [
            'name' => $validated['name'],
            'scope' => $validated['scope'],
            'category_id' => $validated['category_id'] ?? null,
            'provider_id' => $validated['provider_id'] ?? null,
            'service_product_id' => $validated['service_product_id'] ?? null,
            'provider_product_id' => $validated['provider_product_id'] ?? null,
            'markup_type' => $validated['markup_type'],
            'markup_percentage_bps' => (int) round(($validated['markup_percentage'] ?? 0) * 100),
            'markup_fixed_minor' => Money::fromNaira($validated['markup_fixed'] ?? 0)->minor(),
            'minimum_profit_minor' => Money::fromNaira($validated['minimum_profit'] ?? 0)->minor(),
            'maximum_markup_minor' => isset($validated['maximum_markup'])
                ? Money::fromNaira($validated['maximum_markup'])->minor()
                : null,
            'minimum_selling_price_minor' => isset($validated['minimum_selling_price'])
                ? Money::fromNaira($validated['minimum_selling_price'])->minor()
                : null,
            'maximum_selling_price_minor' => isset($validated['maximum_selling_price'])
                ? Money::fromNaira($validated['maximum_selling_price'])->minor()
                : null,
            'minimum_margin_bps' => (int) round(($validated['minimum_margin'] ?? 0) * 100),
            'customer_fee_enabled' => $request->boolean('customer_fee_enabled'),
            'customer_fee_type' => $validated['customer_fee_type'] ?? 'fixed',
            'customer_fee_minor' => Money::fromNaira($validated['customer_fee_amount'] ?? 0)->minor(),
            'customer_fee_bps' => (int) round(($validated['customer_fee_percentage'] ?? 0) * 100),
            'discount_bps' => (int) round(($validated['discount_percentage'] ?? 0) * 100),
            'discount_fixed_minor' => Money::fromNaira($validated['discount_amount'] ?? 0)->minor(),
            'promotion_starts_at' => $validated['promotion_starts_at'] ?? null,
            'promotion_ends_at' => $validated['promotion_ends_at'] ?? null,
            'allow_negative_margin' => $request->boolean('allow_negative_margin'),
            'rounding_step_minor' => Money::fromNaira($validated['rounding_step'] ?? 0)->minor(),
            'rounding_mode' => $validated['rounding_mode'],
            'on_unprofitable' => $validated['on_unprofitable'],
            'fallback_rule_id' => $validated['fallback_rule_id'] ?? null,
            'priority' => (int) ($validated['priority'] ?? 100),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleFormData(PricingRule $rule): array
    {
        return [
            'rule' => $rule,
            'categories' => ServiceCategory::orderBy('group')->orderBy('sort_order')->get(),
            'providers' => Provider::orderBy('name')->get(),
            'products' => ServiceProduct::orderBy('name')->limit(500)->get(),
            'fallbackCandidates' => PricingRule::where('id', '!=', $rule->getKey() ?? 0)
                ->orderBy('name')
                ->get(),
            'scopes' => [
                PricingRule::SCOPE_GLOBAL => 'All services (global default)',
                PricingRule::SCOPE_CATEGORY => 'Service category',
                PricingRule::SCOPE_PROVIDER => 'Provider',
                PricingRule::SCOPE_PRODUCT => 'Product',
                PricingRule::SCOPE_PROVIDER_PRODUCT => 'Individual provider product',
            ],
        ];
    }
}

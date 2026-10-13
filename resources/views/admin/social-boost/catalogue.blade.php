@extends('admin.layouts.app')
@section('title', 'Social Boost catalogue')
@section('page-title', 'Social Boost catalogue')
@section('page-description', 'Provider rates, service limits and the markup each service earns.')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Admin — Social Boost catalogue
    |--------------------------------------------------------------------------
    | Every rate and markup here is read through `SmmPricing`, the same object the
    | purchase uses — so the margin an operator reads is the margin the platform
    | would earn if that service were bought right now. A figure derived any other
    | way would be a second opinion about the business.
    |
    | The provider's raw service id is shown, deliberately: this is the screen an
    | operator uses to reconcile an order against Nitro's own dashboard, and
    | without the id that is impossible. It is shown *here* and nowhere a customer
    | can see.
    */
    $floor = $minimumMarkupBps;
@endphp

<div class="space-y-6" x-data="smmPreview()">

    {{-- ===================== The floor ===================== --}}
    <div class="card border-brand-200">
        <div class="card-body flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold">Mandatory minimum markup: {{ number_format($floor / 100, 2) }}%</h2>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Every Social Boost order is sold at the verified provider cost plus at least this markup. It is
                    enforced when a pricing rule is saved <em>and</em> again immediately before the wallet is debited,
                    so no rule, override or scope can sell a boost below it.
                    <strong>Markup is not margin</strong> — a {{ number_format($floor / 100, 2) }}% markup on ₦1,000
                    earns ₦{{ number_format(1000 * $floor / 10000, 2) }}, which is a
                    {{ number_format(($floor / (10000 + $floor)) * 100, 2) }}% gross margin.
                </p>
            </div>
            <a href="{{ route('admin.pricing.rules') }}" class="btn btn-outline btn-sm shrink-0">
                Pricing rules
            </a>
        </div>
    </div>

    {{-- ===================== Filters ===================== --}}
    <form method="GET" action="{{ route('admin.social-boost.catalogue', [], false) }}" class="card p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <input type="search" id="q" name="q" class="input mt-1.5" value="{{ $filters['q'] ?? '' }}"
                       placeholder="Service name or provider id">
            </div>

            <div>
                <label for="platform" class="label">Platform</label>
                <select id="platform" name="platform" class="select mt-1.5">
                    <option value="">All platforms</option>
                    @foreach($platforms as $platform)
                        <option value="{{ $platform['key'] }}" @selected(($filters['platform'] ?? null) === $platform['key'])>
                            {{ $platform['label'] }} ({{ $platform['count'] }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="state" class="label">State</label>
                <select id="state" name="state" class="select mt-1.5">
                    <option value="">Any state</option>
                    @foreach([
                        'sellable' => 'Sellable',
                        'unpriced' => 'No published rate',
                        'unavailable' => 'Gone from the provider',
                        'paused' => 'Paused by us',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['state'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            @if(array_filter($filters ?? []))
                <a href="{{ route('admin.social-boost.catalogue', [], false) }}" class="btn btn-outline btn-sm">Clear</a>
            @endif
            <span class="text-xs text-muted-foreground">
                Last synchronised:
                {{ $lastSyncedAt ? \Illuminate\Support\Carbon::parse($lastSyncedAt)->diffForHumans() : 'never' }}
            </span>
        </div>
    </form>

    {{-- ===================== Services ===================== --}}
    @if($offerings->isEmpty())
        <div class="card">
            <div class="card-body">
                <p class="text-sm text-muted-foreground">
                    No services match. If the catalogue has never been synchronised, run
                    <code>php artisan smm:sync-catalogue</code>.
                </p>
            </div>
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table">
                    <caption class="sr-only">Synchronised Nitro services with their rates and margins</caption>
                    <thead>
                        <tr>
                            <th scope="col">Service</th>
                            <th scope="col">Provider id</th>
                            <th scope="col" class="text-right">Rate / 1,000</th>
                            <th scope="col" class="text-right">Limit</th>
                            <th scope="col" class="text-right">Cost</th>
                            <th scope="col" class="text-right">Price</th>
                            <th scope="col" class="text-right">Markup</th>
                            <th scope="col" class="text-right">Margin</th>
                            <th scope="col">State</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($offerings as $offering)
                            @php
                                $verdict = $markups[$offering->getKey()] ?? [];
                                $product = $offering->serviceProduct;
                                $unavailable = $offering->unavailable_at !== null;
                                $unpriced = $offering->provider_rate_minor === null;
                                $paused = $product && $product->status !== \App\Models\ServiceProduct::STATUS_ACTIVE;
                            @endphp
                            <tr>
                                <td class="max-w-sm">
                                    <span class="block truncate text-sm font-medium" title="{{ $offering->provider_name }}">
                                        {{ $offering->provider_name }}
                                    </span>
                                    <span class="block text-xs text-muted-foreground">
                                        {{ $offering->platform ?: '—' }}
                                        @if($offering->service_type) &middot; {{ $offering->service_type }} @endif
                                        @if($offering->supports_refill) &middot; refill @endif
                                        @if($offering->supports_cancel) &middot; cancel @endif
                                    </span>
                                    @if(! $product)
                                        <span class="badge badge-warning mt-1">Not mapped</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap font-mono text-xs">
                                    <button type="button"
                                            class="link font-mono"
                                            title="Use this id in the pricing preview"
                                            @click="serviceId = '{{ $offering->getKey() }}'; fetchPreview()">
                                        {{ $offering->provider_product_id }}
                                    </button>
                                </td>

                                <td class="whitespace-nowrap text-right text-sm tabular-nums">
                                    {{ $offering->provider_rate_minor === null ? '—' : \App\Support\Money::fromMinor((int) $offering->provider_rate_minor)->format() }}
                                </td>

                                <td class="whitespace-nowrap text-right text-xs text-muted-foreground tabular-nums">
                                    {{ number_format((int) ($offering->min_quantity ?? 1)) }}–{{ $offering->max_quantity ? number_format((int) $offering->max_quantity) : '∞' }}
                                </td>

                                <td class="whitespace-nowrap text-right text-sm tabular-nums">
                                    {{ $verdict['cost'] ?? '—' }}
                                </td>

                                <td class="whitespace-nowrap text-right text-sm font-medium tabular-nums">
                                    {{ $verdict['price'] ?? '—' }}
                                </td>

                                <td class="whitespace-nowrap text-right text-sm tabular-nums {{ ($verdict['markup_bps'] ?? 0) < $floor ? 'text-red-700 font-medium' : '' }}">
                                    {{ $verdict['markup_bps'] === null ? '—' : number_format($verdict['markup_bps'] / 100, 2) . '%' }}
                                </td>

                                <td class="whitespace-nowrap text-right text-sm tabular-nums">
                                    {{ $verdict['margin_bps'] === null ? '—' : number_format($verdict['margin_bps'] / 100, 2) . '%' }}
                                </td>

                                <td class="whitespace-nowrap">
                                    @if($unavailable)
                                        <span class="badge badge-destructive">Gone upstream</span>
                                    @elseif($unpriced)
                                        <span class="badge badge-warning">No rate</span>
                                    @elseif($paused)
                                        <span class="badge badge-neutral">Paused</span>
                                    @elseif(! ($verdict['sellable'] ?? false))
                                        <span class="badge badge-warning">Below floor</span>
                                        @if($verdict['shortfall'] ?? null)
                                            <span class="block text-xs text-muted-foreground">short by {{ $verdict['shortfall'] }}</span>
                                        @endif
                                    @else
                                        <span class="badge badge-success">Sellable</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap text-right">
                                    @if($product)
                                        <form method="POST"
                                              action="{{ route('admin.social-boost.catalogue.update', $offering, false) }}"
                                              class="inline-flex items-center gap-1.5">
                                            @csrf
                                            @method('PUT')
                                            <select name="status" class="select h-8 py-0 text-xs">
                                                @foreach([
                                                    \App\Models\ServiceProduct::STATUS_ACTIVE => 'Active',
                                                    \App\Models\ServiceProduct::STATUS_PAUSED => 'Paused',
                                                    \App\Models\ServiceProduct::STATUS_REVIEW => 'Review',
                                                    \App\Models\ServiceProduct::STATUS_DISCONTINUED => 'Discontinued',
                                                ] as $value => $label)
                                                    <option value="{{ $value }}" @selected($product->status === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-outline btn-sm h-8">Save</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-muted-foreground">Map it first</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($offerings->hasPages())
                <div class="border-t border-border p-4">{{ $offerings->links() }}</div>
            @endif
        </div>
    @endif

    {{-- ===================== Preview ===================== --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Pricing preview</h2>
            <p class="card-description">
                What a service costs, what it sells for, and what the floor would require — computed by the same
                engine the purchase uses. This screen is read-only: a rule is created or changed in the pricing
                console, which is the one place that can write one.
            </p>
        </div>

        <div class="card-body space-y-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label for="previewService" class="label">Provider service id</label>
                    <input type="text" id="previewService" class="input mt-1.5 font-mono" x-model="serviceId"
                           placeholder="3877">
                </div>
                <div>
                    <label for="previewQuantity" class="label">Quantity</label>
                    <input type="text" id="previewQuantity" class="input mt-1.5" inputmode="numeric"
                           x-model="quantity" @input="debounced()">
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                Copy a provider id from the table above. The preview calls the same pricing endpoint the admin
                console uses and reports the cost, price, markup, margin and profit.
            </p>

            <div x-show="loading" x-cloak class="space-y-2" aria-hidden="true">
                <div class="h-4 w-1/3 animate-pulse rounded bg-ink-100"></div>
                <div class="h-4 w-1/2 animate-pulse rounded bg-ink-100"></div>
            </div>

            <template x-if="error">
                <p class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" x-text="error"></p>
            </template>

            <template x-if="result">
                <dl class="grid grid-cols-2 gap-4 rounded-lg border border-border bg-ink-50 p-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-muted-foreground">Provider cost</dt>
                        <dd class="mt-1 font-semibold tabular-nums" x-text="result ? result.provider_cost_formatted : ''"></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Selling price</dt>
                        <dd class="mt-1 font-semibold tabular-nums" x-text="result ? result.price_formatted : ''"></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Markup</dt>
                        <dd class="mt-1 font-semibold tabular-nums" x-text="result ? (result.markup_label || '—') : ''"></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Gross margin</dt>
                        <dd class="mt-1 font-semibold tabular-nums" x-text="result ? (result.margin_label || '—') : ''"></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Gross profit</dt>
                        <dd class="mt-1 font-semibold tabular-nums" x-text="result ? result.gross_profit_formatted : ''"></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Rule</dt>
                        <dd class="mt-1 font-medium" x-text="result ? (result.rule_name || 'none') : ''"></dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-muted-foreground">Floor</dt>
                        <dd class="mt-1 text-xs">
                            <span x-text="result ? result.minimum_markup_label : ''"></span>
                            <span class="text-muted-foreground"> — minimum price </span>
                            <span class="font-medium tabular-nums" x-text="result ? result.minimum_price_formatted : ''"></span>
                        </dd>
                    </div>
                </dl>
            </template>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
/*
 * The pricing preview.
 *
 * It calls `admin.social-boost.pricing.preview`, which prices through
 * `SmmPricing` — the same object the purchase uses. Nothing here calculates a
 * price; it displays what the server returns, so the preview cannot disagree with
 * the charge.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('smmPreview', () => ({
        serviceId: '',
        quantity: '1000',
        loading: false,
        error: '',
        result: null,

        init() {},

        debounced() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.fetchPreview(), 300);
        },

        async fetchPreview() {
            const id = String(this.serviceId || '').trim();
            const quantity = Number(String(this.quantity || '').replace(/[^0-9]/g, ''));

            this.error = '';
            this.result = null;

            if (!id) {
                return;
            }

            if (!quantity || quantity < 1) {
                this.error = 'Enter a quantity of at least 1.';

                return;
            }

            this.loading = true;

            try {
                const url = `/admin/social-boost/pricing/${encodeURIComponent(id)}?quantity=${quantity}`;

                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('The preview responded with ' + response.status);
                }

                this.result = await response.json();
            } catch (e) {
                console.error('Pricing preview failed:', e);
                this.error = 'We could not price that service. Check the provider id and try again.';
            } finally {
                this.loading = false;
            }
        },
    }));
});
</script>
@endpush

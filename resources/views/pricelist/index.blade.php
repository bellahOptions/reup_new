@extends('layouts.app')
@section('title', 'Data plan prices')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Pricelist (public)
    |--------------------------------------------------------------------------
    | Switched from layouts.main to layouts.app to match the other customer
    | pages. layouts.app has no meta/OG block, so the SEO tags this public page
    | used to inherit are pushed back into the shared head stack below.
    |
    | Rows stay server-rendered (SEO + no-JS), filtering is Alpine `x-show` and
    | sorting reorders the existing rows — the same behaviour as before.
    */
    $plans = is_array($data['data_plans'] ?? null) ? $data['data_plans'] : [];
    $totalPlans = $data['total_plans'] ?? count($plans);
    $lastUpdated = !empty($data['last_updated']) ? \Illuminate\Support\Carbon::parse($data['last_updated']) : null;

    $presentNetworks = collect($plans)->pluck('network')->filter()->unique()->values()->all();
    $networks = collect(['MTN', 'AIRTEL', 'GLO', '9MOBILE'])
        ->filter(fn ($network) => in_array($network, $presentNetworks, true))
        ->values()
        ->all();

    foreach ($presentNetworks as $network) {
        if (! in_array($network, $networks, true)) {
            $networks[] = $network;
        }
    }

    // Brand name => locally hosted logo, so the filters carry the same marks as
    // the purchase forms. Derived from config('bills.networks') so the
    // catalogue has a single definition.
    $networkLogos = collect(config('bills.networks', []))
        ->mapWithKeys(fn ($network) => [strtoupper($network['name']) => asset($network['logo'])])
        ->all();
@endphp

@push('head')
    <meta name="description" content="Current ReUp data plan prices for MTN, Airtel, Glo and 9mobile — updated continuously and paid straight from your wallet.">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ReUp">
    <meta property="og:title" content="Data plan prices — ReUp">
    <meta property="og:description" content="Current ReUp data plan prices for every Nigerian network.">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:locale" content="en_NG">
    <meta name="twitter:card" content="summary_large_image">
@endpush

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        {{-- Page heading --}}
        <header class="mb-6 md:mb-8">
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Data plan prices</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                Every plan we sell, at the price your wallet is charged.
                @if($lastUpdated)
                    Updated {{ $lastUpdated->diffForHumans() }}.
                @endif
            </p>
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <span class="badge badge-neutral">{{ number_format($totalPlans) }} plans listed</span>
                @if($lastUpdated)
                    <span class="badge badge-neutral">
                        <x-icon name="clock" class="h-3 w-3" />
                        {{ $lastUpdated->format('j M Y, H:i') }}
                    </span>
                @endif
            </div>
        </header>

        @if(!empty($data['error']))
            <div class="mb-6 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4" role="status">
                <x-icon name="exclamation-triangle" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                <p class="text-sm text-amber-900">{{ $data['error'] }}</p>
            </div>
        @endif

        @if(empty($plans))
            <div class="card">
                <div class="empty-state">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                        <x-icon name="receipt-percent" class="h-5 w-5" />
                    </span>
                    <p class="mt-4 text-sm font-medium">No plans to show right now</p>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                        Live rates could not be loaded. Refresh the list or try again in a few minutes.
                    </p>
                    <a href="{{ route('pricelist.refresh') }}" class="btn btn-outline btn-sm mt-5">
                        <x-icon name="arrow-path" class="h-4 w-4" />
                        Refresh rates
                    </a>
                </div>
            </div>
        @else
            <div class="space-y-6" x-data="priceList()">

                {{-- Toolbar --}}
                <div class="card">
                    <div class="card-content flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div class="flex flex-wrap gap-2" role="group" aria-label="Filter by network">
                            <button type="button"
                                    data-network="all"
                                    class="rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors"
                                    :class="network === $el.dataset.network ? 'border-brand-500 bg-brand-500 text-white' : 'border-border bg-white text-muted-foreground hover:bg-ink-50'"
                                    :aria-pressed="network === $el.dataset.network ? 'true' : 'false'"
                                    @click="network = $el.dataset.network">
                                All networks
                            </button>
                            @foreach($networks as $network)
                                <button type="button"
                                        data-network="{{ $network }}"
                                        class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors"
                                        :class="network === $el.dataset.network ? 'border-brand-500 bg-brand-500 text-white' : 'border-border bg-white text-muted-foreground hover:bg-ink-50'"
                                        :aria-pressed="network === $el.dataset.network ? 'true' : 'false'"
                                        @click="network = $el.dataset.network">
                                    @if(!empty($networkLogos[strtoupper($network)] ?? null))
                                        <img src="{{ $networkLogos[strtoupper($network)] }}"
                                             alt="" class="h-5 w-5 shrink-0 rounded-full object-contain">
                                    @endif
                                    {{ $network }}
                                </button>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-2">
                                <label for="sort" class="stat-label whitespace-nowrap">Sort</label>
                                <select id="sort" class="select w-44" @change="onSortChange($event)">
                                    <option value="default">Network order</option>
                                    <option value="price-asc">Price: low to high</option>
                                    <option value="price-desc">Price: high to low</option>
                                </select>
                            </div>
                            <a href="{{ route('pricelist.refresh') }}" class="btn btn-outline btn-sm shrink-0">
                                <x-icon name="arrow-path" class="h-4 w-4" />
                                Refresh
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Buy a plan straight from the list. POST pricelist.purchase.data
                     takes {plan_code, phone}; the price is resolved server-side. --}}
                @auth
                    <div class="card border-brand-200" x-show="selectedPlan" x-cloak>
                        <form action="{{ route('pricelist.purchase.data') }}" method="POST" @submit="onBuySubmit($event)">
                            @csrf
                            <input type="hidden" name="plan_code" :value="selectedPlan ? selectedPlan.code : ''">

                            <div class="card-content space-y-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="mt-1 truncate text-sm font-semibold" x-text="selectedPlan ? selectedPlan.name : ''"></p>
                                        <p class="mt-0.5 text-xs text-muted-foreground">
                                            <span x-text="selectedPlan ? selectedPlan.network : ''"></span>
                                            &middot;
                                            <span class="tabular-nums" x-text="selectedPlan ? money(selectedPlan.price) : ''"></span>
                                        </p>
                                    </div>
                                    <button type="button" class="btn btn-ghost btn-icon shrink-0" aria-label="Cancel" @click="selectedPlan = null">
                                        <x-icon name="x-mark" class="h-4 w-4" />
                                    </button>
                                </div>

                                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                    <div class="flex-1">
                                        <label for="buy_phone" class="label">Phone number</label>
                                        <input type="tel"
                                               id="buy_phone"
                                               name="phone"
                                               placeholder="08012345678"
                                               class="input mt-1.5"
                                               :value="phone"
                                               @input="onDigits($event, 'phone', 11)"
                                               minlength="11"
                                               maxlength="11"
                                               pattern="0[7-9][0-9]{9}"
                                               inputmode="numeric"
                                               autocomplete="tel"
                                               required>
                                    </div>
                                    <button type="submit" class="btn btn-primary shrink-0">
                                        <x-icon name="wallet" class="h-4 w-4" />
                                        Pay now
                                    </button>
                                </div>

                                <p class="field-error" x-show="buyError" x-cloak x-text="buyError"></p>
                                <p class="text-xs text-muted-foreground">
                                    Paid from your wallet balance. Failed deliveries are refunded automatically.
                                </p>
                            </div>
                        </form>
                    </div>
                @endauth

                {{-- Plans --}}
                <div class="card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table">
                            <caption class="sr-only">Data plan prices by network</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Network</th>
                                    <th scope="col">Plan</th>
                                    <th scope="col">Data</th>
                                    <th scope="col">Validity</th>
                                    <th scope="col">Type</th>
                                    <th scope="col" class="text-right">Price</th>
                                    <th scope="col" class="text-right">Buy</th>
                                </tr>
                            </thead>
                            <tbody x-ref="rows">
                                @foreach($plans as $index => $plan)
                                    <tr data-row
                                        data-index="{{ $index }}"
                                        data-network="{{ $plan['network'] }}"
                                        data-price="{{ $plan['your_price'] }}"
                                        x-show="network === 'all' || $el.dataset.network === network">
                                        <td>
                                            <span class="flex items-center gap-2">
                                                @if(!empty($networkLogos[strtoupper($plan['network'])] ?? null))
                                                    <img src="{{ $networkLogos[strtoupper($plan['network'])] }}"
                                                         alt="" class="h-6 w-6 shrink-0 rounded-full object-contain">
                                                @else
                                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-border bg-surface text-xs font-semibold text-muted-foreground"
                                                          aria-hidden="true">{{ substr($plan['network'], 0, 1) }}</span>
                                                @endif
                                                <span class="text-sm font-medium">{{ $plan['network'] }}</span>
                                            </span>
                                        </td>
                                        <td class="max-w-xs">
                                            <span class="block truncate text-sm" title="{{ $plan['plan_name'] }}">{{ $plan['plan_name'] }}</span>
                                        </td>
                                        <td class="text-sm tabular-nums">{{ $plan['data_volume'] }}</td>
                                        <td class="text-sm text-muted-foreground">{{ $plan['validity'] }}</td>
                                        <td><span class="badge badge-neutral">{{ $plan['plan_type'] }}</span></td>
                                        <td class="text-right text-sm font-semibold tabular-nums">
                                            &#8358;{{ number_format((float) $plan['your_price'], 2) }}
                                        </td>
                                        @auth
                                            <td class="text-right">
                                                <button type="button"
                                                        class="btn btn-outline btn-sm"
                                                        data-plan-code="{{ $plan['plan_code'] ?: $plan['plan_id'] }}"
                                                        data-plan-name="{{ $plan['plan_name'] }}"
                                                        data-plan-network="{{ $plan['network'] }}"
                                                        data-plan-price="{{ $plan['your_price'] }}"
                                                        @click="selectPlan($event.currentTarget)">
                                                    Buy
                                                </button>
                                            </td>
                                        @else
                                            <td class="text-right">
                                                <a href="{{ route('login') }}" class="link text-sm font-medium">Sign in</a>
                                            </td>
                                        @endauth
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    Prices include the ReUp service margin. The amount debited is the price shown for the plan you select.
                </p>
            </div>
        @endif
    </div>
</main>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('priceList', () => ({
        network: 'all',
        sort: 'default',

        // Buy panel. The price shown here is display-only — pricelist.purchase.data
        // resolves the plan and its price on the server.
        selectedPlan: null,
        phone: @json((string) (auth()->user()?->phone ?? '')),
        buyError: '',

        onSortChange(event) {
            this.sort = event.target.value;
            this.applySort();
        },

        money(value) {
            return '\u20A6' + Number(value || 0).toLocaleString('en-NG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        selectPlan(button) {
            this.buyError = '';
            this.selectedPlan = {
                code: button.dataset.planCode,
                name: button.dataset.planName,
                network: button.dataset.planNetwork,
                price: Number(button.dataset.planPrice),
            };
        },

        onDigits(event, field, maxLength) {
            const clean = String(event.target.value || '').replace(/[^0-9]/g, '').slice(0, maxLength);
            if (event.target.value !== clean) {
                event.target.value = clean;
            }
            this[field] = clean;
        },

        onBuySubmit(event) {
            this.buyError = '';

            if (!this.selectedPlan || !this.selectedPlan.code) {
                event.preventDefault();
                this.buyError = 'Choose a plan first.';
                return;
            }

            if (!/^0[7-9][0-9]{9}$/.test(String(this.phone || '').trim())) {
                event.preventDefault();
                this.buyError = 'Enter a valid Nigerian phone number (for example 08012345678).';
            }
        },

        // Rows are rendered by the server; sorting reuses the DOM that is already
        // there instead of shipping the whole price list twice.
        applySort() {
            const tbody = this.$refs.rows;
            if (!tbody) return;

            const rows = Array.from(tbody.querySelectorAll('[data-row]'));

            if (this.sort === 'price-asc') {
                rows.sort((a, b) => Number(a.dataset.price) - Number(b.dataset.price));
            } else if (this.sort === 'price-desc') {
                rows.sort((a, b) => Number(b.dataset.price) - Number(a.dataset.price));
            } else {
                rows.sort((a, b) => Number(a.dataset.index) - Number(b.dataset.index));
            }

            rows.forEach((row) => tbody.appendChild(row));
        },
    }));
});
</script>
@endpush

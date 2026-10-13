@extends('admin.layouts.app')
@section('title', 'Social Boost')
@section('page-title', 'Social Boost')
@section('page-description', 'Order volume, margin and provider health for the SMM module.')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Admin — Social Boost overview
    |--------------------------------------------------------------------------
    | Every figure comes from `pricing_snapshots` — the immutable record written
    | when the order was charged. Nothing is recomputed from today's rates, because
    | a dashboard that recomputed would restate last month's margin every time
    | Nitro changed a rate.
    |
    | Cost and profit are shown only to a holder of `view_profit`, which the route
    | already requires; this is the same gate the margin watchlist uses.
    */
    $profitVisible = auth()->user()?->hasPermission('view_profit') ?? false;
@endphp

<div class="space-y-6">

    {{-- ===================== Range ===================== --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Date range">
            @foreach([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $days => $label)
                <a href="{{ route('admin.social-boost.index', ['days' => $days]) }}"
                   class="rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors
                          {{ $range['days'] === $days ? 'border-brand-500 bg-brand-500 text-primary-foreground' : 'border-border bg-surface text-muted-foreground hover:bg-ink-50' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="flex gap-2">
            <a href="{{ route('admin.social-boost.catalogue') }}" class="btn btn-outline btn-sm">Catalogue</a>
            <a href="{{ route('admin.social-boost.orders') }}" class="btn btn-outline btn-sm">Orders</a>
            <a href="{{ route('admin.social-boost.provider') }}" class="btn btn-outline btn-sm">Provider health</a>
        </div>
    </div>

    {{-- ===================== Headline figures ===================== --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="card p-4 md:p-5">
            <p class="text-xs font-medium text-muted-foreground">Completed orders</p>
            <p class="mt-1.5 text-2xl font-semibold tabular-nums">{{ number_format($totals['orders']) }}</p>
            <p class="mt-1 text-xs text-muted-foreground">{{ $range['label'] }}</p>
        </div>

        <div class="card p-4 md:p-5">
            <p class="text-xs font-medium text-muted-foreground">Customer revenue</p>
            <p class="mt-1.5 text-2xl font-semibold tabular-nums">{{ $totals['revenue'] }}</p>
            <p class="mt-1 text-xs text-muted-foreground">What customers paid</p>
        </div>

        @if($profitVisible)
            <div class="card p-4 md:p-5">
                <p class="text-xs font-medium text-muted-foreground">Provider cost</p>
                <p class="mt-1.5 text-2xl font-semibold tabular-nums">{{ $totals['cost'] }}</p>
                <p class="mt-1 text-xs text-muted-foreground">What Nitro charged us</p>
            </div>

            <div class="card p-4 md:p-5">
                <p class="text-xs font-medium text-muted-foreground">Gross profit</p>
                <p class="mt-1.5 text-2xl font-semibold tabular-nums {{ $totals['profit_minor'] < 0 ? 'text-red-700' : '' }}">
                    {{ $totals['profit'] }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Margin
                    {{-- Margin is from the final figures and is NOT the markup.
                         A 10% markup earns a 9.09% margin, and the two are
                         labelled separately everywhere they appear. --}}
                    {{ number_format($totals['margin_bps'] / 100, 2) }}%
                </p>
            </div>
        @endif
    </div>

    {{-- ===================== Order states ===================== --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Order states</h2>
            <p class="card-description">
                Pending and uncertain orders hold customer money. Uncertain means we do not yet know whether the
                provider fulfilled the order — those are resolved by the reconciliation sweep, never by re-submitting.
            </p>
        </div>
        <div class="card-body">
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-7">
                @foreach([
                    ['key' => \App\Models\ServiceOrder::STATUS_UNKNOWN, 'label' => 'Uncertain', 'tone' => 'text-amber-700'],
                    ['key' => \App\Models\ServiceOrder::STATUS_PENDING, 'label' => 'Pending', 'tone' => ''],
                    ['key' => \App\Models\ServiceOrder::STATUS_PROCESSING, 'label' => 'In progress', 'tone' => ''],
                    ['key' => \App\Models\ServiceOrder::STATUS_SUCCESS, 'label' => 'Completed', 'tone' => 'text-green-700'],
                    ['key' => \App\Models\ServiceOrder::STATUS_PARTIAL, 'label' => 'Partial', 'tone' => 'text-amber-700'],
                    ['key' => \App\Models\ServiceOrder::STATUS_FAILED, 'label' => 'Failed', 'tone' => 'text-red-700'],
                    ['key' => \App\Models\ServiceOrder::STATUS_REFUNDED, 'label' => 'Refunded', 'tone' => ''],
                ] as $state)
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ $state['label'] }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums {{ $state['tone'] }}">
                            <a href="{{ route('admin.social-boost.orders', ['status' => $state['key']]) }}" class="hover:underline">
                                {{ number_format($statusCounts[$state['key']] ?? 0) }}
                            </a>
                        </dd>
                    </div>
                @endforeach
            </dl>

            @if(($statusCounts['uncertain'] ?? 0) > 0)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <p class="text-sm font-medium text-amber-900">
                        {{ number_format($statusCounts['uncertain']) }} order(s) have an unknown provider outcome.
                    </p>
                    <p class="mt-1 text-xs text-amber-800">
                        These hold customer money. <code>php artisan smm:reconcile-orders</code> asks the provider what
                        happened; it never re-submits, because the order may already have been delivered.
                    </p>
                </div>
            @endif
        </div>
    </div>

    {{-- ===================== Provider and catalogue ===================== --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="card">
            <div class="card-header flex items-center justify-between gap-3">
                <h2 class="card-title">Nitro NG</h2>
                <span class="badge {{ in_array($health['status'], ['healthy'], true) ? 'badge-success' : (in_array($health['status'], ['down'], true) ? 'badge-destructive' : 'badge-warning') }}">
                    {{ $health['label'] }}
                </span>
            </div>
            <div class="card-body space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Wallet balance</span>
                    <span class="font-medium tabular-nums {{ $health['low_balance'] ?? false ? 'text-red-700' : '' }}">
                        {{ $health['balance'] ?? '—' }}
                    </span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Last check</span>
                    <span class="font-medium">{{ $health['checked_at']?->diffForHumans() ?? 'never' }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Active</span>
                    <span class="font-medium">{{ ($health['is_active'] ?? false) ? 'yes' : 'no' }}</span>
                </div>

                @if($health['message'] ?? null)
                    <p class="rounded-lg border border-border bg-ink-50 p-2 text-xs text-muted-foreground">
                        {{ $health['message'] }}
                    </p>
                @endif

                @if(($health['low_balance'] ?? false))
                    <p class="rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-800">
                        The Nitro wallet is below its alert threshold. Orders will start failing once it cannot cover
                        them — and the customer is charged before the provider is asked.
                    </p>
                @endif

                <a href="{{ route('admin.social-boost.provider') }}" class="btn btn-outline btn-sm w-full">
                    Provider health
                </a>
            </div>
        </div>

        @if($profitVisible)
            <div class="card lg:col-span-2">
                <div class="card-header">
                    <h2 class="card-title">Catalogue</h2>
                    <p class="card-description">
                        Services synchronised from Nitro. A service with no published rate cannot be sold.
                    </p>
                </div>
                <div class="card-body">
                    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt class="text-xs text-muted-foreground">Services</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums">{{ number_format($catalogue['services']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Sellable</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums">{{ number_format($catalogue['sellable']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Unpriced</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums {{ $catalogue['unpriced'] > 0 ? 'text-amber-700' : '' }}">
                                {{ number_format($catalogue['unpriced']) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Awaiting mapping</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums {{ $catalogue['staged'] > 0 ? 'text-amber-700' : '' }}">
                                {{ number_format($catalogue['staged']) }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-4 text-xs text-muted-foreground">
                        Last synchronised:
                        <span class="font-medium">{{ $catalogue['last_synced_at'] ? \Illuminate\Support\Carbon::parse($catalogue['last_synced_at'])->diffForHumans() : 'never' }}</span>
                        &middot; run <code>php artisan smm:sync-catalogue</code> to refresh.
                    </p>

                    @if($catalogue['staged'] > 0)
                        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <p class="text-sm font-medium text-amber-900">
                                {{ number_format($catalogue['staged']) }} Nitro service(s) are not mapped to a ReUp product.
                            </p>
                            <p class="mt-1 text-xs text-amber-800">
                                The sync never guesses a mapping: matching a provider's wording to one of our services
                                works until it is reworded, and then the wrong thing is sold.
                            </p>
                        </div>
                    @endif

                    <a href="{{ route('admin.social-boost.catalogue') }}" class="btn btn-outline btn-sm mt-4">
                        Open catalogue
                    </a>
                </div>
            </div>
        @endif
    </div>

    {{-- ===================== Recent orders ===================== --}}
    <div class="card">
        <div class="card-header flex items-center justify-between gap-3">
            <h2 class="card-title">Recent orders</h2>
            <a href="{{ route('admin.social-boost.orders') }}" class="link text-sm font-medium">View all</a>
        </div>

        @if($recent->isEmpty())
            <div class="card-body">
                <p class="text-sm text-muted-foreground">No Social Boost orders yet.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <caption class="sr-only">The most recent Social Boost orders</caption>
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Service</th>
                            <th scope="col">Status</th>
                            @if($profitVisible)
                                <th scope="col" class="text-right">Price</th>
                                <th scope="col" class="text-right">Profit</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recent as $order)
                            <tr>
                                <td class="whitespace-nowrap font-mono text-xs">
                                    <a href="{{ route('admin.social-boost.orders.show', $order->uuid) }}" class="link">
                                        {{ $order->uuid }}
                                    </a>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="block text-sm">{{ $order->user?->name ?? '—' }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ $order->user?->email }}</span>
                                </td>
                                <td class="max-w-xs">
                                    <span class="block truncate text-sm">{{ $order->serviceProduct?->name ?? $order->product_key }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ number_format($order->quantity) }} units</span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="badge {{ $order->status === \App\Models\ServiceOrder::STATUS_SUCCESS ? 'badge-success' : (in_array($order->status, [\App\Models\ServiceOrder::STATUS_FAILED, \App\Models\ServiceOrder::STATUS_RETRYABLE], true) ? 'badge-destructive' : (\App\Models\ServiceOrder::STATUS_PARTIAL === $order->status ? 'badge-warning' : 'badge-info')) }}">
                                        {{ $order->status }}
                                    </span>
                                </td>
                                @if($profitVisible)
                                    <td class="whitespace-nowrap text-right text-sm tabular-nums">
                                        {{ $order->pricingSnapshot ? \App\Support\Money::fromMinor((int) $order->pricingSnapshot->customer_price_minor)->format() : '—' }}
                                    </td>
                                    <td class="whitespace-nowrap text-right text-sm tabular-nums {{ ($order->pricingSnapshot->gross_profit_minor ?? 0) < 0 ? 'text-red-700' : '' }}">
                                        {{ $order->pricingSnapshot ? \App\Support\Money::fromMinor((int) $order->pricingSnapshot->gross_profit_minor)->format() : '—' }}
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

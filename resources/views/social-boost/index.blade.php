@extends('layouts.app')
@section('title', 'Social Boost')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Social Boost — landing
    |--------------------------------------------------------------------------
    | The entry point to the SMM module.
    |
    | Everything on this page comes from the synchronised catalogue: the
    | platforms with at least one sellable service, and the service types within
    | them. A platform with nothing sellable does not appear at all, so a
    | customer is never sent into an empty category — which is the failure mode
    | of a storefront built from a hard-coded platform list.
    |
    | No price is shown here. A rate-based service has no single price (the price
    | is a function of the quantity chosen), so the only honest figure is "from"
    | and it belongs on the service itself, where the minimum quantity is known.
    */
    $statusTones = [
        \App\Models\ServiceOrder::STATUS_PENDING => 'badge-warning',
        \App\Models\ServiceOrder::STATUS_PROCESSING => 'badge-info',
        \App\Models\ServiceOrder::STATUS_SUCCESS => 'badge-success',
        \App\Models\ServiceOrder::STATUS_PARTIAL => 'badge-warning',
        \App\Models\ServiceOrder::STATUS_FAILED => 'badge-destructive',
        \App\Models\ServiceOrder::STATUS_CANCELLED => 'badge-neutral',
        \App\Models\ServiceOrder::STATUS_REFUNDED => 'badge-neutral',
        \App\Models\ServiceOrder::STATUS_RETRYABLE => 'badge-destructive',
        \App\Models\ServiceOrder::STATUS_UNKNOWN => 'badge-info',
    ];

    $statusLabels = [
        \App\Models\ServiceOrder::STATUS_PENDING => 'Pending',
        \App\Models\ServiceOrder::STATUS_PROCESSING => 'In progress',
        \App\Models\ServiceOrder::STATUS_SUCCESS => 'Completed',
        \App\Models\ServiceOrder::STATUS_PARTIAL => 'Partly delivered',
        \App\Models\ServiceOrder::STATUS_FAILED => 'Failed',
        \App\Models\ServiceOrder::STATUS_CANCELLED => 'Cancelled',
        \App\Models\ServiceOrder::STATUS_REFUNDED => 'Refunded',
        \App\Models\ServiceOrder::STATUS_RETRYABLE => 'Not delivered',
        \App\Models\ServiceOrder::STATUS_UNKNOWN => 'Being confirmed',
    ];
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        {{-- ===================== Hero ===================== --}}
        <header class="mb-6 md:mb-8">
            <h1 class="mt-2 text-2xl font-semibold tracking-tight md:text-3xl">Social Boost</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                Grow your social media presence with real engagement. Pick a platform, choose what you want
                to grow, and pay from your ReUp wallet.
            </p>

            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('social-boost.catalogue', [], false) }}" class="btn btn-primary">
                    <x-icon name="rocket-takeoff" class="h-4 w-4" />
                    Browse all services
                </a>
                <a href="{{ route('social-boost.orders.index', [], false) }}" class="btn btn-outline">
                    <x-icon name="receipt-percent" class="h-4 w-4" />
                    My boost orders
                </a>
            </div>
        </header>

        {{-- ===================== Account summary ===================== --}}
        <div class="mb-6 grid grid-cols-2 gap-3 md:mb-8 md:grid-cols-4 md:gap-4">
            <div class="rounded-xl border border-brand-600 bg-brand-500 p-4 text-primary-foreground md:p-5">
                <p class="text-xs font-medium text-brand-50 md:text-sm">Wallet balance</p>
                <p class="mt-1.5 text-xl font-semibold tabular-nums md:text-2xl">
                    &#8358;{{ number_format((float) ($wallet->balance ?? 0), 2) }}
                </p>
                <a href="{{ route('wallet.fund') }}"
                   class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-primary-foreground underline underline-offset-2">
                    <x-icon name="plus" class="h-3.5 w-3.5" />
                    Fund wallet
                </a>
            </div>

            @foreach([
                ['label' => 'Total boosts', 'value' => $stats['total'], 'icon' => 'sparkles'],
                ['label' => 'In progress', 'value' => $stats['active'], 'icon' => 'clock'],
                ['label' => 'Completed', 'value' => $stats['completed'], 'icon' => 'check-circle'],
            ] as $tile)
                <div class="card p-4 md:p-5">
                    <span class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground md:text-sm">
                        <x-icon :name="$tile['icon']" class="h-3.5 w-3.5" />
                        {{ $tile['label'] }}
                    </span>
                    <p class="mt-1.5 text-xl font-semibold tabular-nums md:text-2xl">{{ number_format($tile['value']) }}</p>
                </div>
            @endforeach
        </div>

        {{-- ===================== Platforms ===================== --}}
        <section class="mb-8">
            <div class="mb-4 flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight">Choose a platform</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Only platforms we can deliver right now are shown.
                    </p>
                </div>
                <a href="{{ route('social-boost.catalogue', [], false) }}" class="link shrink-0 text-sm font-medium">
                    See everything
                </a>
            </div>

            @if(empty($platforms))
                {{-- An empty catalogue is a real state, not an error: the sync
                     has not run yet, or everything is paused. Saying so plainly
                     is better than an empty grid. --}}
                <div class="card">
                    <div class="empty-state">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                            <x-icon name="rocket-takeoff" class="h-5 w-5" />
                        </span>
                        <p class="mt-4 text-sm font-medium">Boosts are not available right now</p>
                        <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                            We are updating our service list. Please check back shortly — nothing has been charged.
                        </p>
                        <a href="{{ route('social-boost.help', [], false) }}" class="btn btn-outline btn-sm mt-5">Get help</a>
                    </div>
                </div>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 md:gap-4">
                    @foreach($platforms as $platform)
                        <a href="{{ route('social-boost.catalogue', ['platform' => $platform['key']]) }}"
                           class="card group p-4 transition-colors hover:border-brand-300 md:p-5">
                            <span class="flex h-11 w-11 items-center justify-center rounded-xl border border-border bg-surface text-ink-700 transition-colors group-hover:border-brand-300 group-hover:text-brand-600">
                                <x-icon :name="$platform['icon']" class="h-5 w-5" />
                            </span>
                            <p class="mt-3 text-sm font-semibold">{{ $platform['label'] }}</p>
                            <p class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{{ $platform['blurb'] }}</p>
                            <p class="mt-2 text-xs font-medium text-brand-600">
                                {{ number_format($platform['count']) }} {{ \Illuminate\Support\Str::plural('service', $platform['count']) }}
                            </p>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ===================== Popular categories ===================== --}}
        @if(!empty($serviceTypes))
            <section class="mb-8">
                <h2 class="text-lg font-semibold tracking-tight">Popular categories</h2>
                <p class="mt-1 text-sm text-muted-foreground">What most customers order.</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach(array_slice($serviceTypes, 0, 12, true) as $type => $count)
                        <a href="{{ route('social-boost.catalogue', ['type' => $type]) }}"
                           class="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3.5 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:border-brand-300 hover:text-foreground">
                            {{ $type }}
                            <span class="rounded-full bg-ink-100 px-1.5 text-xs tabular-nums">{{ number_format($count) }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ===================== How it works ===================== --}}
        <section class="mb-8">
            <h2 class="text-lg font-semibold tracking-tight">How it works</h2>
            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3 md:gap-4">
                @foreach([
                    ['icon' => 'magnifying-glass', 'title' => '1. Pick a service', 'body' => 'Choose the platform and what you want to grow — followers, likes, views and more.'],
                    ['icon' => 'link', 'title' => '2. Give us the link', 'body' => 'Paste your profile or post link. We check it is the right shape before you pay.'],
                    ['icon' => 'chart-bar', 'title' => '3. Watch it grow', 'body' => 'Delivery starts minutes after payment. Track progress any time from My boost orders.'],
                ] as $step)
                    <div class="card p-4 md:p-5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                            <x-icon :name="$step['icon']" class="h-5 w-5" />
                        </span>
                        <p class="mt-3 text-sm font-semibold">{{ $step['title'] }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">{{ $step['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ===================== Recent orders ===================== --}}
        @if($recentOrders->isNotEmpty())
            <section>
                <div class="mb-4 flex items-end justify-between gap-4">
                    <h2 class="text-lg font-semibold tracking-tight">Your recent boosts</h2>
                    <a href="{{ route('social-boost.orders.index', [], false) }}" class="link shrink-0 text-sm font-medium">
                        View all
                    </a>
                </div>

                <div class="card divide-y divide-border">
                    @foreach($recentOrders as $order)
                        <a href="{{ route('social-boost.orders.show', $order->uuid) }}"
                           class="flex items-center justify-between gap-4 p-4 transition-colors hover:bg-ink-50">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ $order->serviceProduct?->name ?? $order->product_key }}
                                </p>
                                <p class="mt-0.5 truncate text-xs text-muted-foreground">
                                    {{ number_format($order->quantity) }} &middot; {{ $order->created_at?->diffForHumans() }}
                                </p>
                            </div>
                            <span class="badge {{ $statusTones[$order->status] ?? 'badge-neutral' }} shrink-0">
                                {{ $statusLabels[$order->status] ?? 'In progress' }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ===================== Trust note ===================== --}}
        <p class="mt-8 text-center text-xs text-muted-foreground">
            Your wallet is charged only after you confirm the order. If a boost cannot be delivered,
            it is refunded to your wallet automatically.
            <a href="{{ route('social-boost.help', [], false) }}" class="link font-medium">Read the guide</a>.
        </p>
    </div>
</main>
@endsection

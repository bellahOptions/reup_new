@extends('layouts.app')
@section('title', 'My boost orders')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Social Boost — order history
    |--------------------------------------------------------------------------
    | Every status here is ReUp's own vocabulary, mapped from Nitro's. A customer
    | never reads `In progress` as a provider string they could look up, and never
    | sees a provider status at all — the canonical status is what the rest of the
    | application uses, and the detail page explains what it means.
    |
    | `Being confirmed` is the one worth stating plainly: it means we do not yet
    | know whether the provider fulfilled the order. It is deliberately *not*
    | presented as a failure, because telling a customer an order failed when it
    | may have succeeded is how they buy it a second time.
    */
    $tones = [
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

    $labels = [
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

    // Only statuses this customer actually has, so the filter never offers a
    // state that would return an empty list.
    $present = $orders->getCollection()->pluck('status')->unique()->values()->all();
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        <nav class="mb-4 flex items-center gap-2 text-xs text-muted-foreground" aria-label="Breadcrumb">
            <a href="{{ route('social-boost.index', [], false) }}" class="link">Social Boost</a>
            <x-icon name="chevron-right" class="h-3 w-3" />
            <span>My orders</span>
        </nav>

        <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">My boost orders</h1>
                <p class="mt-2 text-sm text-muted-foreground">Track progress and manage your orders.</p>
            </div>
            <a href="{{ route('social-boost.catalogue', [], false) }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" class="h-4 w-4" />
                New boost
            </a>
        </header>

        {{-- ===================== Filters ===================== --}}
        <form method="GET" action="{{ route('social-boost.orders.index', [], false) }}" class="card mb-6 p-4 md:p-5">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="lg:col-span-2">
                    <label for="q" class="label">Search</label>
                    <input type="search" id="q" name="q" class="input mt-1.5"
                           value="{{ $filters['q'] ?? '' }}"
                           placeholder="Reference, link or service">
                </div>

                <div>
                    <label for="status" class="label">Status</label>
                    <select id="status" name="status" class="select mt-1.5">
                        <option value="">Any status</option>
                        @foreach($allStatuses ?? $present as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>
                                {{ $labels[$status] ?? $status }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="from" class="label">From</label>
                    <input type="date" id="from" name="from" class="input mt-1.5" value="{{ $filters['from'] ?? '' }}">
                </div>

                <div>
                    <label for="to" class="label">To</label>
                    <input type="date" id="to" name="to" class="input mt-1.5" value="{{ $filters['to'] ?? '' }}">
                </div>
            </div>

            @if(!empty($services))
                <div class="mt-3">
                    <label for="service" class="label">Service</label>
                    <select id="service" name="service" class="select mt-1.5">
                        <option value="">Any service</option>
                        @foreach($services as $service)
                            <option value="{{ $service }}" @selected(($filters['service'] ?? null) === $service)>
                                {{ \Illuminate\Support\Str::headline(str_replace('-', ' ', $service)) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="mt-4 flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                @if(array_filter($filters ?? []))
                    <a href="{{ route('social-boost.orders.index', [], false) }}" class="btn btn-outline btn-sm">Clear</a>
                @endif
            </div>
        </form>

        {{-- ===================== Orders ===================== --}}
        @if($orders->isEmpty())
            <div class="card">
                <div class="empty-state">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                        <x-icon name="rocket-takeoff" class="h-5 w-5" />
                    </span>
                    <p class="mt-4 text-sm font-medium">
                        {{ array_filter($filters ?? []) ? 'No orders match that' : 'No boost orders yet' }}
                    </p>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                        {{ array_filter($filters ?? []) ? 'Try a different filter or clear them.' : 'Grow your first account in a few taps.' }}
                    </p>
                    <a href="{{ route('social-boost.catalogue', [], false) }}" class="btn btn-primary btn-sm mt-5">
                        Browse services
                    </a>
                </div>
            </div>
        @else
            {{-- Cards rather than a table: a social-boost row carries a target
                 URL and a progress figure, and a six-column table on a phone is
                 the reseller-panel look this design system avoids. --}}
            <div class="space-y-3">
                @foreach($orders as $order)
                    <a href="{{ route('social-boost.orders.show', $order->uuid) }}"
                       class="card block p-4 transition-colors hover:border-brand-300 md:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="badge {{ $tones[$order->status] ?? 'badge-neutral' }}">
                                        {{ $labels[$order->status] ?? 'In progress' }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ $order->created_at?->format('j M Y, g:i A') }}
                                    </span>
                                </div>

                                <p class="mt-2 truncate text-sm font-semibold">
                                    {{ $order->serviceProduct?->name ?? \Illuminate\Support\Str::headline(str_replace('-', ' ', $order->product_key)) }}
                                </p>

                                <p class="mt-0.5 truncate font-mono text-xs text-muted-foreground">{{ $order->recipient }}</p>

                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ number_format($order->quantity) }} units
                                    &middot; <span class="font-mono">{{ $order->uuid }}</span>
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold tabular-nums">{{ $order->money()->format() }}</p>
                                <p class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600">
                                    Details
                                    <x-icon name="chevron-right" class="h-3 w-3" />
                                </p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            @if($orders->hasPages())
                <div class="mt-6">{{ $orders->links() }}</div>
            @endif
        @endif
    </div>
</main>
@endsection

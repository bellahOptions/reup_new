@extends('admin.layouts.app')
@section('title', 'Social Boost orders')
@section('page-title', 'Social Boost orders')
@section('page-description', 'Every boost order, its lifecycle and its provider responses.')
@section('content')
@php
    $tone = function (string $status): string {
        return match ($status) {
            \App\Models\ServiceOrder::STATUS_SUCCESS => 'badge-success',
            \App\Models\ServiceOrder::STATUS_FAILED,
            \App\Models\ServiceOrder::STATUS_RETRYABLE => 'badge-destructive',
            \App\Models\ServiceOrder::STATUS_PARTIAL => 'badge-warning',
            \App\Models\ServiceOrder::STATUS_CANCELLED,
            \App\Models\ServiceOrder::STATUS_REFUNDED => 'badge-neutral',
            default => 'badge-info',
        };
    };

    $profitVisible = auth()->user()?->hasPermission('view_profit') ?? false;
@endphp

<div class="space-y-6">

    {{-- ===================== Filters ===================== --}}
    <form method="GET" action="{{ route('admin.social-boost.orders', [], false) }}" class="card p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <input type="search" id="q" name="q" class="input mt-1.5" value="{{ $filters['q'] ?? '' }}"
                       placeholder="ReUp reference, provider reference, customer, target">
            </div>

            <div>
                <label for="status" class="label">Status</label>
                <select id="status" name="status" class="select mt-1.5">
                    <option value="">Any status</option>
                    @foreach($allStatuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="platform" class="label">Platform</label>
                <input type="text" id="platform" name="platform" class="input mt-1.5"
                       value="{{ $filters['platform'] ?? '' }}" placeholder="instagram">
            </div>
        </div>

        <div class="mt-3 flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            @if(array_filter($filters ?? []))
                <a href="{{ route('admin.social-boost.orders', [], false) }}" class="btn btn-outline btn-sm">Clear</a>
            @endif
        </div>
    </form>

    {{-- ===================== Orders ===================== --}}
    <div class="card overflow-hidden">
        @if($orders->isEmpty())
            <div class="card-body">
                <p class="text-sm text-muted-foreground">No orders match.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <caption class="sr-only">Social Boost orders</caption>
                    <thead>
                        <tr>
                            <th scope="col">ReUp reference</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Service</th>
                            <th scope="col">Target</th>
                            <th scope="col">Status</th>
                            <th scope="col">Provider ref</th>
                            @if($profitVisible)
                                <th scope="col" class="text-right">Price</th>
                            @endif
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($orders as $order)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <a href="{{ route('admin.social-boost.orders.show', $order->uuid, false) }}" class="link font-mono text-xs">
                                        {{ $order->uuid }}
                                    </a>
                                    <span class="block text-xs text-muted-foreground">{{ $order->created_at?->format('j M, g:i A') }}</span>
                                </td>

                                <td class="whitespace-nowrap">
                                    <span class="block text-sm">{{ $order->user?->name ?? '—' }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ $order->user?->email }}</span>
                                </td>

                                <td class="max-w-xs">
                                    <span class="block truncate text-sm">{{ $order->serviceProduct?->name ?? $order->product_key }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ number_format($order->quantity) }} units</span>
                                </td>

                                <td class="max-w-xs">
                                    <span class="block truncate font-mono text-xs" title="{{ $order->recipient }}">{{ $order->recipient }}</span>
                                </td>

                                <td class="whitespace-nowrap">
                                    <span class="badge {{ $tone($order->status) }}">{{ $order->status }}</span>
                                    @if($order->provider_status)
                                        {{-- The provider's own vocabulary, shown here because
                                             this is the screen used to reconcile against
                                             Nitro's dashboard. Never shown to a customer. --}}
                                        <span class="block text-xs text-muted-foreground">{{ $order->provider_status }}</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap font-mono text-xs">{{ $order->provider_reference ?? '—' }}</td>

                                @if($profitVisible)
                                    <td class="whitespace-nowrap text-right text-sm tabular-nums">
                                        {{ $order->pricingSnapshot ? \App\Support\Money::fromMinor((int) $order->pricingSnapshot->customer_price_minor)->format() : '—' }}
                                    </td>
                                @endif

                                <td class="whitespace-nowrap text-right">
                                    <a href="{{ route('admin.social-boost.orders.show', $order->uuid, false) }}" class="btn btn-outline btn-sm h-8">
                                        Inspect
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($orders->hasPages())
                <div class="border-t border-border p-4">{{ $orders->links() }}</div>
            @endif
        @endif
    </div>
</div>
@endsection

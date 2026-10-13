@extends('admin.layouts.app')
@section('title', 'Order ' . $order->uuid)
@section('page-title', 'Boost order')
@section('page-description', $order->uuid)
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Admin — one Social Boost order
    |--------------------------------------------------------------------------
    | The screen an operator uses to answer a customer. It shows the whole
    | lifecycle: what the customer agreed to pay (the immutable snapshot), what
    | the provider was asked and answered (every provider transaction, in order),
    | and the two actions that are safe.
    |
    | ## The three buttons, and why one of them refuses things
    |
    | **Reconcile** asks the provider what happened. It is always safe: it queries,
    | it never creates an order.
    |
    | **Refund** is offered only when the outcome is known and the order was not
    | delivered. The controller refuses an unresolved or partially delivered order
    | regardless of the button, because refunding an order that may have been
    | delivered gives away the goods and the money.
    |
    | There is deliberately **no "retry" button.** `ServiceOrderService::retrySafely()`
    | refuses anything whose outcome is not a known non-fulfilment, and an order
    | whose outcome is a non-fulfilment is already refunded — so the button would
    | either do nothing or do the wrong thing. The safe retry is that no re-send
    | happens until the provider has said the order was not fulfilled.
    */
    $profitVisible = auth()->user()?->hasPermission('view_profit') ?? false;
    $snapshot = $order->pricingSnapshot;
@endphp

<div class="space-y-6">

    {{-- ===================== Header ===================== --}}
    <div class="card">
        <div class="card-body flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge {{ $order->status === \App\Models\ServiceOrder::STATUS_SUCCESS ? 'badge-success' : (in_array($order->status, [\App\Models\ServiceOrder::STATUS_FAILED, \App\Models\ServiceOrder::STATUS_RETRYABLE], true) ? 'badge-destructive' : (\App\Models\ServiceOrder::STATUS_PARTIAL === $order->status ? 'badge-warning' : 'badge-info')) }}">
                        {{ $order->status }}
                    </span>
                    @if(! $order->outcomeIsKnown())
                        <span class="badge badge-warning">
                            <x-icon name="exclamation-triangle" class="h-3 w-3" />
                            Outcome unknown
                        </span>
                    @endif
                </div>

                <h1 class="mt-2 text-lg font-semibold">
                    {{ $order->serviceProduct?->name ?? $order->product_key }}
                </h1>
                <p class="mt-1 font-mono text-xs text-muted-foreground">{{ $order->uuid }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.social-boost.orders', [], false) }}" class="btn btn-outline btn-sm">Back to orders</a>

                @can('manage_transactions')
                    <form method="POST" action="{{ route('admin.social-boost.orders.reconcile', $order->uuid, false) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline btn-sm">
                            <x-icon name="arrow-path" class="h-3.5 w-3.5" />
                            Reconcile now
                        </button>
                    </form>
                @endcan
            </div>
        </div>

        @if(! $order->outcomeIsKnown())
            <div class="border-t border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-medium text-amber-900">
                    This order's outcome is not known. It may have been delivered.
                </p>
                <p class="mt-1 text-xs text-amber-800">
                    Do not re-submit it and do not refund it. <strong>Reconcile now</strong> asks the provider what
                    happened; the amount stays held until the answer is known. Refunding here would give away the
                    service; re-sending would charge the customer twice.
                </p>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- ===================== What was ordered ===================== --}}
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">Order</h2>
            </div>
            <div class="card-body">
                <dl class="divide-y divide-border text-sm">
                    <div class="flex justify-between gap-6 py-3 first:pt-0">
                        <dt class="text-muted-foreground">Customer</dt>
                        <dd class="text-right font-medium">
                            {{ $order->user?->name ?? '—' }}
                            <span class="block text-xs font-normal text-muted-foreground">{{ $order->user?->email }}</span>
                        </dd>
                    </div>

                    <div class="flex justify-between gap-6 py-3">
                        <dt class="text-muted-foreground">Target</dt>
                        <dd class="break-all text-right font-mono text-xs">{{ $order->recipient }}</dd>
                    </div>

                    <div class="flex justify-between gap-6 py-3">
                        <dt class="text-muted-foreground">Quantity</dt>
                        <dd class="text-right font-medium tabular-nums">{{ number_format($order->quantity) }}</dd>
                    </div>

                    <div class="flex justify-between gap-6 py-3">
                        <dt class="text-muted-foreground">ReUp reference</dt>
                        <dd class="text-right font-mono text-xs">{{ $order->uuid }}</dd>
                    </div>

                    <div class="flex justify-between gap-6 py-3">
                        <dt class="text-muted-foreground">Provider reference</dt>
                        <dd class="text-right font-mono text-xs">{{ $order->provider_reference ?? '—' }}</dd>
                    </div>

                    <div class="flex justify-between gap-6 py-3">
                        <dt class="text-muted-foreground">Provider</dt>
                        <dd class="text-right font-medium">{{ $order->provider?->name ?? $order->provider_name ?? '—' }}</dd>
                    </div>

                    <div class="flex justify-between gap-6 py-3">
                        <dt class="text-muted-foreground">Provider service id</dt>
                        <dd class="text-right font-mono text-xs">{{ $order->providerProduct?->provider_product_id ?? '—' }}</dd>
                    </div>

                    <div class="flex justify-between gap-6 py-3">
                        <dt class="text-muted-foreground">Submitted</dt>
                        <dd class="text-right">{{ $order->submitted_at?->format('j M Y, g:i:s A') ?? '—' }}</dd>
                    </div>

                    <div class="flex justify-between gap-6 py-3">
                        <dt class="text-muted-foreground">Last reconciled</dt>
                        <dd class="text-right">
                            {{ $order->last_reconciled_at?->format('j M Y, g:i:s A') ?? 'never' }}
                            <span class="block text-xs text-muted-foreground">
                                {{ $order->reconcile_attempts }} attempt(s)
                                @if($order->next_reconcile_at)
                                    &middot; next {{ $order->next_reconcile_at->diffForHumans() }}
                                @endif
                            </span>
                        </dd>
                    </div>

                    <div class="flex justify-between gap-6 py-3">
                        <dt class="text-muted-foreground">Provider status</dt>
                        <dd class="text-right font-medium">{{ $order->provider_status ?? '—' }}</dd>
                    </div>

                    @if($order->failure_reason)
                        <div class="flex justify-between gap-6 py-3">
                            <dt class="text-muted-foreground">Failure reason</dt>
                            <dd class="text-right text-red-700">{{ $order->failure_reason }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>

        {{-- ===================== Pricing snapshot ===================== --}}
        <div class="space-y-6">
            @if($profitVisible && $snapshot)
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Pricing snapshot</h2>
                        <p class="card-description">
                            Written when the order was charged and never updated. This is what the customer agreed to.
                        </p>
                    </div>
                    <div class="card-body space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-foreground">Provider cost</span>
                            <span class="font-medium tabular-nums">
                                {{ \App\Support\Money::fromMinor((int) $snapshot->base_cost_minor)->format() }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-foreground">Markup</span>
                            <span class="font-medium tabular-nums">
                                {{ \App\Support\Money::fromMinor((int) $snapshot->markup_amount_minor)->format() }}
                                <span class="text-xs text-muted-foreground">
                                    ({{ number_format((int) $snapshot->markup_percentage_bps / 100, 2) }}%)
                                </span>
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-t border-border pt-3">
                            <span class="font-medium">Selling price</span>
                            <span class="text-base font-semibold tabular-nums">
                                {{ \App\Support\Money::fromMinor((int) $snapshot->customer_price_minor)->format() }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-foreground">Rounding</span>
                            <span class="tabular-nums">
                                {{ \App\Support\Money::fromMinor((int) $snapshot->rounding_adjustment_minor)->format() }}
                                <span class="text-xs text-muted-foreground">(step {{ \App\Support\Money::fromMinor((int) $snapshot->rounding_step_minor)->format() }})</span>
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-foreground">Gross profit</span>
                            <span class="font-medium tabular-nums {{ (int) $snapshot->gross_profit_minor < 0 ? 'text-red-700' : '' }}">
                                {{ \App\Support\Money::fromMinor((int) $snapshot->gross_profit_minor)->format() }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-foreground">Gross margin</span>
                            <span class="font-medium tabular-nums">
                                {{ number_format((int) $snapshot->profit_margin_bps / 100, 2) }}%
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-foreground">Cost source</span>
                            <span class="text-xs">{{ $snapshot->cost_source ?? '—' }}
                                @if($snapshot->cost_verified_at)
                                    <span class="block text-muted-foreground">{{ $snapshot->cost_verified_at }}</span>
                                @endif
                            </span>
                        </div>

                        @if((int) $snapshot->markup_percentage_bps < (int) config('pricing.smm.minimum_markup_bps', 1000))
                            {{-- Should be impossible: `SmmPricing` refuses a quote
                                 below the floor. Surfaced rather than hidden,
                                 because "this cannot happen" is a claim worth
                                 checking rather than asserting. --}}
                            <p class="rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-800">
                                This order's markup is below the mandatory
                                {{ number_format((int) config('pricing.smm.minimum_markup_bps', 1000) / 100, 2) }}%
                                floor. It was written before the floor was enforced, or the floor was lowered after
                                the order. The snapshot is not rewritten.
                            </p>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ===================== Actions ===================== --}}
            @can('manage_transactions')
                @if($canRefund)
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Refund</h2>
                            <p class="card-description">
                                Returns the full amount to the customer's wallet. This is the only refund path, and it
                                can happen once per order.
                            </p>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.social-boost.orders.refund', $order->uuid, false) }}"
                                  x-data="{ confirm: false }"
                                  @submit="if (! confirm) { $event.preventDefault(); confirm = true; }">
                                @csrf
                                <label for="reason" class="label">Reason</label>
                                <input type="text" id="reason" name="reason" class="input mt-1.5"
                                       required minlength="5" maxlength="300"
                                       placeholder="Not delivered by the provider">

                                <button type="submit" class="btn btn-outline btn-sm mt-3 w-full">
                                    <span x-show="!confirm">Refund {{ $order->money()->format() }}</span>
                                    <span x-show="confirm" x-cloak>Tap again to refund</span>
                                </button>

                                <p class="mt-2 text-xs text-muted-foreground">
                                    The reason is recorded on the ledger entry, so the refund is attributable.
                                </p>
                            </form>
                        </div>
                    </div>
                @endif

                @if($canRefill || $canCancel)
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Provider actions</h2>
                            <p class="card-description">
                                Offered only where the service itself supports them. Nitro publishes both per service.
                            </p>
                        </div>
                        <div class="card-body space-y-2">
                            @if($canRefill)
                                <p class="text-xs text-muted-foreground">
                                    A refill is free and tops the delivery back up. It does not change the order's
                                    status — the order did complete.
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Run <code>php artisan smm:reconcile-orders --order={{ $order->uuid }}</code>
                                    to pull the latest state after a customer requests one.
                                </p>
                            @endif

                            @if($canCancel)
                                <p class="text-xs text-muted-foreground">
                                    Cancellation is a request. Nitro's status decides whether it was granted, and the
                                    reconciliation sweep applies the outcome and refunds the customer automatically.
                                </p>
                            @endif
                        </div>
                    </div>
                @endif
            @endcan
        </div>
    </div>

    {{-- ===================== Provider transactions ===================== --}}
    <div class="card overflow-hidden">
        <div class="card-header">
            <h2 class="card-title">Provider interactions</h2>
            <p class="card-description">
                Every request made and every answer received, in order. Credentials and delivery tokens are stripped
                before a payload is stored.
            </p>
        </div>

        @if($order->providerTransactions->isEmpty())
            <div class="card-body">
                <p class="text-sm text-muted-foreground">No provider interaction has been recorded yet.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <caption class="sr-only">Provider interactions for this order</caption>
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Operation</th>
                            <th scope="col">Result</th>
                            <th scope="col">Provider status</th>
                            <th scope="col">HTTP</th>
                            <th scope="col">Error</th>
                            <th scope="col">Payload</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($order->providerTransactions as $interaction)
                            <tr>
                                <td class="whitespace-nowrap text-xs">
                                    {{ $interaction->created_at?->format('j M, g:i:s A') }}
                                </td>
                                <td class="whitespace-nowrap text-xs font-medium">{{ $interaction->operation }}</td>
                                <td class="whitespace-nowrap">
                                    <span class="badge {{ $interaction->status === \App\Providers\Support\ProviderStatus::SUCCESS ? 'badge-success' : (in_array($interaction->status, [\App\Providers\Support\ProviderStatus::FAILED, \App\Providers\Support\ProviderStatus::RETRYABLE], true) ? 'badge-destructive' : 'badge-info') }}">
                                        {{ $interaction->status }}
                                    </span>
                                    @if($interaction->rate_limited)
                                        <span class="badge badge-warning mt-1">rate limited</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-xs">{{ $interaction->provider_status ?? '—' }}</td>
                                <td class="whitespace-nowrap text-xs tabular-nums">{{ $interaction->http_status ?? '—' }}</td>
                                <td class="max-w-xs text-xs text-muted-foreground">
                                    <span class="line-clamp-2">{{ $interaction->error_message ?? '—' }}</span>
                                </td>
                                <td class="max-w-md">
                                    @if($interaction->response_payload)
                                        <details>
                                            <summary class="cursor-pointer text-xs text-muted-foreground">View JSON</summary>
                                            <pre class="mt-1 max-h-56 overflow-auto rounded bg-ink-50 p-2 text-xs">{{ json_encode($interaction->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </details>
                                    @else
                                        <span class="text-xs text-muted-foreground">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

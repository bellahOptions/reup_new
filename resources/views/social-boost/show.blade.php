@extends('layouts.app')
@section('title', 'Boost order ' . $order->uuid)
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Social Boost — order detail
    |--------------------------------------------------------------------------
    | This page is also the purchase confirmation. There is no separate "thank
    | you" screen: the reference, the service, the amount and the live status are
    | the four things a customer needs after paying, and a second page showing
    | them would be a second place for the same information to go stale.
    |
    | ## What is shown, and what deliberately is not
    |
    | Shown: the reference, the service, the target, the quantity, the amount, the
    | progress, the timestamps and the two actions the provider actually supports
    | for this service.
    |
    | Not shown: the provider's name, its service id, its status string, or any
    | part of an API response. Those live on the admin screen, where an operator
    | needs them to answer a support question. A customer reading `In progress`
    | rather than `start_count: 1200, remains: 450` is not being kept in the dark;
    | they are being spared a reseller-panel vocabulary that means nothing to them.
    */
    $tone = $label['tone'];
    $blurb = $label['blurb'];

    // The four states a customer can act on, and the tone each renders in.
    $settled = in_array($order->status, [
        \App\Models\ServiceOrder::STATUS_SUCCESS,
        \App\Models\ServiceOrder::STATUS_REFUNDED,
        \App\Models\ServiceOrder::STATUS_FAILED,
        \App\Models\ServiceOrder::STATUS_CANCELLED,
    ], true);
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        <nav class="mb-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground" aria-label="Breadcrumb">
            <a href="{{ route('social-boost.index', [], false) }}" class="link">Social Boost</a>
            <x-icon name="chevron-right" class="h-3 w-3" />
            <a href="{{ route('social-boost.orders.index', [], false) }}" class="link">My orders</a>
            <x-icon name="chevron-right" class="h-3 w-3" />
            <span class="truncate font-mono">{{ $order->uuid }}</span>
        </nav>

        {{-- ===================== Status banner ===================== --}}
        <div class="card mb-6 overflow-hidden">
            <div class="border-b border-border p-4 md:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="badge badge-{{ $tone }}">
                                @if($tone === 'success')
                                    <x-icon name="check-circle" variant="solid" class="h-3.5 w-3.5" />
                                @elseif(in_array($tone, ['destructive'], true))
                                    <x-icon name="exclamation-circle" variant="solid" class="h-3.5 w-3.5" />
                                @else
                                    <x-icon name="clock" class="h-3.5 w-3.5" />
                                @endif
                                {{ $label['label'] }}
                            </span>
                        </div>

                        <h1 class="mt-3 text-xl font-semibold tracking-tight">
                            {{ $order->serviceProduct?->name ?? \Illuminate\Support\Str::headline(str_replace('-', ' ', $order->product_key)) }}
                        </h1>

                        <p class="mt-1 text-sm text-muted-foreground">{{ $blurb }}</p>
                    </div>

                    <div class="shrink-0 text-left sm:text-right">
                        <p class="text-xs text-muted-foreground">Amount paid</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ $order->money()->format() }}</p>
                    </div>
                </div>
            </div>

            {{-- ===================== Progress =====================
                 Only rendered when the provider gave us a real delivered
                 count. A bar invented from no data would imply delivery that
                 has not happened, which is worse than showing nothing. --}}
            @if($progress)
                <div class="p-4 md:p-6">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="font-medium">Delivery progress</span>
                        <span class="tabular-nums text-muted-foreground">{{ $progress['percent'] }}%</span>
                    </div>

                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-ink-100"
                         role="progressbar"
                         aria-valuenow="{{ $progress['percent'] }}"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         aria-label="Delivery progress">
                        <div class="h-full rounded-full bg-brand-500 transition-all"
                             style="width: {{ max(2, $progress['percent']) }}%"></div>
                    </div>

                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ number_format($progress['delivered']) }} of
                        {{ number_format($progress['ordered']) }} delivered
                        @if($progress['remains'] > 0)
                            &middot; {{ number_format($progress['remains']) }} still to go
                        @endif
                    </p>
                </div>
            @elseif(! $settled)
                <div class="p-4 md:p-6">
                    <p class="flex items-start gap-2 text-sm text-muted-foreground">
                        <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            We will show a progress figure once the provider starts delivering. This page
                            updates as the order moves.
                        </span>
                    </p>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8">

            {{-- ===================== Details ===================== --}}
            <div class="lg:col-span-2">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Order details</h2>
                        <p class="card-description">Reference <span class="font-mono">{{ $order->uuid }}</span></p>
                    </div>

                    <div class="card-content md:p-6">
                        <dl class="divide-y divide-border">
                            <div class="flex flex-col gap-1 py-3.5 first:pt-0 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <dt class="text-sm text-muted-foreground">Reference</dt>
                                <dd class="break-all font-mono text-sm font-medium sm:text-right">{{ $order->uuid }}</dd>
                            </div>

                            <div class="flex flex-col gap-1 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <dt class="text-sm text-muted-foreground">Service</dt>
                                <dd class="text-sm font-medium sm:text-right">
                                    {{ $order->serviceProduct?->name ?? \Illuminate\Support\Str::headline(str_replace('-', ' ', $order->product_key)) }}
                                </dd>
                            </div>

                            <div class="flex flex-col gap-1 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <dt class="text-sm text-muted-foreground">Quantity</dt>
                                <dd class="text-sm font-medium tabular-nums sm:text-right">{{ number_format($order->quantity) }}</dd>
                            </div>

                            <div class="flex flex-col gap-1.5 py-3.5">
                                <dt class="text-sm text-muted-foreground">Target</dt>
                                <dd class="break-all rounded-lg border border-border bg-ink-50 p-3 font-mono text-sm">{{ $order->recipient }}</dd>
                            </div>

                            <div class="flex flex-col gap-1 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <dt class="text-sm text-muted-foreground">Amount</dt>
                                <dd class="text-sm font-medium tabular-nums sm:text-right">{{ $order->money()->format() }}</dd>
                            </div>

                            <div class="flex flex-col gap-1 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <dt class="text-sm text-muted-foreground">Ordered</dt>
                                <dd class="text-sm font-medium sm:text-right">
                                    {{ $order->created_at?->format('j M Y, g:i A') }}
                                </dd>
                            </div>

                            @if($order->completed_at)
                                <div class="flex flex-col gap-1 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <dt class="text-sm text-muted-foreground">Completed</dt>
                                    <dd class="text-sm font-medium sm:text-right">
                                        {{ $order->completed_at->format('j M Y, g:i A') }}
                                    </dd>
                                </div>
                            @endif

                            @if($order->refunded_at)
                                <div class="flex flex-col gap-1 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <dt class="text-sm text-muted-foreground">Refunded</dt>
                                    <dd class="text-sm font-medium sm:text-right">
                                        {{ $order->refunded_at->format('j M Y, g:i A') }}
                                    </dd>
                                </div>
                            @endif
                        </dl>

                        @if($order->status_message)
                            <div class="mt-4 rounded-xl border border-border bg-ink-50 p-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">Latest update</p>
                                <p class="mt-1 text-sm">{{ $order->status_message }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ===================== Actions ===================== --}}
            <aside class="lg:col-span-1">
                <div class="space-y-4">

                    @if($canRefill)
                        <div class="card p-4 md:p-5">
                            <h2 class="text-sm font-semibold">Need a refill?</h2>
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                This service supports refills. A refill tops the delivery back up if the count
                                drops, and it is free.
                            </p>
                            <form method="POST" action="{{ route('social-boost.orders.refill', $order->uuid) }}" class="mt-3">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm w-full">
                                    <x-icon name="arrow-path" class="h-3.5 w-3.5" />
                                    Request a refill
                                </button>
                            </form>
                        </div>
                    @endif

                    @if($canCancel)
                        <div class="card p-4 md:p-5">
                            <h2 class="text-sm font-semibold">Stop this order?</h2>
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                If the provider cancels it, the amount is refunded to your wallet automatically.
                                Cancellation is not always granted once delivery has started.
                            </p>
                            <form method="POST" action="{{ route('social-boost.orders.cancel', $order->uuid) }}"
                                  class="mt-3"
                                  x-data="{ confirm: false }"
                                  @submit="if (! confirm) { $event.preventDefault(); confirm = true; }">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm w-full"
                                        :class="confirm ? 'border-red-300 text-red-700 hover:bg-red-50' : ''">
                                    <span x-show="!confirm">Request cancellation</span>
                                    <span x-show="confirm" x-cloak>Tap again to confirm</span>
                                </button>
                            </form>
                        </div>
                    @endif

                    @if($order->status === \App\Models\ServiceOrder::STATUS_PARTIAL)
                        <div class="card border-amber-200 p-4 md:p-5">
                            <h2 class="flex items-center gap-2 text-sm font-semibold">
                                <x-icon name="exclamation-triangle" class="h-4 w-4 text-amber-600" />
                                Partly delivered
                            </h2>
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Part of this order was delivered and part was not. Our team is reviewing it and will
                                refund the difference. Nothing is needed from you.
                            </p>
                        </div>
                    @endif

                    <div class="card p-4 md:p-5">
                        <h2 class="text-sm font-semibold">Need help?</h2>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Most questions about links, delivery times and refills are answered in the guide.
                        </p>
                        <a href="{{ route('social-boost.help', [], false) }}" class="btn btn-outline btn-sm mt-3 w-full">
                            <x-icon name="question-circle" class="h-3.5 w-3.5" />
                            Read the guide
                        </a>
                        <a href="{{ route('contact') }}" class="btn btn-ghost btn-sm mt-2 w-full">
                            Contact support
                        </a>
                    </div>

                    <div class="card p-4 md:p-5">
                        <p class="flex items-start gap-2 text-xs text-muted-foreground">
                            <x-icon name="information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <span>
                                This page refreshes automatically while the order is running, so there is no need
                                to reload it.
                            </span>
                        </p>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

@unless($settled)
@push('scripts')
<script>
/*
 * Refresh the order while it is still running.
 *
 * ## Why it stops
 *
 * The interval clears itself the moment the order settles, and it pauses while
 * the tab is hidden. An SMM order can run for hours, and a page left open in a
 * background tab polling every thirty seconds all night is a request stream
 * nobody is reading — the browser's own `visibilitychange` is the cheapest
 * throttle available.
 *
 * ## Why it reads ReUp and not the provider
 *
 * The endpoint reports what the reconciliation sweep last learned. A browser
 * polling Nitro directly would spend the provider's rate limit — which live
 * purchases share — on customers watching a progress bar.
 *
 * ## Why it reloads rather than patching the DOM
 *
 * The status affects the badge colour, the progress bar, the explanatory copy and
 * which action cards render. Patching five things in JavaScript means five places
 * to disagree with the server's rendering; reloading means the page is always the
 * server's own answer. It is one small page.
 */
document.addEventListener('DOMContentLoaded', function () {
    const endpoint = @json(route('social-boost.orders.status', $order->uuid));
    const intervalMs = 30000;

    let timer = null;

    async function check() {
        try {
            const response = await fetch(endpoint, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();

            if (payload.settled) {
                window.location.reload();

                return;
            }

            // A progress figure appearing, or the status moving on, is the two
            // things worth a reload; anything else can wait for the next tick.
            if (payload.progress || payload.can_refill || payload.can_cancel) {
                window.location.reload();
            }
        } catch (error) {
            // A failed poll is not worth telling the customer about: the page
            // they are looking at is still accurate as of the last one.
            console.debug('Order status poll failed:', error);
        }
    }

    function start() {
        if (timer) {
            return;
        }

        timer = setInterval(check, intervalMs);
    }

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stop();
        } else {
            check();
            start();
        }
    });

    start();
});
</script>
@endpush
@endunless
@endsection

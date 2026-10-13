@extends('layouts.app')
@section('title', 'Review your boost order')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Social Boost — order review
    |--------------------------------------------------------------------------
    | The step that exists because a social-boost order cannot be undone. The
    | provider starts within minutes, the target cannot be changed once delivery
    | begins, and a mistyped link means the engagement lands on somebody else's
    | account. So the target is shown back in full, at a readable size, next to
    | the exact amount — and the debit happens only on the explicit confirmation
    | at the bottom of this page.
    |
    | Nothing on this page has been charged. The price was computed by
    | `SmmPricing` a moment ago and is recomputed again when the form is
    | submitted, so a figure that moved in between produces a confirmation
    | prompt rather than a surprise.
    */
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        <nav class="mb-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground" aria-label="Breadcrumb">
            <a href="{{ route('social-boost.index', [], false) }}" class="link">Social Boost</a>
            <x-icon name="chevron-right" class="h-3 w-3" />
            <a href="{{ route('social-boost.catalogue', [], false) }}" class="link">Services</a>
            <x-icon name="chevron-right" class="h-3 w-3" />
            <span>Review</span>
        </nav>

        <header class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight">Review your order</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                Check the link and the amount carefully. A boost starts within minutes of payment and
                cannot be redirected afterwards.
            </p>
        </header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8">

            {{-- ===================== No-JavaScript price confirmation ======
                 The modal below needs JavaScript. This is the same decision as a
                 plain form, so a customer on a degraded connection is not locked
                 out of their own purchase. The token is the only thing it posts;
                 the figures come from the server's own flash payload. --}}
            @if(session('price_change'))
                @php $change = session('price_change'); @endphp
                <div class="lg:col-span-3">
                    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4" role="status">
                        <h2 class="text-sm font-semibold text-amber-900">Boost price updated</h2>
                        <p class="mt-1 text-sm text-amber-900">{{ $change['message'] }}</p>

                        <dl class="mt-3 space-y-1 text-sm text-amber-900">
                            <div class="flex justify-between gap-4">
                                <dt>{{ $change['product']['name'] ?? 'Service' }}</dt>
                                <dd class="font-medium">{{ number_format($change['product']['quantity'] ?? $quantity) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>Previous price</dt>
                                <dd class="line-through">{{ $change['previous_price_formatted'] }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="font-medium">New price</dt>
                                <dd class="font-semibold">{{ $change['new_price_formatted'] }}</dd>
                            </div>
                        </dl>

                        <p class="mt-2 text-xs text-amber-800">Nothing has been charged. Your order details are kept.</p>

                        <form method="POST" action="{{ route('social-boost.purchase', [], false) }}"
                              class="mt-3 flex flex-col gap-2 sm:flex-row">
                            @csrf
                            <input type="hidden" name="service" value="{{ $row['key'] }}">
                            <input type="hidden" name="target" value="{{ $target }}">
                            <input type="hidden" name="quantity" value="{{ $quantity }}">
                            @foreach($orderParams as $field => $value)
                                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                            @endforeach
                            <input type="hidden" name="price" value="{{ $change['new_price'] }}">
                            <input type="hidden" name="price_confirmation" value="{{ $change['quote']['identifier'] }}">
                            <input type="hidden" name="pin" value="{{ old('pin') }}">

                            <button type="submit" class="btn btn-primary btn-sm">
                                Continue at {{ $change['new_price_formatted'] }}
                            </button>
                            <a href="{{ route('social-boost.configure', $row['key']) }}" class="btn btn-outline btn-sm">
                                Cancel transaction
                            </a>
                        </form>
                    </div>
                </div>
            @endif

            {{-- ===================== Order details ===================== --}}
            <div class="lg:col-span-2">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Order details</h2>
                    </div>

                    <div class="card-content md:p-6">
                        <dl class="divide-y divide-border">
                            <div class="flex flex-col gap-1 py-3.5 first:pt-0 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <dt class="text-sm text-muted-foreground">Service</dt>
                                <dd class="text-sm font-medium sm:text-right">{{ $row['name'] }}</dd>
                            </div>

                            <div class="flex flex-col gap-1 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <dt class="text-sm text-muted-foreground">Platform</dt>
                                <dd class="text-sm font-medium sm:text-right">{{ $row['platform_label'] }}</dd>
                            </div>

                            <div class="flex flex-col gap-1 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <dt class="text-sm text-muted-foreground">Quantity</dt>
                                <dd class="text-sm font-medium tabular-nums sm:text-right">{{ number_format($quantity) }}</dd>
                            </div>

                            {{-- The target, given the room it needs. This is the
                                 field that cannot be corrected later, so it is
                                 rendered in full and in monospace rather than
                                 truncated into a table cell. --}}
                            <div class="flex flex-col gap-1.5 py-3.5">
                                <dt class="text-sm text-muted-foreground">{{ $targetLabel ?? 'Target' }}</dt>
                                <dd class="break-all rounded-lg border border-border bg-ink-50 p-3 font-mono text-sm">{{ $target }}</dd>
                                <p class="text-xs text-muted-foreground">
                                    This is exactly what will be sent to the provider. If it is wrong, go back and
                                    correct it — a running order cannot be redirected.
                                </p>
                            </div>

                            @if(!empty($orderParams['comments']))
                                <div class="flex flex-col gap-1.5 py-3.5">
                                    <dt class="text-sm text-muted-foreground">Comments</dt>
                                    <dd class="whitespace-pre-line rounded-lg border border-border bg-ink-50 p-3 text-sm">{{ $orderParams['comments'] }}</dd>
                                </div>
                            @endif

                            @if(!empty($orderParams['keywords']))
                                <div class="flex flex-col gap-1.5 py-3.5">
                                    <dt class="text-sm text-muted-foreground">Keywords</dt>
                                    <dd class="whitespace-pre-line rounded-lg border border-border bg-ink-50 p-3 text-sm">{{ $orderParams['keywords'] }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if($row['requirement'])
                            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4" role="note">
                                <p class="flex items-center gap-2 text-sm font-semibold text-amber-900">
                                    <x-icon name="exclamation-triangle" variant="solid" class="h-4 w-4" />
                                    Before you pay, check this
                                </p>
                                <p class="mt-1.5 text-sm text-amber-900">{{ $row['requirement'] }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ===================== Payment summary ===================== --}}
            <aside class="lg:col-span-1">
                <div class="lg:sticky lg:top-6">
                    <form method="POST" action="{{ route('social-boost.purchase', [], false) }}" class="card" x-data="boostConfirm()">
                        @csrf

                        {{-- Everything the order needs, carried forward. None of
                             these is a price: the amount is derived server-side
                             from the service and the quantity on submit. --}}
                        <input type="hidden" name="service" value="{{ $row['key'] }}">
                        <input type="hidden" name="target" value="{{ $target }}">
                        <input type="hidden" name="quantity" value="{{ $quantity }}">
                        @foreach($orderParams as $field => $value)
                            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                        @endforeach
                        {{-- The figure the customer is looking at, so the server can
                             tell whether their view is stale. Never charged from. --}}
                        <input type="hidden" name="price" value="{{ $quote->price()->toDecimalString() }}">

                        <div class="card-header">
                            <h2 class="card-title">Payment</h2>
                        </div>

                        <div class="card-content space-y-4 md:p-6">
                            <dl class="space-y-3 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-muted-foreground">Amount</dt>
                                    <dd class="font-medium tabular-nums">{{ $quote->price()->format() }}</dd>
                                </div>

                                @if($quote->customerFeeMinor > 0)
                                    <div class="flex items-center justify-between gap-3">
                                        <dt class="text-muted-foreground">Service fee</dt>
                                        <dd class="font-medium tabular-nums">
                                            {{ \App\Support\Money::fromMinor($quote->customerFeeMinor)->format() }}
                                        </dd>
                                    </div>
                                @endif

                                <div class="flex items-center justify-between gap-3 border-t border-border pt-3">
                                    <dt class="font-medium">Total to pay</dt>
                                    <dd class="text-lg font-semibold tabular-nums">{{ $quote->price()->format() }}</dd>
                                </div>

                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-muted-foreground">Wallet balance</dt>
                                    <dd class="tabular-nums">{{ $balance->format() }}</dd>
                                </div>

                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-muted-foreground">Balance after</dt>
                                    <dd class="tabular-nums {{ $affordable ? '' : 'text-red-700' }}">
                                        {{ $balanceAfter->format() }}
                                    </dd>
                                </div>
                            </dl>

                            @unless($affordable)
                                {{-- Refused before the form is submitted rather than
                                     by the wallet service after a round trip. --}}
                                <div class="rounded-xl border border-red-200 bg-red-50 p-3" role="alert">
                                    <p class="text-sm font-semibold text-red-800">Not enough in your wallet</p>
                                    <p class="mt-1 text-xs text-red-700">
                                        You need {{ $quote->price()->format() }} but your balance is
                                        {{ $balance->format() }}. Fund your wallet and come back — this order has
                                        not been placed.
                                    </p>
                                    <a href="{{ route('wallet.fund') }}" class="btn btn-outline btn-sm mt-3">Fund wallet</a>
                                </div>
                            @endunless

                            @if($hasPin)
                                <div>
                                    <label for="pin" class="label">Transaction PIN</label>
                                    <input type="password" id="pin" name="pin" class="input mt-1.5"
                                           inputmode="numeric" maxlength="4" pattern="[0-9]{4}"
                                           autocomplete="off" required>
                                    @error('pin')
                                        <p class="field-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            <button type="submit"
                                    class="btn btn-primary btn-lg w-full"
                                    @if(!$affordable) disabled @endif>
                                <span x-show="!busy">Confirm and pay {{ $quote->price()->format() }}</span>
                                <span x-show="busy" x-cloak class="inline-flex items-center gap-2">
                                    <span class="h-4 w-4 animate-spin rounded-full border-2 border-primary-foreground border-t-transparent"></span>
                                    Placing your order…
                                </span>
                            </button>

                            <p class="text-center text-xs text-muted-foreground">
                                <a href="{{ route('social-boost.configure', $row['key']) }}" class="link font-medium">Change this order</a>
                            </p>
                        </div>
                    </form>

                    <div class="card mt-4 p-4 md:p-5">
                        <p class="flex items-start gap-2 text-xs text-muted-foreground">
                            <x-icon name="shield-check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <span>
                                If the price has changed since this page loaded, we will ask you to confirm the new
                                amount before charging anything.
                            </span>
                        </p>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

@push('scripts')
<script>
/*
 * The confirmation guard.
 *
 * A social-boost order cannot be undone, so the submit button is locked while the
 * request is in flight. That is the whole behaviour: it prevents the double-click
 * that would otherwise be two orders, and the server's own idempotency key is the
 * control that makes it impossible rather than merely unlikely.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('boostConfirm', () => ({
        busy: false,

        init() {
            this.$el.addEventListener('submit', () => {
                if (this.busy) {
                    return;
                }

                this.busy = true;
            });
        },
    }));
});
</script>
@endpush
@endsection

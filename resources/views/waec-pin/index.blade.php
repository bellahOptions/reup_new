@extends('layouts.app')
@section('title', 'WAEC e-PIN')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | WAEC e-PIN
    |--------------------------------------------------------------------------
    | This view did not exist (WaecPinController::index() returned
    | view('waec-pin.index'), so /waec-pin was a 500).
    |
    | Contract (WaecPinController):
    |   index    — $user, $unitPrice (config('bills.exam_pins.waec')),
    |              $recentTransactions.
    |   quantity — GET route('waec-pin.quantity')?quantity=N ->
    |              {unit_price, quantity, subtotal, max_quantity} (flat payload,
    |              not wrapped in `data`).
    |   purchase — POST route('waec-pin.purchase') {quantity, phone}; quantity is
    |              1–10 and the price is always resolved server-side.
    */
    $unitPrice = (float) ($unitPrice ?? config('bills.exam_pins.waec', 0));
    $fee = (float) config('bills.fees.exam_pin', 0);
    $recentTransactions = $recentTransactions ?? collect();

    // Mirrors the controller's `max:10` validation rule.
    $quantities = range(1, 10);

    $notes = [
        'The serial number and PIN are delivered by SMS within 5 minutes.',
        'Each card covers one candidate — buy one per candidate.',
        'Scratch cards cannot be returned once they have been issued.',
    ];

    $steps = [
        ['Choose quantity', 'One card per candidate, up to ten at a time.'],
        ['Pay from your wallet', 'The card cost is deducted from your balance.'],
        ['Receive the PIN', 'The serial number and PIN arrive by SMS.'],
    ];

    $statusBadges = [
        'success' => 'badge-success',
        'processing' => 'badge-info',
        'pending' => 'badge-warning',
        'failed' => 'badge-destructive',
        'cancelled' => 'badge-neutral',
    ];
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        {{-- Page heading --}}
        <header class="mb-6 md:mb-8">
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">WAEC e-PIN</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                Verification and registration scratch cards, issued instantly and sent by SMS.
            </p>
        </header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8" x-data="waecForm()">

            {{-- ===================== Purchase form ===================== --}}
            <div class="lg:col-span-2">
                <form id="waecForm"
                      action="{{ route('waec-pin.purchase', [], false) }}"
                      method="POST"
                      class="card"
                      @submit="onSubmit($event)">
                    @csrf

                    <div class="card-header">
                        <h2 class="card-title">Buy a scratch card</h2>
                        <p class="card-description">Choose how many cards you need, then pay.</p>
                    </div>

                    <div class="card-content space-y-6 md:p-6">

                        {{-- Wallet --}}
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface p-4">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                                    <x-icon name="wallet" variant="solid" class="h-5 w-5" />
                                </span>
                                <div>
                                    <p class="text-sm font-semibold">Wallet balance</p>
                                    <p class="text-xs text-muted-foreground">Charged when the cards are issued</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-xl font-semibold tabular-nums">
                                    &#8358;{{ number_format((float) ($user->wallet_balance ?? 0), 2) }}
                                </p>
                                <a href="{{ route('wallet.fund') }}" class="link text-sm font-medium">Fund wallet</a>
                            </div>
                        </div>

                        @if($unitPrice <= 0)
                            <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4" role="status">
                                <x-icon name="exclamation-triangle" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                                <p class="text-sm text-amber-900">
                                    WAEC e-PIN pricing is not configured yet. Please contact support.
                                </p>
                            </div>
                        @endif

                        {{-- Quantity --}}
                        <div>
                            <label for="quantity" class="label">Quantity</label>
                            <select id="quantity"
                                    name="quantity"
                                    class="select mt-1.5"
                                    x-model.number="quantity"
                                    @change="refreshPrice()"
                                    required>
                                @foreach($quantities as $option)
                                    <option value="{{ $option }}">{{ $option === 1 ? '1 card' : $option.' cards' }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                <x-icon name="information-circle" class="h-3.5 w-3.5" />
                                One card per candidate &middot; up to {{ end($quantities) }} per order.
                            </p>
                        </div>

                        {{-- Phone --}}
                        <div>
                            <label for="phone" class="label">Phone number</label>
                            <div class="relative mt-1.5">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-muted-foreground">
                                    <x-icon name="device-phone-mobile" class="h-4 w-4" />
                                </span>
                                <input type="tel"
                                       id="phone"
                                       name="phone"
                                       placeholder="08012345678"
                                       class="input pl-10"
                                       :value="phone"
                                       @input="onDigits($event, 'phone', 11)"
                                       maxlength="11"
                                       pattern="0[7-9][0-9]{9}"
                                       inputmode="numeric"
                                       autocomplete="tel"
                                       required>
                            </div>
                            <p class="mt-1.5 text-xs text-muted-foreground">The serial number and PIN are sent here.</p>
                        </div>

                        {{-- Amount. The server-rendered figures assume one card and
                             are the pre-Alpine fallback. --}}
                        <div class="rounded-xl border border-border bg-surface p-4">
                            <div class="flex items-center justify-between text-sm">
                                <span class="stat-label">Unit price</span>
                                <span class="tabular-nums text-muted-foreground" x-text="money(unitPrice)">&#8358;{{ number_format($unitPrice, 2) }}</span>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-sm">
                                <span class="stat-label">
                                    Card cost <span class="text-muted-foreground" x-text="quantityLabel">&times; 1</span>
                                </span>
                                <span class="font-semibold tabular-nums" x-text="money(subtotal)">&#8358;{{ number_format($unitPrice, 2) }}</span>
                            </div>
                            @if($fee > 0)
                                <div class="mt-2 flex items-center justify-between text-sm">
                                    <span class="stat-label">Service fee</span>
                                    <span class="tabular-nums text-muted-foreground" x-text="money(fee)">&#8358;{{ number_format($fee, 2) }}</span>
                                </div>
                            @endif
                            <div class="my-3 divider"></div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold">Total</span>
                                <span class="stat-value text-brand-600" x-text="money(total)">&#8358;{{ number_format($unitPrice + $fee, 2) }}</span>
                            </div>
                            <p class="mt-3 text-xs text-muted-foreground">
                                Debited from your wallet balance of
                                <span class="font-medium text-foreground">&#8358;{{ number_format((float) ($user->wallet_balance ?? 0), 2) }}</span>.
                            </p>
                        </div>

                        <p class="field-error" x-show="submitError" x-cloak x-text="submitError"></p>
                {{-- Bank-level controls: PIN verified server-side before the
                     debit; the key makes a double submit idempotent. --}}
                <x-transaction-pin id="pay-pin" />

                <input type="hidden" name="idempotency_key"
                       value="{{ Str::random(32) }}">


                        <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="unitPrice <= 0">
                            <x-icon name="document-check" class="h-5 w-5" />
                            <span>Pay now</span>
                        </button>

                        {{-- Notes --}}
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                            <p class="flex items-center gap-2 text-sm font-semibold text-amber-900">
                                <x-icon name="exclamation-triangle" variant="solid" class="h-4 w-4 shrink-0 text-amber-600" />
                                Before you pay
                            </p>
                            <ul class="mt-3 space-y-2">
                                @foreach($notes as $note)
                                    <li class="flex items-start gap-2 text-sm text-amber-800">
                                        <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-amber-700" aria-hidden="true"></span>
                                        {{ $note }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <p class="text-center text-xs text-muted-foreground">
                            By proceeding you agree to our
                            <a href="{{ route('terms-of-service') }}" class="link">Terms of Service</a>.
                        </p>
                    </div>
                </form>
            </div>

            {{-- ===================== Sidebar ===================== --}}
            <aside class="space-y-6">

                {{-- The single accent surface on this page. --}}
                <div class="rounded-xl border border-brand-600 bg-brand-500 p-5 text-white shadow-subtle md:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-brand-50">Wallet balance</p>
                            <p class="mt-2 text-3xl font-semibold tabular-nums">
                                &#8358;{{ number_format((float) ($user->wallet_balance ?? 0), 2) }}
                            </p>
                        </div>
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white/15">
                            <x-icon name="wallet" variant="solid" class="h-5 w-5 text-white" />
                        </span>
                    </div>
                    <a href="{{ route('wallet.fund') }}"
                       class="btn btn-lg mt-6 w-full border border-white/25 bg-white/15 text-white hover:bg-white/25">
                        <x-icon name="plus" class="h-5 w-5" />
                        Fund wallet
                    </a>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">How it works</h2>
                    </div>
                    <div class="card-content space-y-4">
                        @foreach($steps as $index => $step)
                            <div class="flex items-start gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-semibold text-accent-foreground">
                                    {{ $index + 1 }}
                                </span>
                                <div>
                                    <p class="text-sm font-medium">{{ $step[0] }}</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">{{ $step[1] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Recent e-PINs</h2>
                    </div>
                    <div class="card-content">
                        @forelse($recentTransactions as $transaction)
                            <div class="flex items-start justify-between gap-3 border-b border-border py-3 first:pt-0 last:border-0 last:pb-0">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ $transaction->description ?: 'WAEC e-PIN' }}
                                    </p>
                                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                                        {{ $transaction->recipient ?: 'No recipient' }}
                                        @if($transaction->created_at)
                                            &middot; {{ $transaction->created_at->diffForHumans() }}
                                        @endif
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-sm font-semibold tabular-nums">
                                        &#8358;{{ number_format((float) $transaction->total_amount, 2) }}
                                    </p>
                                    <span class="badge {{ $statusBadges[$transaction->status] ?? 'badge-neutral' }} mt-1">
                                        {{ ucfirst((string) $transaction->status) }}
                                    </span>
                                </div>
                            </div>
                        @empty
                            <div class="empty-state py-8">
                                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                                    <x-icon name="document-check" class="h-5 w-5" />
                                </span>
                                <p class="mt-3 text-sm text-muted-foreground">No e-PINs purchased yet</p>
                            </div>
                        @endforelse
                    </div>
                    @if($recentTransactions->count())
                        <div class="card-footer">
                            <a href="{{ route('transactions.index') }}" class="link text-sm font-medium">View all transactions</a>
                        </div>
                    @endif
                </div>
            </aside>
        </div>
    </div>
</main>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('waecForm', () => ({
        unitPrice: @json($unitPrice),
        fee: @json($fee),

        quantity: 1,
        phone: @json((string) ($user->phone ?? '')),

        submitError: '',

        get subtotal() {
            return Number(this.unitPrice || 0) * Number(this.quantity || 0);
        },

        get quantityLabel() {
            return '\u00D7 ' + Number(this.quantity || 0);
        },

        get total() {
            return this.subtotal + Number(this.fee || 0);
        },

        money(value) {
            return '\u20A6' + Number(value || 0).toLocaleString('en-NG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        // Numeric fields are sanitised on input and written back to the element so
        // the native `pattern` check can never disagree with the bound state.
        onDigits(event, field, maxLength) {
            const clean = String(event.target.value || '').replace(/[^0-9]/g, '').slice(0, maxLength);
            if (event.target.value !== clean) {
                event.target.value = clean;
            }
            this[field] = clean;
        },

        /**
         * Keep the displayed unit price in step with the server, which is the
         * only authority on what will actually be debited.
         */
        async refreshPrice() {
            try {
                const response = await fetch('{{ route('waec-pin.quantity') }}?quantity=' + encodeURIComponent(this.quantity), {
                    headers: { 'Accept': 'application/json' },
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();

                if (data && !isNaN(parseFloat(data.unit_price))) {
                    this.unitPrice = parseFloat(data.unit_price);
                }
            } catch (error) {
                // The server-rendered price stands if the refresh fails.
                console.error('WAEC price refresh unavailable:', error);
            }
        },

        onSubmit(event) {
            this.submitError = '';

            const quantity = Number(this.quantity || 0);

            if (!quantity || quantity < 1 || quantity > 10) {
                event.preventDefault();
                this.submitError = 'Choose between 1 and 10 cards.';
                return;
            }

            if (!/^0[7-9][0-9]{9}$/.test(String(this.phone || '').trim())) {
                event.preventDefault();
                this.submitError = 'Enter a valid Nigerian phone number (for example 08012345678).';
            }
        },
    }));
});
</script>
@endpush

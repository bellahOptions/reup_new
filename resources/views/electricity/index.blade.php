@extends('layouts.app')
@section('title', 'Electricity bill payment')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Electricity
    |--------------------------------------------------------------------------
    | This view did not exist (ElectricityController::index() returned
    | view('electricity.index'), so /electricity was a 500). It is written to the
    | same pattern as the other service pages.
    |
    | Contract (ElectricityController):
    |   index        — $user, $discos (code => label, from config/bills.php),
    |                  $recentTransactions.
    |   verify-meter — POST route('electricity.verify-meter')
    |                  {disco, meter_number, meter_type} ->
    |                  {success, data: {customer_name, customer_address, …}}.
    |   pay          — POST route('electricity.pay')
    |                  {disco, meter_number, meter_type, amount, phone};
    |                  amount must be at least 500.
    */
    $discos = $discos ?? [];
    $fee = (float) config('bills.fees.electricity', 0);
    $recentTransactions = $recentTransactions ?? collect();

    // Disco marks, served from our own domain. Config keys are the upstream
    // numeric codes; the filenames name the disco.
    $discoFiles = [
        '01' => 'ikedc', '02' => 'ekedc', '03' => 'aedc', '04' => 'phed',
        '05' => 'kedco', '06' => 'ibedc', '07' => 'eedc', '08' => 'jed',
    ];

    $discoLogos = collect(config('bills.discos', []))
        ->mapWithKeys(fn ($label, $code) => [
            (string) $code => asset('images/providers/' . ($discoFiles[$code] ?? 'ikedc') . '.svg'),
        ])
        ->all();

    $meterTypes = [
        'prepaid' => 'Prepaid — token meter',
        'postpaid' => 'Postpaid — billed meter',
    ];

    $quickAmounts = [1000, 2000, 5000, 10000];

    // The minimum the endpoint accepts is 500; start the form on a round figure.
    $initialAmount = 5000;

    $steps = [
        ['Select your disco', 'Pick the distribution company that serves your address.'],
        ['Enter the meter number', 'Confirm the meter so the token reaches the right account.'],
        ['Pay from your wallet', 'Tokens arrive instantly for prepaid meters.'],
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
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Electricity bill payment</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                Buy prepaid tokens or settle postpaid bills for every disco, paid from your wallet.
            </p>
        </header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8" x-data="electricityForm()">

            {{-- ===================== Payment form ===================== --}}
            <div class="lg:col-span-2">
                <form id="electricityForm"
                      action="{{ route('electricity.pay', [], false) }}"
                      method="POST"
                      class="card"
                      @submit="onSubmit($event)">
                    @csrf

                    <div class="card-header">
                        <h2 class="card-title">Pay a bill</h2>
                        <p class="card-description">Two steps — meter details, then payment.</p>
                    </div>

                    <div class="card-content space-y-6 md:p-6">

                        {{-- Step indicator --}}
                        <ol class="flex items-center gap-3 text-xs font-medium" aria-label="Progress">
                            <li class="flex items-center gap-2" :class="step === 1 ? 'text-foreground' : 'text-muted-foreground'">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full border text-xs"
                                      :class="step === 1 ? 'border-brand-500 bg-accent text-brand-700' : 'border-border bg-white text-muted-foreground'">1</span>
                                Meter
                            </li>
                            <li class="h-px flex-1 bg-border" aria-hidden="true"></li>
                            <li class="flex items-center gap-2" :class="step === 2 ? 'text-foreground' : 'text-muted-foreground'">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full border text-xs"
                                      :class="step === 2 ? 'border-brand-500 bg-accent text-brand-700' : 'border-border bg-white text-muted-foreground'">2</span>
                                Payment
                            </li>
                        </ol>

                        {{-- ============ Step 1: meter ============ --}}
                        <div class="space-y-6" x-show="step === 1" x-cloak>

                            <div>
                                <span class="label mb-3 block">Distribution company</span>

                                {{-- A native <select> cannot render the disco marks, so this is
                                     a radio grid. Each option keeps the `disco` field name and
                                     code value the controller validates. --}}
                                <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                                    @foreach($discos as $code => $label)
                                        @php $short = explode('—', $label)[0]; @endphp
                                        <label class="cursor-pointer">
                                            <input type="radio" name="disco" value="{{ $code }}"
                                                   class="peer sr-only" x-model="disco" required>
                                            <span class="flex h-full items-center gap-3 rounded-xl border border-border bg-white p-3 transition-colors hover:border-brand-300 peer-checked:border-brand-500 peer-checked:bg-accent peer-checked:shadow-subtle peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                                <img src="{{ $discoLogos[$code] ?? '' }}"
                                                     alt=""
                                                     class="h-8 w-8 shrink-0 rounded-lg object-contain"
                                                     loading="lazy">
                                                <span class="min-w-0">
                                                    <span class="block text-sm font-semibold">{{ trim($short) }}</span>
                                                    <span class="block truncate text-xs text-muted-foreground">
                                                        {{ trim(explode('—', $label)[1] ?? '') }}
                                                    </span>
                                                </span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>

                                @error('disco')<p class="field-error">{{ $message }}</p>@enderror
                            </div>

                            <fieldset>
                                <legend class="label mb-3">Meter type</legend>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @foreach($meterTypes as $value => $label)
                                        <label class="cursor-pointer">
                                            <input type="radio" name="meter_type" value="{{ $value }}" class="peer sr-only" x-model="meterType" required>
                                            <span class="flex items-center rounded-xl border border-border bg-white p-4 text-sm font-medium transition-colors hover:border-brand-300 peer-checked:border-brand-500 peer-checked:bg-accent peer-checked:shadow-subtle peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                                {{ $label }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <div>
                                <label for="meter_number" class="label">Meter number</label>
                                <div class="relative mt-1.5">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-muted-foreground">
                                        <x-icon name="bolt" class="h-4 w-4" />
                                    </span>
                                    <input type="text"
                                           id="meter_number"
                                           name="meter_number"
                                           placeholder="04123456789"
                                           class="input pl-10"
                                           :value="meterNumber"
                                           @input="onDigits($event, 'meterNumber', 20)"
                                           minlength="6"
                                           maxlength="20"
                                           pattern="[0-9]{6,20}"
                                           inputmode="numeric"
                                           autocomplete="off"
                                           required>
                                </div>
                                <p class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <x-icon name="information-circle" class="h-3.5 w-3.5" />
                                    Printed on the meter — digits only, 6 to 20 characters.
                                </p>
                            </div>

                            <button type="button" class="btn btn-outline w-full" @click="verifyMeter()" :disabled="verifying">
                                <span class="h-4 w-4 animate-spin rounded-full border-2 border-brand-500 border-t-transparent" x-show="verifying" x-cloak></span>
                                <x-icon name="magnifying-glass" class="h-4 w-4" x-show="!verifying" />
                                <span x-text="verifying ? 'Checking…' : 'Verify meter'"></span>
                            </button>

                            <p class="field-error" x-show="verifyError" x-cloak x-text="verifyError"></p>

                            <div class="rounded-xl border border-border bg-accent p-4" x-show="customerName" x-cloak>
                                <div class="flex items-start gap-3">
                                    <x-icon name="check-circle" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-accent-foreground">Meter verified</p>
                                        <dl class="mt-2 space-y-1 text-sm">
                                            <div class="flex gap-2">
                                                <dt class="text-muted-foreground">Customer</dt>
                                                <dd class="font-medium" x-text="customerName"></dd>
                                            </div>
                                            <div class="flex gap-2" x-show="customerAddress" x-cloak>
                                                <dt class="text-muted-foreground">Address</dt>
                                                <dd class="font-medium" x-text="customerAddress"></dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-primary w-full" @click="goToPayment()">
                                Continue
                                <x-icon name="arrow-right" class="h-4 w-4" />
                            </button>
                        </div>

                        {{-- ============ Step 2: payment ============ --}}
                        <div class="space-y-6" x-show="step === 2" x-cloak>

                            <div class="rounded-xl border border-border bg-surface p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="stat-label">Paying for</p>
                                        <p class="mt-1 truncate text-sm font-semibold" x-text="summaryDisco"></p>
                                        <p class="mt-0.5 truncate text-xs text-muted-foreground" x-text="summaryMeter"></p>
                                    </div>
                                    <button type="button" class="btn btn-ghost btn-sm shrink-0" @click="step = 1">
                                        <x-icon name="pencil" class="h-4 w-4" />
                                        Edit
                                    </button>
                                </div>
                                <p class="mt-3 flex items-start gap-1.5 text-xs text-amber-700" x-show="!customerName" x-cloak>
                                    <x-icon name="exclamation-triangle" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    This meter was not verified. Double-check the number before paying.
                                </p>
                            </div>

                            <div>
                                <label for="amount" class="label">Amount</label>
                                <div class="relative mt-1.5">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm font-medium text-muted-foreground">&#8358;</span>
                                    <input type="number"
                                           id="amount"
                                           name="amount"
                                           placeholder="5000"
                                           min="500"
                                           max="500000"
                                           step="50"
                                            class="input pl-9"
                                           x-model.number="amount"
                                           value="{{ $initialAmount }}"
                                           inputmode="numeric"
                                           required>
                                </div>
                                <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    @foreach($quickAmounts as $quickAmount)
                                        <button type="button"
                                                class="rounded-lg border border-border bg-white px-3 py-2 text-sm font-medium transition-colors hover:border-brand-400 hover:bg-accent"
                                                @click="amount = {{ $quickAmount }}">
                                            &#8358;{{ number_format($quickAmount) }}
                                        </button>
                                    @endforeach
                                </div>
                                <p class="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <x-icon name="information-circle" class="h-3.5 w-3.5" />
                                    Minimum &#8358;500 &middot; maximum &#8358;500,000
                                </p>
                            </div>

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
                                <p class="mt-1.5 text-xs text-muted-foreground">The token and receipt are sent here.</p>
                            </div>

                            <div class="rounded-xl border border-border bg-surface p-4">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="stat-label">Amount</span>
                                    <span class="font-semibold tabular-nums" x-text="money(amount)">&#8358;{{ number_format($initialAmount, 2) }}</span>
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
                                    <span class="stat-value text-brand-600" x-text="money(total)">&#8358;{{ number_format($initialAmount + $fee, 2) }}</span>
                                </div>
                                <p class="mt-3 text-xs text-muted-foreground">
                                    Debited from your wallet balance of
                                    <span class="font-medium text-foreground">&#8358;{{ number_format((float) ($user->wallet_balance ?? 0), 2) }}</span>.
                                </p>
                            </div>

                            <p class="field-error" x-show="submitError" x-cloak x-text="submitError"></p>

                            <div class="flex flex-col gap-3 sm:flex-row">
                                <button type="button" class="btn btn-outline sm:w-auto" @click="step = 1">
                                    <x-icon name="arrow-left" class="h-4 w-4" />
                                    Back
                                </button>
                {{-- Bank-level controls: PIN verified server-side before the
                     debit; the key makes a double submit idempotent. --}}
                <x-transaction-pin id="pay-pin" />

                <input type="hidden" name="idempotency_key"
                       value="{{ Str::random(32) }}">

                                <button type="submit" class="btn btn-primary btn-lg flex-1">
                                    <x-icon name="bolt" variant="solid" class="h-5 w-5" />
                                    Pay bill
                                </button>
                            </div>

                            <p class="text-center text-xs text-muted-foreground">
                                By proceeding you agree to our
                                <a href="{{ route('terms-of-service') }}" class="link">Terms of Service</a>.
                            </p>
                        </div>
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
                        <h2 class="card-title">Recent payments</h2>
                    </div>
                    <div class="card-content">
                        @forelse($recentTransactions as $transaction)
                            <div class="flex items-start justify-between gap-3 border-b border-border py-3 first:pt-0 last:border-0 last:pb-0">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ $transaction->description ?: 'Electricity payment' }}
                                    </p>
                                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                                        {{ $transaction->recipient ?: 'Meter' }}
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
                                    <x-icon name="bolt" class="h-5 w-5" />
                                </span>
                                <p class="mt-3 text-sm text-muted-foreground">No electricity payments yet</p>
                            </div>
                        @endforelse
                    </div>
                    @if($recentTransactions->count())
                        <div class="card-footer">
                            <a href="{{ route('transactions.index') }}" class="link text-sm font-medium">View all transactions</a>
                        </div>
                    @endif
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Support</h2>
                    </div>
                    <div class="card-content space-y-3 text-sm text-muted-foreground">
                        <p class="flex items-start gap-2.5">
                            <x-icon name="whatsapp" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-[#128C7E]" />
                            Message us on WhatsApp every day for failed or missing tokens.
                        </p>
                        <p class="flex items-start gap-2.5">
                            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            Failed payments are refunded to your wallet automatically.
                        </p>
                    </div>
                    <div class="card-footer">
                        @if(config('services.support.whatsapp_url'))
                            <a href="{{ config('services.support.whatsapp_url') }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="link text-sm font-medium">Chat on WhatsApp</a>
                        @endif
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('electricityForm', () => ({
        step: 1,

        labels: @json($discos),
        disco: '',
        meterType: 'prepaid',
        meterNumber: '',
        amount: @json($initialAmount),
        phone: @json((string) ($user->phone ?? '')),
        fee: @json($fee),

        verifying: false,
        verifyError: '',
        customerName: '',
        customerAddress: '',

        submitError: '',

        get total() {
            return Number(this.amount || 0) + Number(this.fee || 0);
        },

        get summaryDisco() {
            return this.labels[this.disco] || 'Selected disco';
        },

        get summaryMeter() {
            return (this.meterType === 'prepaid' ? 'Prepaid' : 'Postpaid') + ' — ' + (this.meterNumber || 'no meter number');
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

        goToPayment() {
            this.verifyError = '';

            if (!this.disco) {
                this.verifyError = 'Select a distribution company first.';
                return;
            }

            if (!/^[0-9]{6,20}$/.test(String(this.meterNumber || '').trim())) {
                this.verifyError = 'Enter the meter number (6 to 20 digits).';
                return;
            }

            this.step = 2;
        },

        async verifyMeter() {
            this.verifyError = '';
            this.customerName = '';
            this.customerAddress = '';

            if (!this.disco) {
                this.verifyError = 'Select a distribution company first.';
                return;
            }

            if (!/^[0-9]{6,20}$/.test(String(this.meterNumber || '').trim())) {
                this.verifyError = 'Enter the meter number (6 to 20 digits).';
                return;
            }

            this.verifying = true;

            try {
                const response = await fetch('{{ route('electricity.verify-meter') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({
                        disco: this.disco,
                        meter_number: String(this.meterNumber).trim(),
                        meter_type: this.meterType,
                    }),
                });

                const data = await response.json();
                const payload = data && data.data ? data.data : null;

                if (data && data.success && payload && payload.customer_name) {
                    this.customerName = payload.customer_name;
                    this.customerAddress = payload.customer_address || '';
                } else {
                    this.verifyError = (data && data.message) || 'That meter could not be verified. You can still continue.';
                }
            } catch (error) {
                console.error('Meter verification failed:', error);
                this.verifyError = 'Meter verification is unavailable right now. You can still continue to payment.';
            } finally {
                this.verifying = false;
            }
        },

        onSubmit(event) {
            this.submitError = '';

            if (!this.disco) {
                event.preventDefault();
                this.step = 1;
                this.submitError = 'Select a distribution company.';
                return;
            }

            if (!/^[0-9]{6,20}$/.test(String(this.meterNumber || '').trim())) {
                event.preventDefault();
                this.step = 1;
                this.submitError = 'Enter the meter number (6 to 20 digits).';
                return;
            }

            const amount = Number(this.amount || 0);

            if (!amount || amount < 500) {
                event.preventDefault();
                this.submitError = 'Enter an amount of at least \u20A6500.';
                return;
            }

            if (amount > 500000) {
                event.preventDefault();
                this.submitError = 'The maximum single payment is \u20A6500,000.';
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

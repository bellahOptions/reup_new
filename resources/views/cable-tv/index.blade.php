@extends('layouts.app')
@section('title', 'Cable TV subscription')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Cable TV
    |--------------------------------------------------------------------------
    | Contract (CableTvController):
    |   index  — $user, $providers (code => label, from config/bills.php),
    |            $recentTransactions.
    |   verify — POST route('cable-tv.verify') {provider, smartcard_number} ->
    |            {success, data: {customer_name, customer_number, package, status}}.
    |   pay    — POST route('cable-tv.purchase') {provider, smartcard_number,
    |            package, amount, phone}. `provider` is the config *code*
    |            (dstv/gotv/startimes/showmax) and `amount` is the bouquet price,
    |            which the endpoint requires.
    |
    | The bouquet catalogue is the one call that still happens in the browser:
    | ClubKonnectService::cableTvPackages() exists but no route or controller
    | value exposes it, and the upstream endpoint only returns price data (no
    | API key is involved). Everything that moves money runs server-side.
    */
    $providers = $providers ?? [];
    // Server-resolved bouquet catalogue (see CableTvController::index).
    $catalogue = $packages ?? [];
    $fee = (float) config('bills.fees.cable_tv', 0);
    $recentTransactions = $recentTransactions ?? collect();

    // Presentation-only brand marks, keyed by the provider code.
    $providerLogos = collect(config('bills.cable_providers', []))
        ->mapWithKeys(fn ($label, $code) => [
            $code => asset('images/providers/' . ($code === 'startimes' ? 'startimes' : $code) . '.svg'),
        ])
        ->all();

    $infoItems = [
        'Activation lands within 5 minutes',
        'Verify the smartcard before paying',
        $fee > 0 ? 'A ' . number_format($fee, 2) . ' service fee is added at checkout' : 'No service fee on cable TV',
        'An SMS confirmation follows every order',
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
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Cable TV subscription</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                Renew DStv, GOtv, StarTimes and Showmax from your wallet — verified and activated in minutes.
            </p>
        </header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8" x-data="cableTvForm()">

            {{-- ===================== Purchase form ===================== --}}
            <div class="lg:col-span-2">
                <form id="cableTvForm"
                      action="{{ route('cable-tv.purchase', [], false) }}"
                      method="POST"
                      class="card"
                      @submit="onSubmit($event)">
                    @csrf

                    <div class="card-header">
                        <h2 class="card-title">New subscription</h2>
                        <p class="card-description">Pick a provider, confirm the smartcard, then choose a bouquet.</p>
                    </div>

                    <div class="card-content space-y-6 md:p-6">

                        {{-- Provider --}}
                        <fieldset>
                            <legend class="label mb-3">Select provider</legend>
                            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                                @foreach($providers as $code => $label)
                                    <label class="cursor-pointer">
                                        <input type="radio"
                                               name="provider"
                                               value="{{ $code }}"
                                               class="peer sr-only"
                                               x-model="provider"
                                               @change="onProviderChange($event.target.value)"
                                               required>
                                        <span class="flex flex-col items-center gap-2 rounded-xl border border-border bg-surface p-4 text-center transition-colors hover:border-brand-300 peer-checked:border-brand-500 peer-checked:bg-accent peer-checked:shadow-subtle peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                            @if(!empty($providerLogos[$code]))
                                                <img src="{{ $providerLogos[$code] }}" alt="" class="h-6 w-6 rounded-full object-contain" loading="lazy">
                                            @else
                                                <span class="flex h-6 w-6 items-center justify-center rounded-md border border-border bg-surface text-xs font-semibold text-muted-foreground" aria-hidden="true">{{ substr($label, 0, 1) }}</span>
                                            @endif
                                            <span class="text-sm font-semibold">{{ $label }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="field-error" x-show="providerError" x-cloak x-text="providerError"></p>
                        </fieldset>

                        {{-- Smartcard --}}
                        <div>
                            <label for="smartcard_number" class="label">
                                SmartCard / IUC number
                                <span class="font-normal text-muted-foreground">— printed on the decoder</span>
                            </label>
                            <div class="relative mt-1.5">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-muted-foreground">
                                    <x-icon name="credit-card" class="h-4 w-4" />
                                </span>
                                <input type="text"
                                       id="smartcard_number"
                                       name="smartcard_number"
                                       placeholder="1234567890"
                                       class="input pl-10"
                                       :value="smartcard"
                                       @input="onDigits($event, 'smartcard', 20)"
                                       minlength="6"
                                       maxlength="20"
                                       pattern="[0-9]{6,20}"
                                       inputmode="numeric"
                                       autocomplete="off"
                                       required>
                            </div>
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                <x-icon name="information-circle" class="h-3.5 w-3.5" />
                                Digits only, 6 to 20 characters.
                            </p>
                        </div>

                        {{-- Verify --}}
                        <div>
                            <button type="button"
                                    class="btn btn-outline w-full"
                                    @click="verifySmartcard()"
                                    :disabled="verifying">
                                <span class="h-4 w-4 animate-spin rounded-full border-2 border-brand-500 border-t-transparent" x-show="verifying" x-cloak></span>
                                <x-icon name="magnifying-glass" class="h-4 w-4" x-show="!verifying" />
                                <span x-text="verifying ? 'Verifying…' : 'Verify smartcard number'"></span>
                            </button>
                            <p class="field-error" x-show="verifyError" x-cloak x-text="verifyError"></p>
                        </div>

                        {{-- Verified customer --}}
                        <div class="rounded-xl border border-border bg-accent p-4" x-show="customerName" x-cloak>
                            <div class="flex items-start gap-3">
                                <x-icon name="check-circle" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-accent-foreground">Account verified</p>
                                    <dl class="mt-2 space-y-1 text-sm">
                                        <div class="flex gap-2">
                                            <dt class="text-muted-foreground">Customer</dt>
                                            <dd class="font-medium" x-text="customerName"></dd>
                                        </div>
                                        <div class="flex gap-2" x-show="customerPackage" x-cloak>
                                            <dt class="text-muted-foreground">Current package</dt>
                                            <dd class="font-medium" x-text="customerPackage"></dd>
                                        </div>
                                        <div class="flex gap-2">
                                            <dt class="text-muted-foreground">Status</dt>
                                            <dd><span class="badge badge-success">Active</span></dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </div>

                        {{-- Bouquet --}}
                        <div>
                            <label for="package" class="label">Package / bouquet</label>
                            <div class="mt-1.5 flex items-center gap-2 py-3 text-sm text-muted-foreground" x-show="loadingPackages" x-cloak>
                                <span class="h-4 w-4 animate-spin rounded-full border-2 border-brand-500 border-t-transparent"></span>
                                Loading packages…
                            </div>
                            <select id="package"
                                    name="package"
                                    class="select mt-1.5"
                                    x-ref="packageSelect"
                                    @change="onPackageChange($event)"
                                    x-show="!loadingPackages"
                                    required
                                    :disabled="!packages.length">
                                <option value="">Select a package</option>
                            </select>
                            <p class="field-error" x-show="catalogueError" x-cloak x-text="catalogueError"></p>
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
                            <p class="mt-1.5 text-xs text-muted-foreground">Used for the confirmation SMS.</p>
                        </div>

                        {{-- Amount: the bouquet price the endpoint requires. --}}
                        <input type="hidden" name="amount" :value="amount">

                        {{-- Summary. The server-rendered figures are the pre-Alpine
                             fallback; Alpine replaces them once a bouquet is chosen. --}}
                        <div class="rounded-xl border border-border bg-surface p-4">
                            <div class="flex items-center justify-between text-sm">
                                <span class="stat-label">Package amount</span>
                                <span class="font-semibold tabular-nums" x-text="money(amount)">&#8358;0.00</span>
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
                                <span class="stat-value text-brand-600" x-text="money(total)">&#8358;{{ number_format($fee, 2) }}</span>
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


                        <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="!packages.length">
                            <x-icon name="wallet" class="h-5 w-5" />
                            <span>Pay now</span>
                        </button>

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
                <div class="rounded-xl border border-brand-600 bg-brand-500 p-5 text-primary-foreground shadow-subtle md:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-brand-50">Wallet balance</p>
                            <p class="mt-2 text-3xl font-semibold tabular-nums">
                                &#8358;{{ number_format((float) ($user->wallet_balance ?? 0), 2) }}
                            </p>
                        </div>
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary-foreground/15">
                            <x-icon name="wallet" variant="solid" class="h-5 w-5 text-primary-foreground" />
                        </span>
                    </div>
                    <a href="{{ route('wallet.fund') }}"
                       class="btn btn-lg mt-6 w-full border border-primary-foreground/25 bg-primary-foreground/15 text-primary-foreground hover:bg-primary-foreground/25">
                        <x-icon name="plus" class="h-5 w-5" />
                        Fund wallet
                    </a>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Recent subscriptions</h2>
                    </div>
                    <div class="card-content">
                        @forelse($recentTransactions as $transaction)
                            <div class="flex items-start justify-between gap-3 border-b border-border py-3 first:pt-0 last:border-0 last:pb-0">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ $transaction->description ?: 'Cable TV subscription' }}
                                    </p>
                                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                                        {{ $transaction->recipient ?: 'Smartcard' }}
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
                                    <x-icon name="tv" class="h-5 w-5" />
                                </span>
                                <p class="mt-3 text-sm text-muted-foreground">No subscriptions yet</p>
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
                        <h2 class="card-title">Good to know</h2>
                    </div>
                    <div class="card-content space-y-3">
                        @foreach($infoItems as $item)
                            <p class="flex items-start gap-2.5 text-sm text-muted-foreground">
                                <x-icon name="check-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                                {{ $item }}
                            </p>
                        @endforeach
                    </div>
                    <div class="card-footer">
                        <a href="{{ route('contact') }}" class="link text-sm font-medium">Need help? Contact support</a>
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
    Alpine.data('cableTvForm', () => ({
        // Bouquet catalogue, resolved server-side and cached. The browser never
        // talks to the provider and no credential reaches the page.
        catalogue: @json($catalogue),
        refreshUrl: @json(route('cable-tv.packages')),

        providers: @json(array_keys($providers)),
        labels: @json($providers),
        provider: @json($providers ? array_key_first($providers) : ''),
        smartcard: '',
        phone: @json((string) ($user->phone ?? '')),
        packageCode: '',
        fee: @json($fee),

        packages: [],
        loadingPackages: false,
        catalogueError: '',

        verifying: false,
        verifyError: '',
        customerName: '',
        customerPackage: '',

        providerError: '',
        submitError: '',

        init() {
            this.applyCatalogue(this.catalogue);

            if (!this.packages.length) {
                this.catalogueError = this.catalogueError
                    || 'Package prices are unavailable right now. Please contact support.';
            }
        },


        /**
         * Turn the provider's nested catalogue into a flat, price-sorted list.
         * Shape: { BRAND: [ { ID, PRODUCT: [ { PACKAGE_ID, PACKAGE_NAME,
         * PACKAGE_AMOUNT, PRODUCT_DISCOUNT_AMOUNT } ] } ] }
         */
        applyCatalogue(catalogue) {
            const byProvider = this.catalogueEntry(catalogue || {});

            if (!byProvider || !byProvider[0] || !byProvider[0].PRODUCT) {
                this.catalogueError = 'No packages are available for this provider right now.';
                this.packages = [];
                return;
            }

            this.packages = byProvider[0].PRODUCT.map((product) => {
                const base = parseFloat(product.PACKAGE_AMOUNT) || 0;
                const discounted = parseFloat(product.PRODUCT_DISCOUNT_AMOUNT) || 0;

                return {
                    code: String(product.PACKAGE_ID ?? ''),
                    name: String(product.PACKAGE_NAME || ''),
                    price: discounted > 0 ? discounted : base,
                };
            });

            // Drop anything the provider returned without a usable price, so
            // the form cannot submit a zero-value bouquet.
            this.packages = this.packages.filter((plan) => plan.code && plan.price > 0);

            this.$nextTick(() => this.renderPackages());
        },

        get selectedPackage() {
            return this.packages.find((plan) => plan.code === this.packageCode) || null;
        },

        get amount() {
            return this.selectedPackage ? this.selectedPackage.price : 0;
        },

        get total() {
            return this.amount + Number(this.fee || 0);
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

        // Bouquets arrive grouped by cadence, cheapest first — the grouping the
        // upstream catalogue has always produced.
        packageType(name) {
            const value = String(name || '').toLowerCase();
            if (value.includes('weekly') || value.includes('1 week')) return 'Weekly';
            if (value.includes('monthly') || value.includes('1 month')) return 'Monthly';
            if (value.includes('quarterly') || value.includes('3 months')) return 'Quarterly';
            if (value.includes('6 months')) return '6 months';
            if (value.includes('yearly') || value.includes('1 year')) return 'Yearly';
            if (value.includes('add-on') || value.includes('addon')) return 'Add-ons';
            if (value.includes('standalone')) return 'Standalone';
            if (value.includes('dish') || value.includes('antenna')) return 'Device specific';
            return 'Regular';
        },

        renderPackages() {
            const select = this.$refs.packageSelect;
            if (!select) return;

            select.innerHTML = '';

            // Keep an explicit empty choice so the select never *looks* like a
            // bouquet is chosen while `packageCode` is still empty.
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select a package';
            placeholder.disabled = true;
            placeholder.selected = true;
            select.appendChild(placeholder);

            const order = ['Monthly', 'Weekly', 'Quarterly', 'Yearly', '6 months', 'Regular', 'Device specific', 'Add-ons', 'Standalone'];
            const grouped = {};

            this.packages.forEach((plan) => {
                const type = this.packageType(plan.name);
                (grouped[type] = grouped[type] || []).push(plan);
            });

            order.forEach((type) => {
                if (!grouped[type]) return;

                const group = document.createElement('optgroup');
                group.label = type + ' packages';

                grouped[type]
                    .sort((a, b) => a.price - b.price)
                    .forEach((plan) => {
                        const option = document.createElement('option');
                        option.value = plan.code;
                        option.textContent = plan.name + ' — ' + this.money(plan.price);
                        group.appendChild(option);
                    });

                select.appendChild(group);
            });

            select.disabled = this.packages.length === 0;
        },


        /**
         * The catalogue keys by brand name ("DStv"), the form posts the config
         * code ("dstv") — try every plausible key before giving up.
         */
        catalogueEntry(data) {
            const tv = data && data.TV_ID ? data.TV_ID : null;
            if (!tv) return null;

            const label = this.labels[this.provider] || this.provider;
            const candidates = [label, String(label).toUpperCase(), String(this.provider).toUpperCase()];

            for (const key of candidates) {
                if (tv[key]) return tv[key];
            }

            return null;
        },

        onProviderChange(value) {
            this.provider = value;
            this.packageCode = '';
            this.providerError = '';
            this.submitError = '';
            this.resetVerification();

            // The catalogue arrives keyed by brand, so re-resolve the list for
            // the newly selected provider from data we already hold.
            this.applyCatalogue(this.catalogue);
        },

        onPackageChange(event) {
            this.packageCode = event.target.value;
            this.submitError = '';
        },

        resetVerification() {
            this.customerName = '';
            this.customerPackage = '';
            this.verifyError = '';
        },

        async verifySmartcard() {
            const smartcard = String(this.smartcard || '').trim();

            this.submitError = '';
            this.resetVerification();

            if (!this.provider) {
                this.providerError = 'Choose a provider first.';
                return;
            }

            this.providerError = '';

            if (!/^[0-9]{6,20}$/.test(smartcard)) {
                this.verifyError = 'Enter the smartcard number (6 to 20 digits).';
                return;
            }

            this.verifying = true;

            try {
                const response = await fetch('{{ route('cable-tv.verify') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({
                        provider: this.provider,
                        smartcard_number: smartcard,
                    }),
                });

                const data = await response.json();
                const payload = data && data.data ? data.data : null;
                const name = payload ? (payload.customer_name || payload.name) : null;

                if (data && data.success && name) {
                    this.customerName = name;
                    this.customerPackage = payload.package || payload.current_package || '';
                } else {
                    this.verifyError = (data && data.message) || 'That smartcard could not be verified.';
                }
            } catch (error) {
                console.error('Cable TV verification failed:', error);
                this.verifyError = 'Verification is unavailable right now. Please try again.';
            } finally {
                this.verifying = false;
            }
        },

        onSubmit(event) {
            this.providerError = '';
            this.submitError = '';

            if (!this.provider) {
                event.preventDefault();
                this.providerError = 'Choose a provider.';
                return;
            }

            if (!/^[0-9]{6,20}$/.test(String(this.smartcard || '').trim())) {
                event.preventDefault();
                this.submitError = 'Enter the smartcard number before paying.';
                return;
            }

            if (!this.packageCode) {
                event.preventDefault();
                this.submitError = 'Select a package before paying.';
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

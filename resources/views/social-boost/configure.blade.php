@extends('layouts.app')
@section('title', $row['name'])
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Social Boost — order configuration
    |--------------------------------------------------------------------------
    | The form collects three things and validates each one where the customer
    | can still act on the answer: the target (against the platform's own shape),
    | the quantity (against the provider's published min/max) and the PIN.
    |
    | ## Why the live estimate is a *preview*, and says so
    |
    | The price is a function of the quantity, so the running total has to be
    | shown as the customer types — but the figure in the browser is a display
    | aid and nothing more. It is computed by the same `SmmPricing` on the server
    | and posted to the review step, where it is recomputed from the catalogue
    | before anything is shown as final. A client that edits the number changes
    | only what it believes; the review step prices it again.
    |
    | The inputs are deliberately plain: no stepper widget, no drag control, and
    | no `type="number"` spinner that a mobile keyboard renders unpredictably.
    | Quantity is a text field with `inputmode="numeric"` so the keypad is right
    | and the value is still a value the server validates.
    */
    $min = (int) ($row['min_quantity'] ?? 1);
    $max = $row['max_quantity'] === null ? null : (int) $row['max_quantity'];
    $step = (int) ($row['quantity_step'] ?? 1);
    $targetRules = $target;
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        <nav class="mb-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground" aria-label="Breadcrumb">
            <a href="{{ route('social-boost.index', [], false) }}" class="link">Social Boost</a>
            <x-icon name="chevron-right" class="h-3 w-3" />
            <a href="{{ route('social-boost.catalogue', [], false) }}" class="link">Services</a>
            <x-icon name="chevron-right" class="h-3 w-3" />
            <span class="truncate">{{ $row['name'] }}</span>
        </nav>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8" x-data="boostForm({
            min: {{ $min }},
            max: {{ $max ?? 'null' }},
            step: {{ $step }},
            previewUrl: '{{ route('social-boost.price-preview', [], false) }}',
            service: '{{ $row['key'] }}'
        })">

            {{-- ===================== The form ===================== --}}
            <div class="lg:col-span-2">
                <form id="boostForm"
                      method="POST"
                      action="{{ route('social-boost.review', [], false) }}"
                      class="card"
                      @submit="onSubmit($event)">
                    @csrf

                    <input type="hidden" name="service" value="{{ $row['key'] }}">

                    <div class="card-header">
                        <h2 class="card-title">{{ $row['name'] }}</h2>
                        <p class="card-description">
                            {{ $row['platform_label'] }} &middot; {{ $row['type'] }}
                            @if($row['supports_refill']) &middot; refill available @endif
                        </p>
                    </div>

                    <div class="card-content space-y-6 md:p-6">

                        {{-- ---- Description and requirement ---------- --}}
                        @if($row['description'])
                            <div class="rounded-xl border border-border bg-ink-50 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">About this service</p>
                                <p class="mt-2 text-sm">{{ $row['description'] }}</p>
                            </div>
                        @endif

                        @if($row['requirement'])
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4" role="note">
                                <p class="flex items-center gap-2 text-sm font-semibold text-amber-900">
                                    <x-icon name="exclamation-triangle" variant="solid" class="h-4 w-4" />
                                    Before you order
                                </p>
                                <p class="mt-1.5 text-sm text-amber-900">{{ $row['requirement'] }}</p>
                                <p class="mt-1.5 text-xs text-amber-800">
                                    Orders that do not meet this cannot be delivered, and are refunded rather than delivered.
                                </p>
                            </div>
                        @endif

                        {{-- ---- Target ---------- --}}
                        <div>
                            <label for="target" class="label">{{ $targetRules['label'] }}</label>
                            <input type="text"
                                   id="target"
                                   name="target"
                                   class="input mt-1.5"
                                   placeholder="{{ $targetRules['hint'] }}"
                                   value="{{ old('target') }}"
                                   required
                                   autocomplete="off"
                                   autocapitalize="off"
                                   spellcheck="false"
                                   inputmode="{{ $targetRules['accepts_username'] ? 'text' : 'url' }}"
                                   @input="onTargetInput($event)">
                            @error('target')
                                <p class="field-error">{{ $message }}</p>
                            @else
                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    For example: <span class="font-mono">{{ $targetRules['hint'] }}</span>
                                </p>
                            @enderror
                            <p class="field-error" x-show="targetError" x-cloak x-text="targetError"></p>
                        </div>

                        {{-- ---- Quantity ---------- --}}
                        <div>
                            <label for="quantity" class="label">Quantity</label>
                            <div class="mt-1.5 flex flex-wrap gap-2">
                                <input type="text"
                                       id="quantity"
                                       name="quantity"
                                       class="input flex-1"
                                       inputmode="numeric"
                                       autocomplete="off"
                                       value="{{ old('quantity', $prefill['quantity']) }}"
                                       required
                                       @input="onQuantityInput($event)">
                                @foreach(array_values(array_unique(array_filter([$min, $min * 2 < ($max ?? PHP_INT_MAX) ? $min * 2 : null, $min * 5 < ($max ?? PHP_INT_MAX) ? $min * 5 : null]))) as $preset)
                                    <button type="button"
                                            class="btn btn-outline btn-sm"
                                            data-quantity="{{ $preset }}"
                                            @click="setQuantity({{ $preset }})">
                                        {{ number_format($preset) }}
                                    </button>
                                @endforeach
                            </div>
                            @error('quantity')
                                <p class="field-error">{{ $message }}</p>
                            @else
                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    Minimum {{ $row['min_quantity_label'] }}@if($row['max_quantity_label']) &middot; maximum {{ $row['max_quantity_label'] }}@endif.
                                </p>
                            @enderror
                            <p class="field-error" x-show="quantityError" x-cloak x-text="quantityError"></p>
                        </div>

                        {{-- ---- Service-type specific fields ----------
                             Only the fields this service's type actually uses are
                             rendered, and the server filters them again on the way
                             out. A field Nitro does not expect for this service is
                             not sent at all. --}}
                        @if(in_array(strtolower((string) $row['service_type']), ['custom comments'], true))
                            <div>
                                <label for="comments" class="label">Comments</label>
                                <textarea id="comments" name="comments" rows="5" class="input mt-1.5"
                                          placeholder="One comment per line"
                                          required>{{ old('comments') }}</textarea>
                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    One comment per line. The quantity is the number of lines you enter.
                                </p>
                            </div>
                        @endif

                        @if(in_array(strtolower((string) $row['service_type']), ['mentions'], true))
                            <div>
                                <label for="usernames" class="label">Usernames</label>
                                <textarea id="usernames" name="usernames" rows="4" class="input mt-1.5"
                                          placeholder="One username per line">{{ old('usernames') }}</textarea>
                            </div>
                        @endif

                        @if(in_array(strtolower((string) $row['service_type']), ['seo'], true))
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="keywords" class="label">Keywords</label>
                                    <textarea id="keywords" name="keywords" rows="3" class="input mt-1.5"
                                              placeholder="One search term per line">{{ old('keywords') }}</textarea>
                                </div>
                                <div>
                                    <label for="country" class="label">Country</label>
                                    <input type="text" id="country" name="country" class="input mt-1.5"
                                           placeholder="WW" maxlength="3" value="{{ old('country', 'WW') }}">
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Two-letter code, <span class="font-mono">WW</span> for worldwide, or a continent.
                                    </p>
                                </div>
                            </div>
                        @endif

                        {{-- ---- Transaction PIN ---------- --}}
                        @if($hasPin ?? true)
                            <div>
                                <label for="pin" class="label">Transaction PIN</label>
                                <input type="password" id="pin" name="pin" class="input mt-1.5"
                                       inputmode="numeric" maxlength="4" pattern="[0-9]{4}"
                                       autocomplete="off" required>
                                @error('pin')
                                    <p class="field-error">{{ $message }}</p>
                                @else
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Your four-digit PIN authorises the wallet debit.
                                    </p>
                                @enderror
                            </div>
                        @endif
                    </div>

                    <div class="card-footer flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <a href="{{ route('social-boost.catalogue', [], false) }}" class="btn btn-outline">Back to services</a>
                        <button type="submit" class="btn btn-primary">
                            Review order
                            <x-icon name="arrow-right" class="h-4 w-4" />
                        </button>
                    </div>
                </form>
            </div>

            {{-- ===================== Sticky summary ===================== --}}
            <aside class="lg:col-span-1">
                <div class="lg:sticky lg:top-6 space-y-4">
                    <div class="card p-4 md:p-5">
                        <h2 class="text-sm font-semibold">Order summary</h2>

                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground">Service</dt>
                                <dd class="text-right font-medium">{{ $row['name'] }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground">Platform</dt>
                                <dd class="text-right font-medium">{{ $row['platform_label'] }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-muted-foreground">Quantity</dt>
                                <dd class="text-right font-medium tabular-nums" x-text="quantityLabel">—</dd>
                            </div>
                        </dl>

                        <div class="mt-4 border-t border-border pt-4">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-medium">Estimated total</span>
                                <span class="text-xl font-semibold tabular-nums">
                                    <template x-if="estimate !== null">
                                        <span x-text="money(estimate)"></span>
                                    </template>
                                    <template x-if="estimate === null">
                                        <span class="text-base font-normal text-muted-foreground">—</span>
                                    </template>
                                </span>
                            </div>

                            {{-- The estimate is a preview. Saying so is the honest
                                 thing: the authoritative figure is computed on the
                                 next step, from the catalogue, immediately before
                                 anything is charged. --}}
                            <p class="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
                                <x-icon name="information-circle" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>
                                    An estimate based on the current rate. The exact price is confirmed on the
                                    next step before you pay.
                                </span>
                            </p>

                            <p class="mt-2 text-xs text-muted-foreground" x-show="previewFailed" x-cloak>
                                We could not refresh the estimate. Enter a quantity and continue — the price is
                                confirmed on the next step.
                            </p>
                        </div>

                        {{-- Loading skeleton, so a slow estimate reads as work
                             in progress rather than as a missing figure. --}}
                        <div class="mt-3 space-y-2" x-show="loading" x-cloak aria-hidden="true">
                            <div class="h-3 w-2/3 animate-pulse rounded bg-ink-100"></div>
                            <div class="h-3 w-1/2 animate-pulse rounded bg-ink-100"></div>
                        </div>
                    </div>

                    <div class="card p-4 md:p-5">
                        <h2 class="text-sm font-semibold">Delivery</h2>
                        <ul class="mt-3 space-y-2 text-xs text-muted-foreground">
                            <li class="flex items-start gap-2">
                                <x-icon name="check-circle" variant="solid" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600" />
                                <span>Starts within minutes of payment.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <x-icon name="check-circle" variant="solid" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600" />
                                <span>Your account must be public while the order runs.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <x-icon name="check-circle" variant="solid" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600" />
                                <span>Undelivered orders are refunded automatically.</span>
                            </li>
                        </ul>
                        <a href="{{ route('social-boost.help', [], false) }}" class="link mt-3 inline-block text-xs font-medium">
                            Read the full guide
                        </a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

@push('scripts')
<script>
/*
 * The configuration form's behaviour.
 *
 * Everything here is presentation: validating the quantity against the bounds
 * the server already knows, and asking the server what the order would cost so
 * the customer is not surprised on the next screen. **No figure computed here is
 * ever charged.** The review step recomputes from the catalogue and the purchase
 * recomputes again, so a tampered `estimate` changes nothing but what the
 * browser believes.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('boostForm', (config) => ({
        min: Number(config.min || 1),
        max: config.max === null ? null : Number(config.max),
        step: Number(config.step || 1),

        quantity: '',
        estimate: null,
        loading: false,
        previewFailed: false,
        targetError: '',
        quantityError: '',

        init() {
            const field = document.getElementById('quantity');
            this.quantity = String(field ? field.value : this.min);
            this.refresh();
        },

        get quantityLabel() {
            const value = Number(String(this.quantity).replace(/[^0-9]/g, ''));

            return value > 0 ? value.toLocaleString('en-NG') : '—';
        },

        money(value) {
            return '\u20A6' + Number(value || 0).toLocaleString('en-NG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        onTargetInput(event) {
            this.targetError = '';

            // Only the obvious mistake is corrected in place: leading and
            // trailing whitespace from a paste. Anything else is left for the
            // server, because a client that rewrites a URL can turn a valid
            // target into an invalid one.
            const cleaned = String(event.target.value || '').trim();

            if (event.target.value !== cleaned) {
                event.target.value = cleaned;
            }
        },

        onQuantityInput(event) {
            this.quantityError = '';

            const digits = String(event.target.value || '').replace(/[^0-9]/g, '');

            if (event.target.value !== digits) {
                event.target.value = digits;
            }

            this.quantity = digits;

            // Checked here so the customer is told while typing rather than on
            // submit — the server re-checks, and this is not the control.
            const value = Number(digits);

            if (digits === '') {
                this.quantityError = 'Enter how many you want.';
                this.estimate = null;

                return;
            }

            if (value < this.min) {
                this.quantityError = `The minimum for this service is ${this.min.toLocaleString('en-NG')}.`;
                this.estimate = null;

                return;
            }

            if (this.max !== null && value > this.max) {
                this.quantityError = `The maximum for this service is ${this.max.toLocaleString('en-NG')}.`;
                this.estimate = null;

                return;
            }

            this.refresh();
        },

        setQuantity(value) {
            const field = document.getElementById('quantity');

            if (field) {
                field.value = String(value);
            }

            this.quantity = String(value);
            this.quantityError = '';
            this.refresh();
        },

        /*
         * Ask the server what this order would cost.
         *
         * Debounced, because a customer typing "10000" would otherwise make five
         * requests — and the endpoint prices a service through the engine each
         * time. The timeout is short enough to feel immediate.
         */
        refresh() {
            const value = Number(String(this.quantity).replace(/[^0-9]/g, ''));

            if (!value || value < this.min || (this.max !== null && value > this.max)) {
                return;
            }

            clearTimeout(this.timer);

            this.timer = setTimeout(() => this.fetchEstimate(value), 250);
        },

        async fetchEstimate(value) {
            this.loading = true;
            this.previewFailed = false;

            try {
                const url = new URL(config.previewUrl, window.location.origin);
                url.searchParams.set('service', config.service);
                url.searchParams.set('quantity', String(value));

                const response = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Preview responded with ' + response.status);
                }

                const payload = await response.json();

                // A service the engine refuses has no price to show; the form
                // still submits and the review step explains it properly.
                this.estimate = payload.sellable ? Number(payload.price) : null;
            } catch (error) {
                console.error('Price preview failed:', error);

                this.estimate = null;
                this.previewFailed = true;
            } finally {
                this.loading = false;
            }
        },

        onSubmit(event) {
            const field = document.getElementById('quantity');
            const value = Number(String(field ? field.value : '').replace(/[^0-9]/g, ''));

            if (!value || value < this.min || (this.max !== null && value > this.max)) {
                event.preventDefault();
                this.onQuantityInput({ target: field });

                return;
            }

            const target = document.getElementById('target');

            if (!target || !String(target.value || '').trim()) {
                event.preventDefault();
                this.targetError = 'Enter the link or username you want boosted.';
            }
        },
    }));
});
</script>
@endpush
@endsection

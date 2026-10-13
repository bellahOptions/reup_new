@extends('layouts.app')
@section('title', 'Buy Airtime & Data')
@section('content')
@php
    /*
     * The marquee normalises announcement rows itself — including translating
     * legacy emoji icons to icon names — so this view just forwards the models.
     * It used to carry its own emoji→icon table, which is why the icon rendered
     * on this page and nowhere else.
     */
    $announcementItems = $announcements ?? collect();

    /*
     * Network marks, shared by the airtime and data forms.
     *
     * Built from `NetworkBrand`, which owns the label, the asset and the alias
     * table. This view previously read `config('bills.networks')` itself and
     * keyed it by ClubKonnect's numeric code, so it resolved codes one way and
     * the pricelist resolved them another — the duplication that let the real
     * brand images sit unused while placeholder monograms were rendered.
     */
    $networkOptions = collect(config('networks.networks', []))
        ->map(function ($network, $code) {
            // The provider code for this canonical key, so the radio a customer
            // selects still posts the code the server validates against.
            $providerCode = array_search($code, (array) config('networks.provider_codes.clubkonnect', []), true);

            return [
                'code' => $providerCode === false ? null : (string) $providerCode,
                'key' => $code,
                'name' => \App\Services\NetworkBrand::label($code) ?? $code,
            ];
        })
        ->filter(fn ($network) => $network['code'] !== null)
        ->values()
        ->all();

    $dataPlanFilters = ['all' => 'All plans', 'SME' => 'SME', 'Awoof Data' => 'Awoof data', 'Direct Data' => 'Direct data', 'Night Plan' => 'Night plans'];

    // Kept for the footer copy.
    $tipItems = [
        'Instant delivery within seconds',
        'Best rates guaranteed',
        'Support available around the clock',
        'Secure, wallet-funded transactions',
    ];
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-12">

        {{-- Page heading --}}
        <header class="mb-8 md:mb-10">
            <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Buy airtime &amp; data</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                Instant recharge for every Nigerian network, paid straight from your wallet.
            </p>
        </header>

        {{-- Announcements --}}
        @if(!empty($announcementItems))
            <div class="card mb-8">
                <div class="flex items-center gap-3 border-b border-border px-5 py-3">
                    <x-icon name="megaphone" class="h-4 w-4 text-brand-600" />
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">Announcements</span>
                </div>
                <div class="px-2 py-1">
                    <x-marquee :items="$announcementItems" speed="40" :pauseOnHover="true" compact />
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8" x-data="{ service: 'airtime' }">

            {{-- Purchase forms --}}
            <div class="space-y-6 lg:col-span-2">
                <div class="card overflow-hidden">
                    {{-- Service toggle --}}
                    <div class="border-b border-border bg-surface p-2">
                        <div class="grid grid-cols-2 gap-2" role="tablist" aria-label="Choose a service">
                            <button type="button"
                                    role="tab"
                                    id="airtimeBtn"
                                    :aria-selected="service === 'airtime' ? 'true' : 'false'"
                                    @click="service = 'airtime'"
                                    :class="service === 'airtime' ? 'bg-brand-500 text-primary-foreground shadow-subtle' : 'text-muted-foreground hover:bg-ink-100'"
                                    class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-colors">
                                <x-icon name="phone" class="h-4 w-4" />
                                Airtime
                            </button>
                            <button type="button"
                                    role="tab"
                                    id="dataBtn"
                                    :aria-selected="service === 'data' ? 'true' : 'false'"
                                    @click="service = 'data'"
                                    data-service-tab="data"
                                    :class="service === 'data' ? 'bg-brand-500 text-primary-foreground shadow-subtle' : 'text-muted-foreground hover:bg-ink-100'"
                                    class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-colors">
                                <x-icon name="wifi" class="h-4 w-4" />
                                Data
                            </button>
                        </div>
                    </div>

                    <div class="card-content md:p-8">
                        {{-- ================= Airtime ================= --}}
                        <form id="airtimeForm" action="{{ route('airtime.purchase', [], false) }}" method="POST"
                              data-loading-text="Sending airtime&hellip;"
                              class="space-y-6" x-show="service === 'airtime'">
                            @csrf

                            <div>
                                <span class="label mb-3 block">Select network</span>
                                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                                    @foreach($networkOptions as $network)
                                        <label class="cursor-pointer">
                                            <input type="radio" name="network" value="{{ $network['code'] }}"
                                                   class="network-radio sr-only" required>
                                            <span class="network-option flex flex-col items-center gap-2 rounded-xl border border-border bg-surface p-4 text-center transition-colors hover:border-brand-300">
                                                <x-network-logo :network="$network['key']" />
                                                <span class="text-sm font-semibold">{{ $network['name'] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <label for="phone" class="label">Phone number</label>
                                <input type="tel" id="phone" name="phone" placeholder="08012345678"
                                       class="input mt-1.5" required maxlength="11" pattern="[0-9]{11}"
                                       inputmode="numeric" autocomplete="tel">
                                <p class="mt-1.5 text-xs text-muted-foreground">The number that should receive the top-up.</p>
                            </div>

                            <div>
                                <label for="amount" class="label">Amount</label>
                                <div class="relative mt-1.5">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm font-medium text-muted-foreground">&#8358;</span>
                                    <input type="number" id="amount" name="amount" placeholder="1000" min="100" max="10000"
                                           class="input pl-9" required inputmode="numeric">
                                </div>
                                <div class="mt-2 space-y-1">
                                    <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <x-icon name="information-circle" class="h-3.5 w-3.5" />
                                        Minimum &#8358;100 &middot; maximum &#8358;10,000
                                    </p>
                                    {{--
                                        No service-fee notice. There is no customer fee on
                                        airtime: ₦1,000 of airtime costs ₦1,000. This block
                                        previously warned of a 2% fee, which both described
                                        and reinforced the charge being removed here.
                                    --}}
                                </div>
                            </div>

                            <div>
                                <span class="label mb-3 block">Quick select</span>
                                <div class="grid grid-cols-3 gap-2 md:grid-cols-5">
                                    @foreach([100 => '100', 200 => '200', 500 => '500', 1000 => '1K', 2000 => '2K'] as $value => $label)
                                        <button type="button"
                                                class="quick-amount rounded-lg border border-border bg-surface py-2 px-3 text-sm font-semibold transition-colors hover:border-brand-400 hover:bg-accent"
                                                data-amount="{{ $value }}">
                                            &#8358;{{ $label }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>

            
                            {{-- How to pay. Above the PIN, because the choice decides
                                 whether a PIN is asked for at all. --}}
                            <x-payment-method :wallet="$wallet->balance ?? 0" />

                {{-- PIN is verified server-side before the debit; the key makes a
                     double submit idempotent. Not required on the card rail — see
                     the component. --}}
                <x-transaction-pin id="airtime-pin" />

                <input type="hidden" name="idempotency_key" value="{{ Str::random(32) }}">
                <button type="submit" class="btn btn-primary btn-lg w-full">
                                <span>Buy Airtime Now</span>
                                <x-icon name="rocket-launch" class="h-5 w-5" />
                            </button>
                        </form>

                        {{-- ================= Data ================= --}}
                        <form id="dataForm" action="{{ route('data.purchase', [], false) }}" method="POST"
                              data-loading-text="Sending data&hellip;"
                              class="space-y-6" x-show="service === 'data'" x-cloak>
                            @csrf

                            <div>
                                <span class="label mb-3 block">Select network</span>
                                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                                    @foreach($networkOptions as $network)
                                        <label class="cursor-pointer">
                                            <input type="radio" name="data_network" value="{{ $network['code'] }}"
                                                   class="network-radio-data sr-only" required>
                                            <span class="network-option flex flex-col items-center gap-2 rounded-xl border border-border bg-surface p-4 text-center transition-colors hover:border-brand-300">
                                                <x-network-logo :network="$network['key']" />
                                                <span class="text-sm font-semibold">{{ $network['name'] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <span class="label mb-2 block">Filter by type</span>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($dataPlanFilters as $type => $label)
                                        <button type="button"
                                                class="data-type-filter rounded-lg border border-border bg-surface px-4 py-2 text-sm font-medium transition-colors hover:bg-ink-50 {{ $type === 'all' ? 'active' : '' }}"
                                                data-type="{{ $type }}">
                                            {{ $label }}
                                        </button>
                                    @endforeach
                                </div>

                                <div class="mt-4">
                                    <label for="data_plan" class="label">Data plan</label>
                                    <div id="dataPlanLoader" class="hidden items-center gap-2 py-3 text-sm text-muted-foreground">
                                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-brand-500 border-t-transparent"></span>
                                        Loading plans&hellip;
                                    </div>
                                    <select id="data_plan" name="data_plan" class="select mt-1.5" required disabled>
                                        <option value="">Select network first</option>
                                    </select>
                                </div>

                                {{-- The price is deliberately not posted as a
                                     source of truth: the server re-resolves
                                     what this bundle costs from the catalogue
                                     when the form arrives.

                                     `plan_price` is carried so the server can
                                     tell that the customer's page is stale and
                                     answer with a price-change confirmation
                                     rather than charging a figure nobody agreed
                                     to. It is never the amount charged. --}}
                                <input type="hidden" id="plan_name" name="plan_name">
                                <input type="hidden" id="plan_price" name="plan_price">
                                <input type="hidden" id="plan_type" name="plan_type">
                                <input type="hidden" id="price_confirmation" name="price_confirmation" value="">

                                <p id="planStaleNotice" class="mt-2 hidden items-center gap-1.5 text-xs text-amber-700">
                                    <x-icon name="exclamation-triangle" class="h-3.5 w-3.5" />
                                    <span>These prices may be out of date. They refresh automatically.</span>
                                </p>
                            </div>

                            <div>
                                <label for="data_phone" class="label">Phone number</label>
                                <input type="tel" id="data_phone" name="phone" placeholder="08012345678"
                                       class="input mt-1.5" required maxlength="11" pattern="[0-9]{11}"
                                       inputmode="numeric" autocomplete="tel">
                            </div>

                            <div id="planSummary" class="hidden rounded-xl border border-brand-200 bg-accent p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-accent-foreground">Selected plan</p>
                                        <p id="selectedPlanName" class="mt-1 text-sm text-accent-foreground"></p>
                                        <p id="selectedPlanType" class="mt-0.5 text-xs text-accent-foreground/80"></p>
                                    </div>
                                    <div class="text-right">
                                        <p id="selectedPlanPrice" class="text-lg font-semibold tabular-nums text-accent-foreground"></p>
                                        <p class="text-xs text-accent-foreground/80">Total amount</p>
                                    </div>
                                </div>
                            </div>

                            {{-- How to pay. Above the PIN, because the choice decides
                                 whether a PIN is asked for at all. --}}
                            <x-payment-method :wallet="$wallet->balance ?? 0" />

                <x-transaction-pin id="data-pin" />

                <input type="hidden" name="idempotency_key" value="{{ Str::random(32) }}">
                <button type="submit" class="btn btn-primary btn-lg w-full">
                                <span>Buy Data Now</span>
                                <x-icon name="wifi" class="h-5 w-5" />
                            </button>
                        </form>

                        <p class="mt-6 text-center text-xs text-muted-foreground">
                            By proceeding you agree to our
                            <a href="#" class="link">Terms of Service</a> and
                            <a href="#" class="link">Privacy Policy</a>.
                        </p>
                    </div>
                </div>
            </div>

            {{-- Sidebar --}}
            <aside class="space-y-6">
                {{-- The single accent surface on this page. --}}
                <div class="rounded-xl border border-brand-600 bg-brand-500 p-5 text-primary-foreground shadow-subtle md:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-brand-50">Wallet balance</p>
                            <p class="mt-2 text-3xl font-semibold tabular-nums">
                                &#8358;{{ number_format((float) ($wallet->balance ?? 0), 2) }}
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
                        <h2 class="card-title">Quick tips</h2>
                    </div>
                    <div class="card-content space-y-3">
                        @foreach($tipItems as $tip)
                            <p class="flex items-start gap-2.5 text-sm text-muted-foreground">
                                <x-icon name="check-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                                {{ $tip }}
                            </p>
                        @endforeach
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Recent transactions</h2>
                    </div>
                    <div class="card-content">
                        @forelse($recentTransactions as $transaction)
                            <div class="flex items-start justify-between gap-3 border-b border-border py-3 first:pt-0 last:border-0 last:pb-0">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ ucfirst((string) ($transaction->service_type ?? $transaction->type)) }}
                                    </p>
                                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                                        {{ $transaction->recipient ?: 'No recipient' }}
                                    </p>
                                </div>
                                <span class="shrink-0 text-sm font-semibold tabular-nums {{ $transaction->type === 'credit' ? 'text-green-700' : 'text-foreground' }}">
                                    {{ $transaction->type === 'credit' ? '+' : '-' }}&#8358;{{ number_format((float) $transaction->amount, 2) }}
                                </span>
                            </div>
                        @empty
                            <div class="empty-state py-8">
                                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                                    <x-icon name="inbox" class="h-5 w-5" />
                                </span>
                                <p class="mt-3 text-sm text-muted-foreground">No recent transactions</p>
                            </div>
                        @endforelse
                    </div>
                    @if($recentTransactions->count())
                        <div class="card-footer">
                            <a href="{{ route('transactions.index') }}" class="link text-sm font-medium">
                                View all transactions
                            </a>
                        </div>
                    @endif
                </div>
            </aside>
        </div>
        {{-- ================= Price-change confirmation =================
             Shown when the backend answers a data submission with a changed
             price. The figures, the bundle's identity and the confirmation token
             all come from that response — nothing here is hardcoded, and nothing
             here is submitted as a price.

             `x-cloak` so an unfired modal cannot flash on load, and the panel is
             a real focus target with `role="dialog"` and `aria-modal` so a screen
             reader announces it as the decision it is. --}}
        <div x-data="priceChange()"
             x-cloak
             x-show="open"
             x-effect="document.body.classList.toggle('overflow-hidden', open)"
             @keydown.escape.window="cancel()"
             class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
            <div class="absolute inset-0 bg-ink-900/50" @click="cancel()" aria-hidden="true"></div>

            <div role="dialog"
                 aria-modal="true"
                 aria-labelledby="priceChangeTitle"
                 x-ref="panel"
                 tabindex="-1"
                 class="relative w-full max-w-md rounded-xl border border-border bg-surface p-5 shadow-lg sm:p-6">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                        <x-icon name="receipt-percent" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="priceChangeTitle" class="text-base font-semibold">Data bundle price updated</h2>
                        <p class="mt-1 text-sm text-muted-foreground" x-text="message"></p>
                    </div>
                </div>

                <dl class="mt-4 space-y-2 rounded-lg border border-border bg-ink-50 p-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground">Bundle</dt>
                        <dd class="truncate font-medium" x-text="product.plan_name || '—'"></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground">Network</dt>
                        <dd class="font-medium" x-text="product.network || '—'"></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground">Previous price</dt>
                        <dd class="tabular-nums line-through" x-text="previousPrice"></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="font-medium">New price</dt>
                        <dd class="text-base font-semibold tabular-nums" x-text="newPrice"></dd>
                    </div>
                </dl>

                {{-- A price that has gone up may no longer be affordable, and the
                     customer should learn that here rather than after accepting. --}}
                <p class="mt-3 text-xs text-muted-foreground" x-show="balance" x-cloak>
                    Wallet balance: <span class="font-medium tabular-nums" x-text="balance"></span>
                    <template x-if="!affordable">
                        <span class="text-red-700"> — not enough for the new price.</span>
                    </template>
                </p>

                <p class="mt-3 text-xs text-muted-foreground">
                    Nothing has been charged. This price is held for
                    <span x-text="expiresIn"></span>.
                </p>

                <p class="field-error mt-3" x-show="error" x-cloak x-text="error"></p>

                <div class="mt-5 flex flex-col gap-2 sm:flex-row-reverse">
                    <button type="button"
                            class="btn btn-primary w-full sm:w-auto"
                            :disabled="busy"
                            :class="busy ? 'opacity-60 cursor-not-allowed' : ''"
                            @click="confirm()">
                        <span x-show="!busy">Continue at new price</span>
                        <span x-show="busy" x-cloak class="inline-flex items-center gap-2">
                            <span class="h-4 w-4 animate-spin rounded-full border-2 border-primary-foreground border-t-transparent"></span>
                            Confirming…
                        </span>
                    </button>
                    <button type="button"
                            class="btn btn-outline w-full sm:w-auto"
                            :disabled="busy"
                            @click="cancel()">
                        Cancel transaction
                    </button>
                </div>
            </div>
        </div>
        {{-- ================= No-JavaScript price confirmation =================
             The modal below needs JavaScript. This is the same decision in a
             plain form, so a customer on a degraded connection is not locked out
             of their own purchase.

             The token is the only thing this posts. The figures come from the
             server's own flash payload — the browser is told what to display,
             never what to charge. --}}
        @if(session('price_change'))
            @php $change = session('price_change'); @endphp
            <div class="container-page pb-6">
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4" role="status">
                    <h2 class="text-sm font-semibold text-amber-900">Data bundle price updated</h2>
                    <p class="mt-1 text-sm text-amber-900">{{ $change['message'] }}</p>

                    <dl class="mt-3 space-y-1 text-sm text-amber-900">
                        <div class="flex justify-between gap-4">
                            <dt>Bundle</dt>
                            <dd class="font-medium">{{ $change['product']['plan_name'] ?? '—' }}</dd>
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

                    <p class="mt-2 text-xs text-amber-800">Nothing has been charged. Your selection is kept.</p>

                    {{-- A real form, so the decision is takeable without any
                         JavaScript. The form fields the customer already filled in
                         are restored by `withInput()`, so this posts the same
                         purchase they submitted with the confirmation attached. --}}
                    <form method="POST"
                          action="{{ route('data.purchase', [], false) }}"
                          class="mt-3 flex flex-col gap-2 sm:flex-row">
                        @csrf
                        <input type="hidden" name="data_network" value="{{ old('data_network') }}">
                        <input type="hidden" name="data_plan" value="{{ old('data_plan') }}">
                        <input type="hidden" name="phone" value="{{ old('phone') }}">
                        <input type="hidden" name="plan_name" value="{{ old('plan_name') }}">
                        <input type="hidden" name="plan_price" value="{{ old('plan_price') }}">
                        <input type="hidden" name="plan_type" value="{{ old('plan_type') }}">
                        <input type="hidden" name="payment_method" value="{{ old('payment_method', 'wallet') }}">
                        <input type="hidden" name="pin" value="{{ old('pin') }}">
                        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key') }}">
                        <input type="hidden" name="price_confirmation" value="{{ $change['quote']['identifier'] }}">

                        <button type="submit" class="btn btn-primary btn-sm">Continue at {{ $change['new_price_formatted'] }}</button>
                        <a href="{{ route('airtime-data.index') }}" class="btn btn-outline btn-sm">Cancel transaction</a>
                    </form>
                </div>
            </div>
        @endif

    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const airtimeForm = document.getElementById('airtimeForm');
    const dataForm = document.getElementById('dataForm');
    const amountInput = document.getElementById('amount');
    const quickAmounts = document.querySelectorAll('.quick-amount');
    const dataPlanSelect = document.getElementById('data_plan');
    const dataPlanLoader = document.getElementById('dataPlanLoader');
    const dataNetworkRadios = document.querySelectorAll('.network-radio-data');
    const planNameInput = document.getElementById('plan_name');
    const planPriceInput = document.getElementById('plan_price');
    const planTypeInput = document.getElementById('plan_type');
    const priceConfirmationInput = document.getElementById('price_confirmation');
    const staleNotice = document.getElementById('planStaleNotice');
    const dataTypeFilters = document.querySelectorAll('.data-type-filter');

    /*
     * The frontend refresh interval, from configuration.
     *
     * Inlined from `config('pricing.catalogue.frontend_refresh_seconds')` rather
     * than hardcoded, and refreshed from the API each time the plans are reloaded,
     * so one setting controls it. It defaults to the same value the API reports,
     * for the case where the very first load happens before any API response.
     */
    let planRefreshSeconds = {{ max(30, (int) config('pricing.catalogue.frontend_refresh_seconds', 300)) }};

    // Get user's wallet balance
    const walletBalance = {{ (float) ($wallet->balance ?? 0) }};
    /*
     * No client-side fee.
     *
     * This was `const serviceFeeRate = 0.02` and every balance check below added
     * 2% on top of the amount. The browser's idea of the total and the server's had
     * to agree, so a fee removed on the server but left here would have told a
     * customer with exactly ₦1,000 that they could not afford ₦1,000 of airtime.
     *
     * The total is now the amount, which is what the server charges.
     */

    // Store fetched data plans
    let fetchedDataPlans = [];
    let currentFilter = 'all';
    let currentNetworkId = '';

    /*
     * Which rail the customer chose, read from the form control itself.
     *
     * Read live rather than cached, and from the control rather than from a mirrored
     * flag: the select is the thing that decides what the server charges, so anything
     * that gates a submission on the rail has to read the same value the server will.
     */
    function payingByCard() {
        return document.querySelector('[name="payment_method"]')?.value === 'paystack';
    }

    /*
     * Undo the balance gate: remove the warning panel and re-enable the button.
     *
     * Shared by both forms because both had their own copy of the same six lines, and
     * the copy is where the card-rail guard would have been forgotten.
     */
    function clearBalanceGate(form, warningId, label) {
        document.getElementById(warningId)?.remove();

        const submitBtn = form?.querySelector('button[type="submit"]');

        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            submitBtn.querySelector('span:first-child').textContent = label;
        }
    }

    /*
     * Re-evaluate the gate whenever the rail changes.
     *
     * Choosing "Card or bank transfer" has to clear a warning the wallet rail put
     * there, and choosing the wallet again has to bring it back — otherwise the
     * button stays dead (or stays live when it should not) until something else
     * happens to re-run the check.
     */
    document.querySelectorAll('[name="payment_method"]').forEach(rail => {
        rail.addEventListener('change', function () {
            if (this.value === 'paystack') {
                clearBalanceGate(airtimeForm, 'airtimeBalanceWarning', 'Buy Airtime Now');
                clearBalanceGate(dataForm, 'dataBalanceWarning', 'Buy Data Now');

                return;
            }

            const airtimeAmount = parseFloat(amountInput?.value) || 0;
            const dataAmount = parseFloat(planPriceInput?.value) || 0;

            if (airtimeAmount > 0) {
                checkAirtimeBalance(airtimeAmount);
            }

            if (dataAmount > 0) {
                checkDataBalance(dataAmount);
            }
        });
    });

    // Panel visibility and the toggle's pressed styling are owned by Alpine
    // (see x-data on the page grid); the ids are kept for the balance checks
    // below. The old script toggled a `.hidden` class that Alpine would have
    // written back on the next reactive update.

    // Quick amount selection with balance check
    quickAmounts.forEach(btn => {
        btn.addEventListener('click', () => {
            const amount = parseFloat(btn.getAttribute('data-amount'));
            amountInput.value = amount;
            checkAirtimeBalance(amount);
        });
    });

    // Check balance on amount input
    amountInput.addEventListener('input', function() {
        const amount = parseFloat(this.value) || 0;
        checkAirtimeBalance(amount);
    });

    // Function to check airtime balance
    function checkAirtimeBalance(amount) {
        /*
         * The wallet balance only limits a purchase paid *from the wallet*.
         *
         * Without this guard the page disabled its own submit button — and replaced
         * its label with "Insufficient Balance" — the moment an amount exceeded the
         * balance, even with "Card or bank transfer" selected. A customer with an
         * empty wallet could choose the card rail and find the button dead, which made
         * the card rail unusable for exactly the customers it exists for.
         */
        if (payingByCard()) {
            clearBalanceGate(airtimeForm, 'airtimeBalanceWarning', 'Buy Airtime Now');

            return;
        }

        // The customer pays the amount. No fee is added, so the total is the amount.
        const totalAmount = amount;

        const submitBtn = airtimeForm.querySelector('button[type="submit"]');
        const balanceWarning = document.getElementById('airtimeBalanceWarning');

        if (totalAmount > walletBalance) {
            const shortage = totalAmount - walletBalance;

            // Show warning
            if (!balanceWarning) {
                const warning = document.createElement('div');
                warning.id = 'airtimeBalanceWarning';
                warning.className = 'mt-3 rounded-xl border border-red-200 bg-red-50 p-3';
                warning.innerHTML = `
                    <div class="flex items-start gap-2.5">
                        <x-icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                        <div>
                            <p class="text-sm font-semibold text-red-800">Insufficient balance</p>
                            <p class="mt-1 text-xs text-red-700">
                                Total needed: <strong>₦${totalAmount.toFixed(2)}</strong>
                            </p>
                            <p class="text-xs text-red-700">
                                Your balance: <strong>₦${walletBalance.toFixed(2)}</strong>
                                &middot; short by <strong>₦${shortage.toFixed(2)}</strong>
                            </p>
                            <a href="{{ route('wallet.fund') }}" class="mt-2 inline-block text-xs font-semibold text-red-700 underline">
                                Fund wallet
                            </a>
                        </div>
                    </div>
                `;
                amountInput.parentElement.parentElement.appendChild(warning);
            }

            // Disable submit button
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            submitBtn.querySelector('span:first-child').textContent = 'Insufficient Balance';
        } else {
            // Remove warning
            if (balanceWarning) {
                balanceWarning.remove();
            }

            // Enable submit button
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            submitBtn.querySelector('span:first-child').textContent = 'Buy Airtime Now';
        }
    }

    /**
     * Rebuild the plan select from the catalogue.
     *
     * `previousPlan` is the `plan_id` that was selected before this rebuild. It is
     * restored when it still exists, and the bundle's *new* price is written into
     * the summary and the hidden input — so a refresh updates the figure the
     * customer is looking at without losing which bundle they were looking at.
     * That is the whole point: a price change is information, not a reason to make
     * somebody start again.
     */
    function displayFilteredPlans(previousPlan) {
        dataPlanSelect.innerHTML = '<option value="">Select a data plan</option>';

        let filteredPlans = fetchedDataPlans;

        if (currentFilter !== 'all') {
            filteredPlans = fetchedDataPlans.filter(plan =>
                plan.type.toLowerCase().includes(currentFilter.toLowerCase()) ||
                (currentFilter === 'Night Plan' && plan.type === 'Night Plan')
            );
        }

        filteredPlans.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));

        const groupedPlans = {};
        filteredPlans.forEach(plan => {
            if (!groupedPlans[plan.type]) {
                groupedPlans[plan.type] = [];
            }
            groupedPlans[plan.type].push(plan);
        });

        Object.keys(groupedPlans).sort().forEach(type => {
            const optgroup = document.createElement('optgroup');
            optgroup.label = `${type} Plans`;

            groupedPlans[type].forEach(plan => {
                const option = document.createElement('option');
                option.value = plan.product_id;

                const displayText = `${plan.name} - ₦${plan.price}`;
                option.textContent = displayText;

                option.dataset.planName = plan.name;
                option.dataset.planPrice = plan.price;
                option.dataset.planType = plan.type;
                option.dataset.productCode = plan.product_code;

                optgroup.appendChild(option);
            });

            dataPlanSelect.appendChild(optgroup);
        });

        dataPlanSelect.disabled = filteredPlans.length === 0;

        if (filteredPlans.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No plans available for this filter';
            dataPlanSelect.appendChild(option);
        }

        /*
         * Restore the selection, or clear the summary.
         *
         * `applySelectedPlan()` is what keeps the displayed price, the hidden
         * cross-check field and the balance gate in step — reading the option's
         * own dataset rather than a captured value, so a re-price cannot leave a
         * stale figure on screen.
         */
        const restorable = previousPlan
            && Array.from(dataPlanSelect.options).some(option => option.value === previousPlan);

        if (restorable) {
            dataPlanSelect.value = previousPlan;
            applySelectedPlan(false);
        } else {
            planNameInput.value = '';
            planPriceInput.value = '';
            planTypeInput.value = '';
            priceConfirmationInput.value = '';
        }
    }

    /**
     * Apply the currently selected option to the summary, the hidden fields and
     * the balance gate.
     *
     * `resetConfirmation` clears any price-change token from a previous attempt,
     * because a token belongs to one bundle at one price. Choosing a different
     * bundle must not carry the previous bundle's confirmation with it.
     */
    function applySelectedPlan(resetConfirmation) {
        const selectedOption = dataPlanSelect.options[dataPlanSelect.selectedIndex];

        if (!selectedOption || !selectedOption.value) {
            return;
        }

        const planName = selectedOption.dataset.planName || '';
        const planPrice = parseFloat(selectedOption.dataset.planPrice || 0);
        const planType = selectedOption.dataset.planType || '';

        // Posted for display and for a price cross-check on the server; it is
        // not what the server charges from.
        planNameInput.value = planName;
        planPriceInput.value = planPrice;
        planTypeInput.value = planType;

        if (resetConfirmation !== false) {
            priceConfirmationInput.value = '';
        }

        const planSummary = document.getElementById('planSummary');
        const selectedPlanName = document.getElementById('selectedPlanName');
        const selectedPlanType = document.getElementById('selectedPlanType');
        const selectedPlanPrice = document.getElementById('selectedPlanPrice');

        if (selectedPlanName && selectedPlanType && selectedPlanPrice && planSummary) {
            selectedPlanName.textContent = planName;
            selectedPlanType.textContent = `Type: ${planType}`;
            selectedPlanPrice.textContent = `₦${planPrice.toFixed(2)}`;
            planSummary.classList.remove('hidden');
        }

        checkDataBalance(planPrice);
    }

    // Data type filter buttons
    dataTypeFilters.forEach(filterBtn => {
        filterBtn.addEventListener('click', function() {
            dataTypeFilters.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');

            currentFilter = this.dataset.type;

            if (fetchedDataPlans.length > 0) {
                // The bundle the customer had chosen is kept if the new filter
                // still lists it — filtering is a view of the list, not a reset.
                displayFilteredPlans(dataPlanSelect.value || null);
            }
        });
    });

    /*
     * Load the priced catalogue from this application.
     *
     * The catalogue comes from ReUp, not from the provider. It was previously
     * fetched straight from NelloBytes with the account id in the query string —
     * which published the credential to every visitor — and the markup was
     * applied in the browser, so the price the form posted could be edited with
     * devtools. The server returns the price it will actually charge, and
     * re-resolves it again when the purchase arrives.
     *
     * `preserveSelection` is what makes a *refresh* different from a network
     * change. A refresh must not throw away the network, the bundle, the phone
     * number or the filter the customer has chosen: a price updating underneath
     * them is a reason to update the figure on screen, never a reason to reset
     * the form. Unticking it is the deliberate act of choosing a new network.
     */
    async function loadDataPlans(preserveSelection) {
        const previousPlan = preserveSelection && dataPlanSelect.value ? dataPlanSelect.value : null;

        dataPlanSelect.disabled = true;

        if (!preserveSelection) {
            dataPlanSelect.innerHTML = '<option value="">Loading plans...</option>';
            planNameInput.value = '';
            planPriceInput.value = '';
            planTypeInput.value = '';
            priceConfirmationInput.value = '';
        }

        dataPlanLoader.classList.remove('hidden');
        dataPlanLoader.classList.add('flex');

        try {
            const response = await fetch('{{ route('pricelist.api') }}', {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Plan catalogue responded with ' + response.status);
            }

            const payload = await response.json();

            /*
             * The catalogue's own freshness, so the page can say that a price is
             * old instead of presenting it as current. The server is still the
             * authority on whether a price may be charged — this is disclosure,
             * not a decision.
             */
            staleNotice.classList.toggle('hidden', !(payload.catalogue && payload.catalogue.stale));
            staleNotice.classList.toggle('flex', !!(payload.catalogue && payload.catalogue.stale));

            fetchedDataPlans = (payload.data_plans || [])
                .filter(plan => String(plan.network_code) === String(currentNetworkId))
                .map(plan => ({
                    product_id: String(plan.plan_id ?? ''),
                    name: plan.plan_name || 'Data bundle',
                    price: Number(plan.your_price || 0).toFixed(2),
                    product_code: plan.plan_code || '',
                    network_id: currentNetworkId,
                    type: plan.plan_type || 'Direct Data',
                }))
                .filter(plan => plan.product_id && parseFloat(plan.price) > 0);

            if (!fetchedDataPlans.length) {
                dataPlanSelect.innerHTML = '<option value="">No plans are priced for this network right now</option>';
                return;
            }

            displayFilteredPlans(previousPlan);

        } catch (error) {
            console.error('Error loading data plans:', error);

            /*
             * A failed *refresh* keeps the plans that are already on screen. They
             * are the last prices the server gave us and are still the best
             * information available; emptying the list would turn a transient
             * network blip into "we do not sell this network".
             */
            if (!preserveSelection || !fetchedDataPlans.length) {
                fetchedDataPlans = [];
                dataPlanSelect.innerHTML = '<option value="">We could not load the plans. Please try again.</option>';
            }

            if (window.ReUpFeedback) {
                window.ReUpFeedback.error('We could not refresh the data plans. The prices shown may be slightly out of date.');
            }
        } finally {
            dataPlanLoader.classList.add('hidden');
            dataPlanLoader.classList.remove('flex');
        }
    }

    // Choosing a network is a new context, so nothing is preserved.
    dataNetworkRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            currentNetworkId = this.value;

            dataTypeFilters.forEach(btn => btn.classList.remove('active'));
            document.querySelector('[data-type="all"]').classList.add('active');
            currentFilter = 'all';

            loadDataPlans(false);
        });
    });

    /*
     * Re-read the prices periodically while the page is open.
     *
     * The interval is the *backend's* configured frontend refresh interval, handed
     * to the page by the API, so it is one setting rather than a number in a
     * template. The request goes to this application and never to the provider:
     * the catalogue is refreshed by the scheduled synchronisation, and a browser
     * polling NelloBytes would spend the rate limit that purchases need.
     *
     * It is deliberately a poll rather than a push: there is no websocket
     * infrastructure here, and a price list that is at most one interval old is
     * exactly what the confirmation flow exists to handle.
     */
    let planRefreshTimer = null;

    function scheduleDataPlanRefresh() {
        if (planRefreshTimer) {
            clearInterval(planRefreshTimer);
        }

        planRefreshTimer = setInterval(function() {
            // Nothing to refresh, or the customer is mid-decision.
            if (!currentNetworkId || document.hidden) {
                return;
            }

            loadDataPlans(true);
        }, Math.max(30, planRefreshSeconds) * 1000);
    }

    document.addEventListener('visibilitychange', function() {
        // Coming back to the tab is a good moment to be current, and it is a
        // moment the customer is not waiting on anything.
        if (!document.hidden && currentNetworkId) {
            loadDataPlans(true);
        }
    });

    // When the customer selects a data plan, apply it to the summary and the
    // hidden fields. A new bundle voided any earlier confirmation, so the token
    // is cleared.
    dataPlanSelect.addEventListener('change', function() {
        if (this.value) {
            applySelectedPlan(true);
        } else {
            planNameInput.value = '';
            planPriceInput.value = '';
            planTypeInput.value = '';
            priceConfirmationInput.value = '';

            const planSummary = document.getElementById('planSummary');
            if (planSummary) {
                planSummary.classList.add('hidden');
            }

            // Remove warning
            const balanceWarning = document.getElementById('dataBalanceWarning');
            if (balanceWarning) {
                balanceWarning.remove();
            }
        }
    });

    // Function to check data balance
    function checkDataBalance(amount) {
        /*
         * Same guard as airtime, for the same reason: a card purchase is not limited
         * by the wallet, so the balance must not gate the button on that rail.
         */
        if (payingByCard()) {
            clearBalanceGate(dataForm, 'dataBalanceWarning', 'Buy Data Now');

            return;
        }

        /*
         * The quoted bundle price is the amount the engine charges, so the check is
         * against the plan price alone. The old `+ 2%` here applied the *airtime*
         * fee rate to data as well, which never matched what the server charged.
         */
        const totalAmount = amount;

        const submitBtn = dataForm.querySelector('button[type="submit"]');
        const balanceWarning = document.getElementById('dataBalanceWarning');

        if (totalAmount > walletBalance) {
            const shortage = totalAmount - walletBalance;

            // Show warning
            if (!balanceWarning) {
                const warning = document.createElement('div');
                warning.id = 'dataBalanceWarning';
                warning.className = 'mt-3 rounded-xl border border-red-200 bg-red-50 p-3';
                warning.innerHTML = `
                    <div class="flex items-start gap-2.5">
                        <x-icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                        <div>
                            <p class="text-sm font-semibold text-red-800">Insufficient balance</p>
                            <p class="mt-1 text-xs text-red-700">
                                Total needed: <strong>₦${totalAmount.toFixed(2)}</strong>
                            </p>
                            <p class="text-xs text-red-700">
                                Your balance: <strong>₦${walletBalance.toFixed(2)}</strong>
                                &middot; short by <strong>₦${shortage.toFixed(2)}</strong>
                            </p>
                            <a href="{{ route('wallet.fund') }}" class="mt-2 inline-block text-xs font-semibold text-red-700 underline">
                                Fund wallet
                            </a>
                        </div>
                    </div>
                `;
                dataPlanSelect.parentElement.appendChild(warning);
            }

            // Disable submit button
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            submitBtn.querySelector('span:first-child').textContent = 'Insufficient Balance';
        } else {
            // Remove warning
            if (balanceWarning) {
                balanceWarning.remove();
            }

            // Enable submit button
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            submitBtn.querySelector('span:first-child').textContent = 'Buy Data Now';
        }
    }

    /*
     * Data form submission.
     *
     * Posted with `fetch()` rather than as a plain form post, because the answer
     * is sometimes a *decision* rather than an outcome: a changed price comes
     * back as a structured 409 carrying both figures and a confirmation token,
     * and a full-page post would replace the page — losing the customer's
     * selected bundle, phone number and position — to show them a modal.
     *
     * ## What is deliberately still native
     *
     * The browser's own validation runs first (the button is `type="submit"` and
     * nothing is `preventDefault`ed until it has passed), so a wallet purchase
     * with no PIN is stopped in place rather than by a round trip. The rail's PIN
     * field is statically `required` for the same reason.
     *
     * ## What is not repeated here
     *
     * No price arithmetic. The client cannot know the price the server will
     * charge, so it does not try: it sends the identifier and the figure it was
     * *shown*, and the server decides.
     */
    dataForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        if (!dataPlanSelect.value || dataPlanSelect.disabled) {
            window.ReUpFeedback
                ? window.ReUpFeedback.error('Please select a data plan.')
                : alert('Please select a data plan');
            return;
        }

        const phoneInput = document.getElementById('data_phone');

        if (!/^0[7-9][0-9]{9}$/.test(String(phoneInput.value || '').trim())) {
            window.ReUpFeedback
                ? window.ReUpFeedback.error('Enter a valid Nigerian phone number, for example 08012345678.')
                : alert('Please enter a valid 11-digit phone number');
            phoneInput.focus();
            return;
        }

        const submitBtn = dataForm.querySelector('button[type="submit"]');
        const originalLabel = submitBtn ? submitBtn.querySelector('span:first-child') : null;
        const originalText = originalLabel ? originalLabel.textContent : null;

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-60', 'cursor-not-allowed');
            if (originalLabel) {
                originalLabel.textContent = 'Sending…';
            }
        }

        try {
            const response = await fetch(dataForm.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(dataForm),
                credentials: 'same-origin',
            });

            /*
             * A validation failure, or a refusal the server chose to answer as a
             * page. Follow it: a redirect to the success page is an outcome the
             * customer wants to see, and a redirect back is the server's own
             * rendering of the refusal — which is where a flash message and the
             * repopulated form live.
             */
            if (response.redirected) {
                window.location.assign(response.url);
                return;
            }

            const contentType = response.headers.get('content-type') || '';

            if (!contentType.includes('application/json')) {
                dataForm.submit();
                return;
            }

            const payload = await response.json();

            if (payload.status === 'price_changed') {
                // The modal owns the decision from here. The form is left exactly
                // as it is, so cancelling keeps every input the customer entered.
                window.dispatchEvent(new CustomEvent('reup:price-changed', { detail: payload }));
                return;
            }

            if (payload.status === 'refused' || !response.ok) {
                const message = payload.message || 'We could not complete that purchase. No money has left your wallet.';

                window.ReUpFeedback ? window.ReUpFeedback.error(message) : alert(message);
                return;
            }

            if (payload.redirect) {
                window.location.assign(payload.redirect);
            }
        } catch (error) {
            console.error('Data purchase failed:', error);

            const message = 'We could not reach ReUp. Nothing has been charged — please check your connection and try again.';
            window.ReUpFeedback ? window.ReUpFeedback.error(message) : alert(message);
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-60', 'cursor-not-allowed');
                if (originalLabel && originalText !== null) {
                    originalLabel.textContent = originalText;
                }
            }
        }
    });

    // Airtime form submission
    airtimeForm.addEventListener('submit', function(e) {
        // No fee, so the total the customer needs is the amount they entered.
        const totalAmount = parseFloat(amountInput.value) || 0;

        if (totalAmount > walletBalance) {
            e.preventDefault();
            alert('Insufficient wallet balance. Please fund your wallet first.');
            return;
        }
    });

    // Add input validation for phone numbers
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 11) {
                this.value = this.value.slice(0, 11);
            }
        });
    });

    // Start the periodic price refresh. The API may report a different interval
    // on its first response; `scheduleDataPlanRefresh()` is re-run with it below.
    scheduleDataPlanRefresh();
});

/*
 * The price-change confirmation.
 *
 * Receives the structured 409 the purchase endpoint returns, shows both figures
 * and the bundle, and — on acceptance — re-submits the *same form* with the
 * server-issued confirmation token attached. The token is the only thing that
 * changes: the price is never posted by this code, because the client has no
 * authority over it.
 *
 * Cancelling closes the modal and nothing else. The form's values are untouched,
 * so the customer keeps their bundle, phone number and rail, and the wallet was
 * never debited — the confirmation exists precisely because the purchase had not
 * been made.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('priceChange', () => ({
        open: false,
        busy: false,
        error: '',
        message: '',
        product: {},
        previousPrice: '',
        newPrice: '',
        balance: '',
        affordable: true,
        expiresAt: 0,
        expiresIn: '',
        token: '',
        countdown: null,

        init() {
            window.addEventListener('reup:price-changed', (event) => this.show(event.detail));
        },

        show(payload) {
            this.error = '';
            this.busy = false;
            this.message = payload.message || 'The price for your selected data bundle has changed. Would you like to continue at the updated price?';
            this.product = payload.product || {};
            this.previousPrice = payload.previous_price_formatted || '';
            this.newPrice = payload.new_price_formatted || '';
            this.balance = payload.wallet_balance_formatted || '';
            this.affordable = payload.affordable !== false;
            this.token = (payload.quote && payload.quote.identifier) || '';
            this.expiresAt = Number((payload.quote && payload.quote.expires_at_unix) || 0);

            this.open = true;
            this.startCountdown();

            // Move focus into the dialog so a keyboard user is placed at the
            // decision rather than left behind it.
            this.$nextTick(() => this.$refs.panel && this.$refs.panel.focus());
        },

        startCountdown() {
            if (this.countdown) {
                clearInterval(this.countdown);
            }

            const tick = () => {
                const remaining = Math.max(0, this.expiresAt - Math.floor(Date.now() / 1000));

                if (remaining <= 0) {
                    this.expiresIn = 'a moment';
                    clearInterval(this.countdown);
                    return;
                }

                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                this.expiresIn = minutes > 0
                    ? `${minutes}m ${String(seconds).padStart(2, '0')}s`
                    : `${seconds}s`;
            };

            tick();
            this.countdown = setInterval(tick, 1000);
        },

        confirm() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = '';

            const form = document.getElementById('dataForm');
            const field = document.getElementById('price_confirmation');

            if (!form || !field || !this.token) {
                this.busy = false;
                this.error = 'That confirmation has expired. Please submit the purchase again to see the current price.';
                return;
            }

            field.value = this.token;

            /*
             * Re-submit the form. The submit handler sends it with the token, and
             * the server re-validates the price, the rule and the expiry — so if
             * the price moved again while the modal was open, a *new* modal opens
             * with the latest figures instead of a purchase at an unagreed price.
             *
             * The modal is closed here rather than left spinning. Every outcome
             * replaces it: a success navigates to the receipt, a renewed price
             * change re-opens it with the new figures, and a failure raises a
             * message through the feedback channel. Leaving a spinner behind an
             * error is how a customer concludes their money is in limbo.
             */
            this.open = false;
            this.busy = false;

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
        },

        cancel() {
            // No request, no debit, no change to the form. Clearing the token is
            // the only state this touches, so the next submission is treated as a
            // fresh one and the customer is asked again if the price moved.
            const field = document.getElementById('price_confirmation');
            if (field) {
                field.value = '';
            }

            if (this.countdown) {
                clearInterval(this.countdown);
            }

            this.open = false;
            this.busy = false;
            this.error = '';

            if (window.ReUpFeedback) {
                window.ReUpFeedback.info('Purchase cancelled. Nothing has been charged.', { timeout: 6000 });
            }
        },
    }));
});
</script>
@endsection

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

    // Network marks, shared by the airtime and data forms. Logos are served
    // from our own domain; the catalogue lives in config/bills.php so the
    // picker and the server-side validation cannot drift apart.
    $networkOptions = collect(config('bills.networks', []))
        ->map(fn ($network, $code) => [
            'code' => (string) $code,
            'name' => $network['name'],
            'logo' => asset($network['logo']),
        ])
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
                                    :class="service === 'airtime' ? 'bg-brand-500 text-white shadow-subtle' : 'text-muted-foreground hover:bg-ink-100'"
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
                                    :class="service === 'data' ? 'bg-brand-500 text-white shadow-subtle' : 'text-muted-foreground hover:bg-ink-100'"
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
                                            <span class="network-option flex flex-col items-center gap-2 rounded-xl border border-border bg-white p-4 text-center transition-colors hover:border-brand-300">
                                                <img src="{{ $network['logo'] }}" alt="" class="h-6 w-6 rounded-full object-contain">
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
                                                class="quick-amount rounded-lg border border-border bg-white py-2 px-3 text-sm font-semibold transition-colors hover:border-brand-400 hover:bg-accent"
                                                data-amount="{{ $value }}">
                                            &#8358;{{ $label }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>

            
                {{-- PIN is verified server-side before the debit; the key makes a
                     double submit idempotent. --}}
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
                                            <span class="network-option flex flex-col items-center gap-2 rounded-xl border border-border bg-white p-4 text-center transition-colors hover:border-brand-300">
                                                <img src="{{ $network['logo'] }}" alt="" class="h-6 w-6 rounded-full object-contain">
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
                                                class="data-type-filter rounded-lg border border-border bg-white px-4 py-2 text-sm font-medium transition-colors hover:bg-ink-50 {{ $type === 'all' ? 'active' : '' }}"
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
                                     when the form arrives. --}}
                                <input type="hidden" id="plan_name" name="plan_name">
                                <input type="hidden" id="plan_price" name="plan_price">
                                <input type="hidden" id="plan_type" name="plan_type">
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
                <div class="rounded-xl border border-brand-600 bg-brand-500 p-5 text-white shadow-subtle md:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-brand-50">Wallet balance</p>
                            <p class="mt-2 text-3xl font-semibold tabular-nums">
                                &#8358;{{ number_format((float) ($wallet->balance ?? 0), 2) }}
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
    const dataTypeFilters = document.querySelectorAll('.data-type-filter');

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

    // Function to filter and display plans
    function displayFilteredPlans() {
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

        planNameInput.value = '';
        planPriceInput.value = '';
        planTypeInput.value = '';
    }

    // Data type filter buttons
    dataTypeFilters.forEach(filterBtn => {
        filterBtn.addEventListener('click', function() {
            dataTypeFilters.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');

            currentFilter = this.dataset.type;

            if (fetchedDataPlans.length > 0) {
                displayFilteredPlans();
            }
        });
    });

    /*
     * Load data plans when a network is selected.
     *
     * The catalogue comes from this application, not from the provider. It was
     * previously fetched straight from NelloBytes with the account id in the
     * query string — which published the credential to every visitor — and the
     * markup was applied in the browser, so the price the form posted could be
     * edited with devtools. The server now returns the price it will actually
     * charge, and re-resolves it again when the purchase arrives.
     */
    dataNetworkRadios.forEach(radio => {
        radio.addEventListener('change', async function() {
            currentNetworkId = this.value;

            dataPlanSelect.disabled = true;
            dataPlanSelect.innerHTML = '<option value="">Loading plans...</option>';
            dataPlanLoader.classList.remove('hidden');
            dataPlanLoader.classList.add('flex');
            planNameInput.value = '';
            planPriceInput.value = '';
            planTypeInput.value = '';

            dataTypeFilters.forEach(btn => btn.classList.remove('active'));
            document.querySelector('[data-type="all"]').classList.add('active');
            currentFilter = 'all';

            try {
                const response = await fetch('{{ route('pricelist.api') }}', {
                    headers: { 'Accept': 'application/json' },
                });

                if (!response.ok) {
                    throw new Error('Plan catalogue responded with ' + response.status);
                }

                const payload = await response.json();

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

                displayFilteredPlans();

            } catch (error) {
                console.error('Error loading data plans:', error);

                fetchedDataPlans = [];
                dataPlanSelect.innerHTML = '<option value="">We could not load the plans. Please try again.</option>';

                if (window.ReUpFeedback) {
                    window.ReUpFeedback.error('We could not load the data plans. Please try again in a moment.');
                }
            } finally {
                dataPlanLoader.classList.add('hidden');
                dataPlanLoader.classList.remove('flex');
            }
        });
    });

    // When user selects a data plan, update hidden inputs and check balance
    dataPlanSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];

        if (selectedOption.value) {
            const planName = selectedOption.dataset.planName || '';
            const planPrice = parseFloat(selectedOption.dataset.planPrice || 0); // We charge this
            const planType = selectedOption.dataset.planType || '';

            // Posted for display and for a price cross-check on the server; it is
            // not what the server charges from.
            planNameInput.value = planName;
            planPriceInput.value = planPrice;
            planTypeInput.value = planType;

            // Show plan summary
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

            // Check balance for data
            checkDataBalance(planPrice);
        } else {
            planNameInput.value = '';
            planPriceInput.value = '';
            planTypeInput.value = '';

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

    // Data form submission - validation
    dataForm.addEventListener('submit', function(e) {
        if (!dataPlanSelect.value || dataPlanSelect.disabled) {
            e.preventDefault();
            alert('Please select a data plan');
            return;
        }

        const phoneInput = document.getElementById('data_phone');
        const phoneRegex = /^[0-9]{11}$/;
        if (!phoneRegex.test(phoneInput.value)) {
            e.preventDefault();
            alert('Please enter a valid 11-digit phone number');
            phoneInput.focus();
            return;
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
});
</script>
@endsection

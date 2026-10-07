@extends('layouts.app')
@section('title', 'JAMB e-PIN')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | JAMB e-PIN
    |--------------------------------------------------------------------------
    | Contract (JambPinController):
    |   index          — $user, $types (code => {label, price} from
    |                    config('bills.exam_pins.jamb')), $recentTransactions.
    |   verify-profile — POST route('jamb.verify-profile')
    |                    {profile_id, exam_type} ->
    |                    {success, data: {customer_name, profile_id, exam_type}}.
    |   purchase       — POST route('jamb.purchase')
    |                    {profile_id, exam_type, phone}. The provider request id is
    |                    derived server-side, so the form no longer posts one.
    */
    $types = $types ?? [];
    $fee = (float) config('bills.fees.exam_pin', 0);
    $recentTransactions = $recentTransactions ?? collect();

    $prices = collect($types)->map(fn ($type) => (float) ($type['price'] ?? 0))->all();
    $defaultType = $types ? array_key_first($types) : '';
    $initialCost = (float) ($prices[$defaultType] ?? 0);

    $notes = [
        'The e-PIN is delivered by SMS within 5 minutes.',
        'Check the JAMB Profile ID before paying — PINs cannot be reversed.',
        'Use the PIN to finish registration on the JAMB portal.',
        'No refund is possible once a PIN has been generated.',
    ];

    $steps = [
        ['Verify the profile', 'We check the Profile ID against the JAMB database.'],
        ['Pay from your wallet', 'The e-PIN price is deducted from your balance.'],
        ['Receive the e-PIN', 'It arrives by SMS and stays on your receipt.'],
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
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">JAMB e-PIN</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                UTME and Direct Entry registration PINs, generated against your Profile ID and sent by SMS.
            </p>
        </header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8" x-data="jambForm()">

            {{-- ===================== Purchase form ===================== --}}
            <div class="lg:col-span-2">
                <form id="jambForm"
                      action="{{ route('jamb.purchase') }}"
                      method="POST"
                      class="card"
                      @submit="onSubmit($event)">
                    @csrf

                    <div class="card-header">
                        <h2 class="card-title">Buy an e-PIN</h2>
                        <p class="card-description">Verify the profile, then pay from your wallet.</p>
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
                                    <p class="text-xs text-muted-foreground">Charged when the e-PIN is issued</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-xl font-semibold tabular-nums">
                                    &#8358;{{ number_format((float) ($user->wallet_balance ?? 0), 2) }}
                                </p>
                                <a href="{{ route('wallet.fund') }}" class="link text-sm font-medium">Fund wallet</a>
                            </div>
                        </div>

                        @if(empty($types))
                            <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4" role="status">
                                <x-icon name="exclamation-triangle" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                                <p class="text-sm text-amber-900">
                                    JAMB e-PIN pricing is not configured yet. Please contact support.
                                </p>
                            </div>
                        @endif

                        {{-- Exam type --}}
                        <fieldset>
                            <legend class="label mb-3">Exam type</legend>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach($types as $code => $type)
                                    <label class="cursor-pointer">
                                        <input type="radio"
                                               name="exam_type"
                                               value="{{ $code }}"
                                               class="peer sr-only"
                                               x-model="examType"
                                               required>
                                        <span class="block rounded-xl border border-border bg-white p-4 transition-colors hover:border-brand-300 peer-checked:border-brand-500 peer-checked:bg-accent peer-checked:shadow-subtle peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                            <span class="flex items-start justify-between gap-3">
                                                <span class="flex items-center gap-3">
                                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                                                        <x-icon name="academic-cap" class="h-5 w-5" />
                                                    </span>
                                                    <span class="block">
                                                        <span class="block text-sm font-semibold">{{ $type['label'] ?? strtoupper($code) }}</span>
                                                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ strtoupper($code) }} registration PIN</span>
                                                    </span>
                                                </span>
                                                <span class="shrink-0 text-sm font-semibold tabular-nums">
                                                    &#8358;{{ number_format((float) ($type['price'] ?? 0), 2) }}
                                                </span>
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="field-error" x-show="examTypeError" x-cloak x-text="examTypeError"></p>
                        </fieldset>

                        {{-- Profile ID --}}
                        <div>
                            <label for="profile_id" class="label">
                                JAMB Profile ID
                                <span class="font-normal text-muted-foreground">— 10 characters from your registration</span>
                            </label>
                            <div class="relative mt-1.5">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-muted-foreground">
                                    <x-icon name="identification" class="h-4 w-4" />
                                </span>
                                <input type="text"
                                       id="profile_id"
                                       name="profile_id"
                                       placeholder="ABC1234567"
                                       class="input pl-10 uppercase"
                                       :value="profileId"
                                       @input="onProfileInput($event)"
                                       maxlength="10"
                                       pattern="[A-Z0-9]{10}"
                                       autocomplete="off"
                                       required>
                            </div>
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                <x-icon name="information-circle" class="h-3.5 w-3.5" />
                                Letters and numbers only, exactly as issued by JAMB.
                            </p>
                        </div>

                        {{-- Verify --}}
                        <div>
                            <button type="button" class="btn btn-outline w-full" @click="verifyProfile()" :disabled="verifying">
                                <span class="h-4 w-4 animate-spin rounded-full border-2 border-brand-500 border-t-transparent" x-show="verifying" x-cloak></span>
                                <x-icon name="magnifying-glass" class="h-4 w-4" x-show="!verifying" />
                                <span x-text="verifying ? 'Verifying…' : 'Verify Profile ID'"></span>
                            </button>
                            <p class="field-error" x-show="verifyError" x-cloak x-text="verifyError"></p>
                        </div>

                        {{-- Verified profile --}}
                        <div class="rounded-xl border border-border bg-accent p-4" x-show="candidateName" x-cloak>
                            <div class="flex items-start gap-3">
                                <x-icon name="check-circle" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-accent-foreground">Profile verified</p>
                                    <dl class="mt-2 space-y-1 text-sm">
                                        <div class="flex gap-2">
                                            <dt class="text-muted-foreground">Candidate</dt>
                                            <dd class="font-medium" x-text="candidateName"></dd>
                                        </div>
                                        <div class="flex gap-2">
                                            <dt class="text-muted-foreground">Eligible for</dt>
                                            <dd class="font-medium" x-text="summaryExam"></dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
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
                            <p class="mt-1.5 text-xs text-muted-foreground">The e-PIN is sent to this number.</p>
                        </div>

                        {{-- Amount. Server-rendered figures are the pre-Alpine
                             fallback for the default exam type. --}}
                        <div class="rounded-xl border border-border bg-surface p-4">
                            <div class="flex items-center justify-between text-sm">
                                <span class="stat-label">e-PIN cost</span>
                                <span class="font-semibold tabular-nums" x-text="money(pinCost)">&#8358;{{ number_format($initialCost, 2) }}</span>
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
                                <span class="stat-value text-brand-600" x-text="money(total)">&#8358;{{ number_format($initialCost + $fee, 2) }}</span>
                            </div>
                        </div>

                        <p class="field-error" x-show="submitError" x-cloak x-text="submitError"></p>
                {{-- Bank-level controls: PIN verified server-side before the
                     debit; the key makes a double submit idempotent. --}}
                <x-transaction-pin id="pay-pin" />

                <input type="hidden" name="idempotency_key"
                       value="{{ Str::random(32) }}">


                        <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="!Object.keys(prices).length">
                            <x-icon name="academic-cap" class="h-5 w-5" />
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
                                        {{ $transaction->description ?: 'JAMB e-PIN' }}
                                    </p>
                                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                                        {{ $transaction->recipient ?: 'Profile' }}
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
                                    <x-icon name="academic-cap" class="h-5 w-5" />
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

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Support</h2>
                    </div>
                    <div class="card-content space-y-3 text-sm text-muted-foreground">
                        <p class="flex items-start gap-2.5">
                            <x-icon name="whatsapp" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-[#128C7E]" />
                            Message us on WhatsApp every day for PIN issues.
                        </p>
                        <p class="flex items-start gap-2.5">
                            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            Failed purchases are refunded to your wallet automatically.
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
    Alpine.data('jambForm', () => ({
        prices: @json($prices),
        labels: @json(collect($types)->map(fn ($type) => $type['label'] ?? null)->all()),
        fee: @json($fee),

        examType: @json($defaultType),
        profileId: '',
        phone: @json((string) ($user->phone ?? '')),

        verifying: false,
        verifyError: '',
        candidateName: '',

        examTypeError: '',
        submitError: '',

        get pinCost() {
            return Number(this.prices[this.examType] || 0);
        },

        get total() {
            return this.pinCost + Number(this.fee || 0);
        },

        get summaryExam() {
            return this.labels[this.examType] || String(this.examType || '').toUpperCase();
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

        onProfileInput(event) {
            const clean = String(event.target.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
            if (event.target.value !== clean) {
                event.target.value = clean;
            }
            this.profileId = clean;
        },

        async verifyProfile() {
            this.verifyError = '';
            this.candidateName = '';

            const profileId = String(this.profileId || '').trim().toUpperCase();
            this.profileId = profileId;

            if (!/^[A-Z0-9]{10}$/.test(profileId)) {
                this.verifyError = 'Enter the 10-character Profile ID (letters and numbers only).';
                return;
            }

            if (!this.examType) {
                this.verifyError = 'Choose an exam type first.';
                return;
            }

            this.verifying = true;

            try {
                const response = await fetch('{{ route('jamb.verify-profile') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({
                        profile_id: profileId,
                        exam_type: this.examType,
                    }),
                });

                const data = await response.json();
                const payload = data && data.data ? data.data : null;
                const name = payload ? payload.customer_name : null;

                if (data && data.success && name) {
                    this.candidateName = name;
                } else {
                    this.verifyError = (data && data.message) || 'That Profile ID could not be verified.';
                }
            } catch (error) {
                console.error('JAMB profile verification failed:', error);
                this.verifyError = 'Verification is unavailable right now. Please try again.';
            } finally {
                this.verifying = false;
            }
        },

        onSubmit(event) {
            this.examTypeError = '';
            this.submitError = '';

            if (!this.examType) {
                event.preventDefault();
                this.examTypeError = 'Choose an exam type.';
                return;
            }

            const profileId = String(this.profileId || '').trim().toUpperCase();
            this.profileId = profileId;

            if (!/^[A-Z0-9]{10}$/.test(profileId)) {
                event.preventDefault();
                this.submitError = 'Enter a valid 10-character JAMB Profile ID.';
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

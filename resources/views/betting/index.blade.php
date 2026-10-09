@extends('layouts.app')

@section('title', 'Betting wallet funding')

@section('content')
@php
    $range = (array) config('bills.ranges.betting', ['min' => 100, 'max' => 100000]);
    $fee = (float) ($fee ?? 0);
@endphp

<div class="container-page py-8"
     x-data="bettingFunding({
         verifyUrl: @js(route('betting.verify')),
         csrf: @js(csrf_token()),
         feed: @json($providers),
         labels: @json(collect($providers)->map(fn ($p) => $p['label'] ?? '')->all()),
         minimums: @json(collect($providers)->map(fn ($p) => (float) ($p['min'] ?? 0))->all()),
         min: {{ (float) ($range['min'] ?? 100) }},
         max: {{ (float) ($range['max'] ?? 100000) }},
         fee: {{ $fee }},
         balance: {{ (float) ($user->wallet_balance ?? 0) }},
     })">

    <div class="mb-6">
        <h1 class="mt-2 text-2xl font-semibold tracking-tight">Fund a betting wallet</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Top up your bookmaker account from your ReUp wallet. The account number is
            checked with the provider before any money moves.
        </p>
    </div>

    @unless($hasPin)
        <div class="mb-6 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
            <x-icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
            <p class="text-sm text-amber-900">
                <strong class="font-semibold">Set your transaction PIN first.</strong>
                Every purchase needs a 4-digit PIN.
                <a href="{{ route('profile.index') }}" class="font-medium underline underline-offset-4">Set it in profile settings</a>.
            </p>
        </div>
    @endunless

    @if($errors->any())
        <div class="mb-6 flex items-start gap-2.5 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
            <x-icon name="exclamation-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
            <ul class="min-w-0 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li class="text-sm text-red-700">{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ============================ Form ============================ --}}
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('betting.fund', [], false) }}" class="card"
                  @submit="submitError = ''">
                @csrf

                <div class="card-header">
                    <h2 class="card-title">Funding details</h2>
                    <p class="card-description">
                        Available balance: <span class="font-medium text-foreground">&#8358;{{ number_format((float) ($user->wallet_balance ?? 0), 2) }}</span>
                    </p>
                </div>

                <div class="card-content space-y-5">

                    {{-- Provider --}}
                    <div>
                        <span class="label mb-3 block">Bookmaker</span>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <template x-for="(label, code) in labels" :key="code">
                                <label class="cursor-pointer">
                                    <input type="radio" name="provider" :value="code"
                                           x-model="provider" @change="reset()"
                                           class="sr-only" required>
                                    <span class="flex items-center justify-center rounded-xl border border-border bg-surface px-3 py-3 text-center text-sm font-medium transition-colors"
                                          :class="provider === code
                                              ? 'border-brand-500 bg-brand-50 text-brand-700'
                                              : 'hover:border-brand-300'"
                                          x-text="label"></span>
                                </label>
                            </template>
                        </div>
                        @error('provider')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    {{-- Account --}}
                    <div>
                        <label for="customer_id" class="label">Betting account number / user ID</label>
                        <div class="mt-1.5 flex gap-2">
                            <input id="customer_id" name="customer_id" type="text" required
                                   x-model="customerId" @input="reset()"
                                   inputmode="numeric" autocomplete="off"
                                   maxlength="20"
                                   placeholder="e.g. 1234567"
                                   class="input @error('customer_id') input-error @enderror">
                            <button type="button" class="btn btn-outline shrink-0"
                                    @click="verify()" :disabled="verifying || !canVerify">
                                <span x-show="!verifying">Verify</span>
                                <span x-show="verifying" x-cloak>Checking…</span>
                            </button>
                        </div>
                        @error('customer_id')<p class="field-error">{{ $message }}</p>@enderror

                        <p class="mt-1.5 flex items-center gap-1.5 text-xs"
                           x-show="verifiedName" x-cloak>
                            <x-icon name="check-circle" variant="solid" class="h-3.5 w-3.5 text-green-600" />
                            <span class="text-green-700" x-text="verifiedName"></span>
                        </p>
                        <p class="mt-1.5 text-xs text-red-600" x-show="verifyError" x-cloak x-text="verifyError"></p>
                    </div>

                    {{-- Amount --}}
                    <div>
                        <label for="amount" class="label">Amount</label>
                        <input id="amount" name="amount" type="number" required
                               x-model.number="amount" @input="reset()"
                               min="{{ $range['min'] ?? 100 }}" max="{{ $range['max'] ?? 100000 }}"
                               step="50"
                               placeholder="{{ $range['min'] ?? 100 }}"
                               class="input mt-1.5 @error('amount') input-error @enderror">
                        @error('amount')<p class="field-error">{{ $message }}</p>@enderror

                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="preset in [500, 1000, 2000, 5000, 10000]" :key="preset">
                                <button type="button" class="btn btn-outline btn-sm"
                                        @click="amount = preset"
                                        x-text="'₦' + preset.toLocaleString()"></button>
                            </template>
                        </div>
                    </div>

                    {{-- Phone (for the provider's receipt SMS) --}}
                    <div>
                        <label for="phone" class="label">
                            Phone <span class="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <input id="phone" name="phone" type="tel"
                               value="{{ old('phone', $user->phone) }}"
                               maxlength="11" inputmode="numeric"
                               @input="$event.target.value = $event.target.value.replace(/\D/g,'').slice(0,11)"
                               placeholder="08012345678"
                               class="input mt-1.5 @error('phone') input-error @enderror">
                        @error('phone')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="divider"></div>

                    {{-- Summary --}}
                    <dl class="space-y-2 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-muted-foreground">Top-up</dt>
                            <dd class="tabular-nums" x-text="money(amount || 0)"></dd>
                        </div>
                        @if($fee > 0)
                            <div class="flex items-center justify-between">
                                <dt class="text-muted-foreground">Service fee</dt>
                                <dd class="tabular-nums" x-text="money(fee)"></dd>
                            </div>
                        @endif
                        <div class="flex items-center justify-between border-t pt-2">
                            <dt class="font-semibold">Total debited</dt>
                            <dd class="text-lg font-semibold tabular-nums text-brand-600" x-text="money(total)"></dd>
                        </div>
                    </dl>

                    <p class="text-xs text-muted-foreground"
                       x-show="amount > 0 && total > balance" x-cloak>
                        <span class="font-medium text-red-600">Insufficient balance.</span>
                        You need <span x-text="money(total - balance)"></span> more.
                    </p>

                    <x-transaction-pin id="betting-pin" />

                    <input type="hidden" name="idempotency_key" value="{{ Str::random(32) }}">

                    <p class="field-error" x-show="submitError" x-cloak x-text="submitError"></p>
                </div>

                <div class="card-footer justify-end">
                    <button type="submit" class="btn btn-primary btn-lg w-full sm:w-auto"
                            :disabled="!verifiedName || total > balance || total <= 0">
                        <x-icon name="wallet" class="h-4 w-4" />
                        Fund betting wallet
                    </button>
                </div>
            </form>
        </div>

        {{-- ============================ Sidebar ============================ --}}
        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Recent top-ups</h2>
                </div>
                <ul class="divide-y divide-border">
                    @forelse($recentTransactions as $transaction)
                        <li class="flex items-center gap-3 px-5 py-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600">
                                <x-icon name="wallet" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ data_get($transaction->meta, 'betting_label', 'Betting') }}
                                </p>
                                <p class="truncate font-mono text-xs text-muted-foreground">
                                    {{ $transaction->recipient }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-medium tabular-nums">
                                    &#8358;{{ number_format((float) $transaction->amount, 2) }}
                                </p>
                                <span class="badge {{ $transaction->status_badge }}">
                                    {{ ucfirst($transaction->status) }}
                                </span>
                            </div>
                        </li>
                    @empty
                        <li class="empty-state">
                            <x-icon name="wallet" class="h-6 w-6 text-ink-300" />
                            <p class="mt-2 text-sm text-muted-foreground">No top-ups yet.</p>
                        </li>
                    @endforelse
                </ul>
                @if($recentTransactions->isNotEmpty())
                    <div class="card-footer justify-center">
                        <a href="{{ route('betting.history') }}" class="link text-sm font-medium">View all</a>
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Before you fund</h2>
                </div>
                <div class="card-content">
                    <ul class="space-y-3 text-sm">
                        @foreach([
                            ['Verify the account first — a mistyped ID cannot be recovered once funded.', 'shield-check'],
                            ['The account number is the one shown in your bookmaker profile, not your phone number.', 'identification'],
                            ['Top-ups are instant; if one fails your wallet is refunded automatically.', 'arrow-path'],
                            ['Limits apply per transaction and per day — see profile settings.', 'scale'],
                        ] as [$tip, $icon])
                            <li class="flex items-start gap-2.5">
                                <x-icon :name="$icon" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                                <span class="text-muted-foreground">{{ $tip }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function bettingFunding(config) {
        return {
            provider: Object.keys(config.labels)[0] || '',
            customerId: '',
            amount: 0,
            fee: config.fee,
            balance: config.balance,
            min: config.min,

            verifying: false,
            verifiedName: '',
            verifyError: '',
            submitError: '',

            get minimum() {
                // Upstream floors differ per bookmaker; take the tighter one.
                return Math.max(this.min, Number(config.minimums[this.provider] || 0));
            },

            get canVerify() {
                return this.customerId.length >= 4 && this.provider;
            },

            get total() {
                return Number(this.amount || 0) + Number(this.fee || 0);
            },

            money(value) {
                return '\u20A6' + Number(value || 0).toLocaleString('en-NG', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            },

            reset() {
                // Any change invalidates the verified name: funding the wrong
                // account is not reversible.
                this.verifiedName = '';
                this.verifyError = '';
                this.submitError = '';
            },

            async verify() {
                if (!this.canVerify) return;

                this.verifying = true;
                this.reset();

                try {
                    const res = await fetch(config.verifyUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': config.csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            provider: this.provider,
                            customer_id: this.customerId,
                        }),
                    });

                    const data = await res.json().catch(() => ({}));

                    if (!res.ok || !data.success) {
                        throw new Error(data.message || 'That account could not be verified.');
                    }

                    const name = data.data && data.data.customer_name;
                    this.verifiedName = name
                        ? name + ' — ' + (config.labels[this.provider] || '')
                        : 'Account verified — ' + (config.labels[this.provider] || '');
                } catch (e) {
                    this.verifyError = e.message || 'Verification failed.';
                } finally {
                    this.verifying = false;
                }
            },
        };
    }
</script>
@endpush

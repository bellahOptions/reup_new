@extends('layouts.app')
@section('title', 'Fund wallet')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Fund wallet
    |--------------------------------------------------------------------------
    | WalletController::fund() passes $wallet, $bank_details, $paystack_fee,
    | $bank_fee, $min_amount, $max_amount, $recent_funding and $paystack_enabled.
    |
    | Two things changed structurally:
    |
    | 1. SweetAlert2 was loaded from cdn.jsdelivr.net and drove the
    |    session('modal_success') / session('modal_error') dialogs. Both the CDN
    |    dependency and the inline script are gone. Those two outcomes are now
    |    rendered as page content (below), and the ordinary success/error/warning
    |    flashes are already handled by partials/flash.blade.php in layouts.app.
    |
    | 2. Payment-method selection, quick amounts, the live fee summary and the
    |    client-side range check are all Alpine state, so there are no inline
    |    onclick handlers and no jQuery. The form field names (`amount`,
    |    `payment_method`), the @csrf token and the wallet.process-funding POST
    |    target are unchanged.
    */
    $balance = (float) ($wallet->balance ?? 0);
    $paystackPercentage = (float) ($paystack_fee['percentage'] ?? 0);
    $paystackAdditional = (float) ($paystack_fee['additional'] ?? 0);
    $paystackCap = $paystack_fee['cap'] ?? null;
    $bankFixedFee = (float) ($bank_fee['fixed'] ?? 0);

    $quickAmounts = [500, 1000, 2000, 5000, 10000];
    $amountOutOfRange = $errors->has('amount');

    $statusBadges = [
        'success' => 'badge-success',
        'pending' => 'badge-warning',
        'processing' => 'badge-info',
        'verifying' => 'badge-info',
        'failed' => 'badge-destructive',
        'cancelled' => 'badge-neutral',
    ];
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-12">

        {{-- Page heading --}}
        <header class="mb-8 md:mb-10">
            <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Fund your wallet</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                Add money by card or bank transfer. The fee is calculated before you pay, never after.
            </p>
        </header>

        {{-- Payment outcome, rendered as page content. Previously these arrived
             as SweetAlert2 dialogs driven by a CDN script. --}}
        @if(session('modal_success'))
            <div class="mb-6 flex items-start gap-3 rounded-xl border border-brand-200 bg-brand-50 p-5" role="status">
                <x-icon name="check-circle" variant="solid" class="mt-0.5 h-6 w-6 shrink-0 text-brand-600" />
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-brand-900">Payment successful</h2>
                    <p class="mt-1 text-sm text-brand-800">{{ session('modal_success') }}</p>
                    <p class="mt-3 text-sm font-medium text-brand-900">
                        Current balance: <span class="tabular-nums">&#8358;{{ number_format($balance, 2) }}</span>
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="{{ route('wallet.index') }}" class="btn btn-primary btn-sm">Back to wallet</a>
                        <a href="{{ route('wallet.history') }}" class="btn btn-outline btn-sm">View history</a>
                    </div>
                </div>
            </div>
        @endif

        @if(session('modal_error'))
            <div class="mb-6 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-5" role="alert">
                <x-icon name="exclamation-circle" variant="solid" class="mt-0.5 h-6 w-6 shrink-0 text-red-600" />
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-red-900">Payment failed</h2>
                    <p class="mt-1 text-sm text-red-800">{{ session('modal_error') }}</p>
                    <p class="mt-3 text-sm text-red-800">
                        If you were debited, contact support with your reference and we will trace it immediately.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="{{ route('wallet.history') }}" class="btn btn-outline btn-sm">View history</a>
                        <a href="{{ route('contact') }}" class="btn btn-outline btn-sm">Contact support</a>
                    </div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="card"
                     x-data="walletFunding({
                        amount: @js(old('amount') !== null ? (float) old('amount') : null),
                        method: @js(old('payment_method', 'paystack')),
                        quick: @js($quickAmounts),
                        min: @js((float) $min_amount),
                        max: @js((float) $max_amount),
                        paystackPercentage: @js($paystackPercentage),
                        paystackAdditional: @js($paystackAdditional),
                        paystackCap: @js($paystackCap !== null ? (float) $paystackCap : null),
                        bankFixedFee: @js($bankFixedFee)
                     })">

                    {{-- The component itself lives in
                         resources/js/wallet-funding.js, not in an inline x-data
                         string. Inline it was neither parse-checked by the build
                         nor safe from HTML-attribute quoting: a double quote in
                         one of its own JS comments closed the attribute early,
                         Alpine received a truncated expression, and every
                         binding on the page died at once. Only server values are
                         passed in here.

                         `data-no-loading`: this form owns its own loading state
                         (see the module), so forms.js must keep its hands off the
                         button.

                         The third `route()` argument ($absolute = false) is
                         load-bearing, not cosmetic: `form-action 'self'` is
                         measured against the page origin and includes the port,
                         so an absolute action built from APP_URL is refused
                         whenever APP_URL and the address bar disagree (e.g.
                         http://localhost vs http://127.0.0.1:8000). --}}
                    <form id="fundWalletForm" method="POST" action="{{ route('wallet.process-funding', [], false) }}"
                          data-no-loading
                          @submit="submit($event)">
                        @csrf

                        <div class="card-header">
                            <h2 class="card-title">Funding details</h2>
                            <p class="card-description">Choose an amount, then pick how you want to pay.</p>
                        </div>

                        <div class="card-content space-y-6">

                            {{-- Validation errors --}}
                            @if($errors->any())
                                <div class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4" role="alert">
                                    <x-icon name="exclamation-circle" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                                    <div class="min-w-0 text-sm text-red-800">
                                        @foreach($errors->all() as $error)
                                            <p>{{ $error }}</p>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- The outcome of a background submit. This is what
                                 stops a slow gateway from looking like an
                                 infinite spinner: the customer is told what
                                 happened, including that nothing was charged. --}}
                            <div x-show="outcome" x-cloak
                                 :class="outcomeTone === 'error'
                                     ? 'border-red-200 bg-red-50 text-red-800'
                                     : 'border-brand-200 bg-brand-50 text-brand-800'"
                                 class="flex items-start gap-3 rounded-lg border p-4"
                                 :role="outcomeTone === 'error' ? 'alert' : 'status'">
                                <x-icon name="exclamation-circle" variant="solid"
                                        class="mt-0.5 h-5 w-5 shrink-0"
                                        x-show="outcomeTone === 'error'" />
                                <x-icon name="check-circle" variant="solid"
                                        class="mt-0.5 h-5 w-5 shrink-0"
                                        x-show="outcomeTone !== 'error'" x-cloak />
                                <p class="min-w-0 text-sm" x-text="outcome"></p>
                            </div>

                            {{-- Current balance --}}
                            <div class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-brand-600">
                                        <x-icon name="wallet" variant="solid" class="h-5 w-5" />
                                    </span>
                                    <div>
                                        <p class="text-sm font-medium">Current balance</p>
                                        <p class="text-xs text-muted-foreground">Available for spending</p>
                                    </div>
                                </div>
                                <p class="text-2xl font-semibold tabular-nums text-brand-700">
                                    &#8358;{{ number_format($balance, 2) }}
                                </p>
                            </div>

                            {{-- Amount --}}
                            <div>
                                <label class="label" for="amount">Amount to add (&#8358;)</label>
                                <div class="relative mt-1.5">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 font-medium text-muted-foreground">
                                        &#8358;
                                    </span>
                                    <input type="number" id="amount" name="amount" x-model="amount"
                                           inputmode="decimal" step="0.01"
                                           min="{{ $min_amount }}" max="{{ $max_amount }}"
                                           placeholder="5000"
                                           @class(['input pl-9', 'input-error' => $amountOutOfRange])
                                           autocomplete="off" required>
                                </div>
                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    Minimum &#8358;{{ number_format((float) $min_amount) }} &middot;
                                    maximum &#8358;{{ number_format((float) $max_amount) }} per transaction.
                                </p>
                                <p class="field-error" x-show="outOfRange" x-cloak>
                                    Enter an amount between
                                    <span x-text="format(min)"></span> and <span x-text="format(max)"></span>.
                                </p>

                                {{-- Quick select --}}
                                <p class="mt-4 text-sm font-medium">Quick select</p>
                                <div class="mt-2 grid grid-cols-3 gap-2 md:grid-cols-5">
                                    <template x-for="option in quick" :key="option">
                                        <button type="button"
                                                @click="amount = option"
                                                :class="numericAmount === option
                                                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                                                    : 'border-border hover:border-brand-400 hover:bg-brand-50'"
                                                class="rounded-lg border px-3 py-2 text-sm font-medium tabular-nums transition-colors"
                                                x-text="'\u20A6' + option.toLocaleString('en-NG')"></button>
                                    </template>
                                </div>
                            </div>

                            {{-- Payment method --}}
                            <fieldset>
                                <legend class="label mb-3">Payment method</legend>
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <label class="block cursor-pointer rounded-xl has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500 has-[:focus-visible]:ring-offset-2">
                                        <input type="radio" name="payment_method" value="paystack" x-model="method" class="sr-only">
                                        <span class="flex h-full items-start gap-3 rounded-xl border-2 p-4 transition-colors"
                                              :class="method === 'paystack'
                                                  ? 'border-brand-500 bg-brand-50'
                                                  : 'border-border hover:border-ink-300'">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-brand-600">
                                                <x-icon name="credit-card" class="h-5 w-5" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-sm font-medium">Card payment</span>
                                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                                    Card, bank or USSD &middot; instant
                                                </span>
                                            </span>
                                            <x-icon name="check-circle" variant="solid"
                                                    class="h-5 w-5 shrink-0 text-brand-600"
                                                    x-show="method === 'paystack'" />
                                        </span>
                                    </label>

                                    <label class="block cursor-pointer rounded-xl has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500 has-[:focus-visible]:ring-offset-2">
                                        <input type="radio" name="payment_method" value="bank_transfer" x-model="method" class="sr-only">
                                        <span class="flex h-full items-start gap-3 rounded-xl border-2 p-4 transition-colors"
                                              :class="method === 'bank_transfer'
                                                  ? 'border-brand-500 bg-brand-50'
                                                  : 'border-border hover:border-ink-300'">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-brand-600">
                                                <x-icon name="building-library" class="h-5 w-5" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-sm font-medium">Bank transfer</span>
                                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                                    Transfer to our account &middot; verified automatically
                                                </span>
                                            </span>
                                            <x-icon name="check-circle" variant="solid"
                                                    class="h-5 w-5 shrink-0 text-brand-600"
                                                    x-show="method === 'bank_transfer'" x-cloak />
                                        </span>
                                    </label>
                                </div>
                                @if(! $paystack_enabled)
                                    <p class="mt-2 text-xs text-muted-foreground">
                                        Card payment is not configured on this environment — bank transfer still works.
                                    </p>
                                @endif
                            </fieldset>

                            {{-- Summary --}}
                            <dl class="space-y-3 rounded-lg border border-border bg-surface p-4 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-muted-foreground">Amount to fund</dt>
                                    <dd class="font-medium tabular-nums" x-text="format(numericAmount)"></dd>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-muted-foreground">Processing fee</dt>
                                    <dd class="font-medium tabular-nums" x-text="format(fee)"></dd>
                                </div>
                                <div class="flex items-center justify-between gap-3 border-t border-border pt-3">
                                    <dt class="font-medium">Total to pay</dt>
                                    <dd class="text-lg font-semibold tabular-nums text-brand-700" x-text="format(total)"></dd>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Fees are capped where a percentage is used, so large top-ups stay reasonable.
                                </p>
                            </dl>

                            <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="submitting">
                                <x-icon name="lock-closed" class="h-4 w-4" />
                                <span x-show="!submitting" x-text="method === 'bank_transfer' ? 'Get bank details' : 'Proceed to payment'"></span>
                                <span x-show="submitting" x-cloak>Processing&hellip;</span>
                            </button>

                            <div class="flex items-start gap-3 rounded-lg border border-border bg-surface p-4">
                                <x-icon name="shield-check" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">Secure payment</p>
                                    <p class="mt-0.5 text-sm text-muted-foreground">
                                        Payments are processed by our payment provider. ReUp never stores your card details.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">

                {{-- How it works --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">How funding works</h2>
                    </div>
                    <ol class="card-content space-y-4 text-sm">
                        @foreach([
                            ['Select amount', 'Choose how much to add — &#8358;' . number_format((float) $min_amount) . ' minimum.'],
                            ['Choose method', 'Card is instant; bank transfer is matched automatically.'],
                            ['Complete payment', 'Follow the instructions for the method you picked.'],
                            ['Wallet credited', 'Immediately for card, within minutes for bank transfer.'],
                        ] as $index => $step)
                            <li class="flex items-start gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                                    {{ $index + 1 }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block font-medium">{{ $step[0] }}</span>
                                    <span class="block text-xs text-muted-foreground">{!! $step[1] !!}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </section>

                {{-- Fees --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Processing fees</h2>
                        <p class="card-description">Applied automatically at checkout.</p>
                    </div>
                    <dl class="card-content space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Card payment</dt>
                            <dd class="font-medium tabular-nums">
                                {{ rtrim(rtrim(number_format($paystackPercentage, 2), '0'), '.') }}%
                                + &#8358;{{ number_format($paystackAdditional, 2) }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Bank transfer</dt>
                            <dd class="font-medium tabular-nums">&#8358;{{ number_format($bankFixedFee, 2) }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Recent funding --}}
                @if($recent_funding && $recent_funding->isNotEmpty())
                    <section class="card">
                        <div class="card-header">
                            <h2 class="card-title">Recent funding</h2>
                        </div>
                        <ul class="card-content space-y-3">
                            @foreach($recent_funding as $funding)
                                <li class="flex items-center justify-between gap-3 border-b border-border pb-3 last:border-0 last:pb-0">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium tabular-nums">&#8358;{{ number_format((float) $funding->amount, 2) }}</p>
                                        <p class="text-xs text-muted-foreground">{{ $funding->created_at->format('M j, Y') }}</p>
                                    </div>
                                    <span class="badge {{ $statusBadges[$funding->status] ?? 'badge-neutral' }}">
                                        {{ ucfirst((string) $funding->status) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Support --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Need help?</h2>
                        <p class="card-description">For payment issues or questions.</p>
                    </div>
                    <div class="card-content space-y-3 text-sm">
                        <div class="rounded-lg border border-border bg-surface p-3">
                            <p class="font-medium">Support team</p>
                            <div class="mt-2 space-y-1.5 text-xs text-muted-foreground">
                                @if(!empty($siteSettings['contact_phone']))
                                    <p class="flex items-center gap-2">
                                        <x-icon name="phone" class="h-3.5 w-3.5 shrink-0 text-ink-400" />
                                        <span class="tabular-nums">{{ $siteSettings['contact_phone'] }}</span>
                                    </p>
                                @endif
                                @if(!empty($siteSettings['support_email']))
                                    <p class="flex items-center gap-2">
                                        <x-icon name="envelope" class="h-3.5 w-3.5 shrink-0 text-ink-400" />
                                        <span class="break-all">{{ $siteSettings['support_email'] }}</span>
                                    </p>
                                @endif
                                <p class="flex items-center gap-2">
                                    <x-icon name="clock" class="h-3.5 w-3.5 shrink-0 text-ink-400" />
                                    <span>Around the clock</span>
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('contact') }}" class="btn btn-outline btn-sm w-full">
                            <x-icon name="lifebuoy" class="h-4 w-4" />
                            Contact support
                        </a>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>
@endsection

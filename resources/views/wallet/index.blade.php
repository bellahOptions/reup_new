@extends('layouts.app')
@section('title', 'Wallet')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Wallet overview
    |--------------------------------------------------------------------------
    | WalletController::index() passes `$wallet`, `$recentTransactions` and
    | `$monthlyStats`. The previous view read `$recent_transactions`, which is
    | never shared, so the recent-activity block could not render at all; the
    | controller's actual variable is used here.
    |
    | `$monthlyStats` keys: spent, funded, transactions, today_volume.
    */
    $user = auth()->user();

    $balance = (float) ($wallet->balance ?? $user->wallet_balance ?? 0);
    $spentToday = (float) ($monthlyStats['today_volume'] ?? 0);
    $spentThisMonth = (float) ($monthlyStats['spent'] ?? 0);
    $fundedThisMonth = (float) ($monthlyStats['funded'] ?? 0);
    $transactionCount = (int) ($monthlyStats['transactions'] ?? 0);

    // Average daily spend across the days elapsed this month, not across the
    // month's full length — dividing by 30 on the 2nd would understate it.
    $daysElapsed = max(1, now()->day);
    $averageDaily = $spentThisMonth / $daysElapsed;

    // Service glyphs. The old view switched on emoji here; the icon component
    // only renders names from its own sets, so every name is whitelisted.
    $serviceIcons = [
        'airtime' => 'device-phone-mobile',
        'data' => 'wifi',
        'cable-tv' => 'tv',
        'electricity' => 'bolt',
        'exam' => 'academic-cap',
        'waec' => 'document-check',
        'jamb' => 'academic-cap',
        'funding' => 'credit-card',
        'wallet_funding' => 'credit-card',
        'refund' => 'arrow-path',
        'transfer' => 'arrows-up-down',
    ];

    $statusBadges = [
        'success' => 'badge-success',
        'pending' => 'badge-warning',
        'processing' => 'badge-info',
        'verifying' => 'badge-info',
        'failed' => 'badge-destructive',
        'cancelled' => 'badge-neutral',
    ];

    $statusIcons = [
        'success' => 'check-circle',
        'pending' => 'clock',
        'processing' => 'arrow-path',
        'verifying' => 'finger-print',
        'failed' => 'x-mark',
        'cancelled' => 'no-symbol',
    ];

    $quickActions = [
        [
            'label' => 'Fund wallet',
            'description' => 'Add money',
            'icon' => 'credit-card',
            'href' => route('wallet.fund'),
            'tint' => 'border-brand-200 bg-brand-50 text-brand-700',
        ],
        [
            // The pay-in account. Its own entry rather than a link buried in the
            // funding page, because "where do I send a bank transfer?" is a
            // different question from "how do I pay by card".
            'label' => 'My account number',
            'description' => 'Fund by transfer',
            'icon' => 'building-library',
            'href' => route('wallet.virtual-account'),
            'tint' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        ],
        [
            'label' => 'Buy airtime',
            'description' => 'Instant recharge',
            'icon' => 'device-phone-mobile',
            'href' => route('airtime-data.index'),
            'tint' => 'border-sky-200 bg-sky-50 text-sky-700',
        ],
        [
            'label' => 'Buy data',
            'description' => 'Fast delivery',
            'icon' => 'wifi',
            'href' => route('airtime-data.index'),
            'tint' => 'border-violet-200 bg-violet-50 text-violet-700',
        ],
        [
            'label' => 'Pay TV',
            'description' => 'Cable subscription',
            'icon' => 'tv',
            'href' => route('cable-tv.index'),
            'tint' => 'border-amber-200 bg-amber-50 text-amber-700',
        ],
    ];

    $paymentMethods = [
        ['name' => 'Bank transfer', 'detail' => 'Instant &middot; no fees', 'icon' => 'building-library'],
        ['name' => 'Card payment', 'detail' => 'Visa &middot; Mastercard', 'icon' => 'credit-card'],
        ['name' => 'Wallet balance', 'detail' => 'Spend what you have', 'icon' => 'wallet'],
    ];
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-12">

        {{-- Page heading --}}
        <header class="mb-8 flex flex-wrap items-end justify-between gap-4 md:mb-10">
            <div>
                <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Wallet management</h1>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                    Your balance, recent movements and the fastest routes to funding.
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('wallet.history') }}" class="btn btn-outline">
                    <x-icon name="queue-list" class="h-4 w-4" />
                    History
                </a>
                <a href="{{ route('wallet.fund') }}" class="btn btn-primary">
                    <x-icon name="plus" class="h-4 w-4" />
                    Fund wallet
                </a>
            </div>
        </header>

        {{-- Balance: the single accent surface on this page. --}}
        <section class="mb-6 rounded-xl border border-brand-600 bg-brand-500 p-5 text-primary-foreground shadow-subtle md:p-6">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <div>
                    <p class="text-sm font-medium text-brand-50">Available balance</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums md:text-4xl">
                        &#8358;{{ number_format($balance, 2) }}
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="badge border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground">
                            <x-icon name="check-circle" variant="solid" class="h-3.5 w-3.5" />
                            Active
                        </span>
                        <span class="badge border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground">
                            <x-icon name="bolt" variant="solid" class="h-3.5 w-3.5" />
                            Instant
                        </span>
                    </div>
                </div>
                <p class="text-xs text-brand-50">Last updated {{ now()->format('jS M, Y') }}</p>
            </div>

            <dl class="mt-6 grid grid-cols-2 gap-4 border-t border-primary-foreground/20 pt-5 md:grid-cols-4">
                <div>
                    <dt class="text-xs text-brand-50">Spent today</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums">&#8358;{{ number_format($spentToday, 2) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-brand-50">Spent this month</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums">&#8358;{{ number_format($spentThisMonth, 2) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-brand-50">Funded this month</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums">&#8358;{{ number_format($fundedThisMonth, 2) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-brand-50">Movements</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums">{{ number_format($transactionCount) }}</dd>
                </div>
            </dl>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">

                {{-- Quick actions --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Quick actions</h2>
                        <p class="card-description">What would you like to do next?</p>
                    </div>
                    <div class="card-content grid grid-cols-2 gap-3 md:grid-cols-4">
                        @foreach($quickActions as $action)
                            <a href="{{ $action['href'] }}"
                               class="flex flex-col items-center gap-2 rounded-xl border border-border p-4 text-center transition-colors hover:border-ink-300 hover:bg-ink-50">
                                <span class="flex h-11 w-11 items-center justify-center rounded-lg border {{ $action['tint'] }}">
                                    <x-icon :name="$action['icon']" class="h-5 w-5" />
                                </span>
                                <span class="text-sm font-medium">{{ $action['label'] }}</span>
                                <span class="text-xs text-muted-foreground">{{ $action['description'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>

                {{-- Recent activity --}}
                <section class="card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                        <div>
                            <h2 class="card-title">Recent transactions</h2>
                            <p class="card-description">Your last {{ $recentTransactions->count() }} movements.</p>
                        </div>
                        <a href="{{ route('wallet.history') }}" class="btn btn-outline btn-sm shrink-0">
                            View all
                            <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    </div>

                    @if($recentTransactions->isEmpty())
                        <div class="empty-state">
                            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                                <x-icon name="inbox" class="h-6 w-6" />
                            </span>
                            <h3 class="mt-4 text-base font-semibold">No transactions yet</h3>
                            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                                Fund your wallet and your activity will appear here.
                            </p>
                            <a href="{{ route('wallet.fund') }}" class="btn btn-primary btn-sm mt-5">Fund wallet</a>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th scope="col">Description</th>
                                        <th scope="col">Date</th>
                                        <th scope="col" class="text-right">Amount</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentTransactions as $transaction)
                                        <tr>
                                            <td>
                                                <span class="flex items-start gap-2.5">
                                                    <x-icon :name="$serviceIcons[$transaction->service_type] ?? 'arrow-path'"
                                                            class="mt-0.5 h-4 w-4 shrink-0 text-ink-500" />
                                                    <span class="min-w-0">
                                                        <span class="block text-sm font-medium">
                                                            {{ $transaction->description ?: ucfirst((string) $transaction->service_type) }}
                                                        </span>
                                                        @if($transaction->reference)
                                                            <span class="mt-0.5 block font-mono text-xs text-muted-foreground">
                                                                {{ $transaction->reference }}
                                                            </span>
                                                        @endif
                                                    </span>
                                                </span>
                                            </td>
                                            <td class="whitespace-nowrap">
                                                <span class="block text-sm">{{ $transaction->created_at->format('M j, Y') }}</span>
                                                <span class="block text-xs text-muted-foreground">{{ $transaction->created_at->format('g:i A') }}</span>
                                            </td>
                                            <td class="whitespace-nowrap text-right">
                                                <span class="text-sm font-semibold tabular-nums {{ $transaction->type === 'credit' ? 'text-green-700' : 'text-foreground' }}">
                                                    {{ $transaction->type === 'credit' ? '+' : '-' }}&#8358;{{ number_format((float) $transaction->amount, 2) }}
                                                </span>
                                            </td>
                                            <td class="whitespace-nowrap">
                                                <span class="badge {{ $statusBadges[$transaction->status] ?? 'badge-neutral' }}">
                                                    <x-icon :name="$statusIcons[$transaction->status] ?? 'information-circle'" class="h-3.5 w-3.5" />
                                                    {{ ucfirst((string) $transaction->status) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">

                {{-- Spending snapshot --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">This month</h2>
                        <p class="card-description">A quick read on your spending pace.</p>
                    </div>
                    <dl class="card-content space-y-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="stat-label">Total spent</dt>
                            <dd class="stat-value text-lg">&#8358;{{ number_format($spentThisMonth, 2) }}</dd>
                        </div>
                        <div class="divider"></div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="stat-label">Average per day</dt>
                            <dd class="text-lg font-semibold tabular-nums">&#8358;{{ number_format($averageDaily, 2) }}</dd>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Across {{ $daysElapsed }} {{ Str::plural('day', $daysElapsed) }} so far this month.
                        </p>
                    </dl>
                </section>

                {{-- Payment methods --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Payment methods</h2>
                        <p class="card-description">All available on your account.</p>
                    </div>
                    <ul class="card-content space-y-3">
                        @foreach($paymentMethods as $method)
                            <li class="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                                <span class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600">
                                        <x-icon :name="$method['icon']" class="h-4 w-4" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium">{{ $method['name'] }}</span>
                                        <span class="block text-xs text-muted-foreground">{!! $method['detail'] !!}</span>
                                    </span>
                                </span>
                                <x-icon name="check" variant="solid" class="h-4 w-4 shrink-0 text-success-soft-foreground" />
                            </li>
                        @endforeach
                    </ul>
                    <div class="card-footer">
                        <a href="{{ route('wallet.virtual-account') }}" class="btn btn-outline btn-sm w-full">
                            <x-icon name="building-library" class="h-4 w-4" />
                            Get my account number
                        </a>
                    </div>
                </section>

                {{-- Tips --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Wallet tips</h2>
                    </div>
                    <ul class="card-content space-y-3 text-sm text-muted-foreground">
                        <li class="flex items-start gap-2.5">
                            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <span>Keep at least &#8358;500 available for instant transactions.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <span>Fund ahead of peak hours so a recharge never waits.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <span>Keep the reference of any failed transaction to hand.</span>
                        </li>
                    </ul>
                </section>

                {{-- Support --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Need help?</h2>
                        <p class="card-description">For wallet issues or questions.</p>
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

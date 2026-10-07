@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

{{--
    Customer dashboard.

    Every figure on this page comes from DashboardController — the previous
    version recomputed the statistics itself against an assumed contract and
    read variables the controller never passed, so the page threw. The
    controller is now the single source of truth:

        $user               App\Models\User
        $userStats          balance, total_transactions, successful_transactions,
                            pending_transactions, spent_this_month, funded_this_month
        $recentTransactions Collection of App\Models\Transactions (max 10, NOT a paginator)
        $transactionStats   total_spent, average_transaction, last_transaction_date
        $recentActivity     last_login, account_created, status
        $userChats          Collection of App\Models\ChatSession
        $quickActions       [{ title, route (bare route name), icon }]
        $announcements      Collection of App\Models\PromotionNotification

    Everything below is presentation only: no statistics are recomputed here,
    there is no `$promotionsNotifications` reference and no fallback data.
--}}

@php
    /*
    | Presentation maps only — these never touch the database.
    |
    | `Transactions::service_type` and `->status` are nullable, so both the
    | glyph and the badge class are looked up defensively. The badge *colours*
    | come from the model accessors (`service_type_badge`, `status_badge`).
    */
    $serviceTypeIcons = [
        'airtime' => 'device-phone-mobile',
        'data' => 'wifi',
        'cable-tv' => 'tv',
        'electricity' => 'bolt',
        'exam' => 'academic-cap',
        'funding' => 'credit-card',
        'refund' => 'arrow-path',
        'transfer' => 'arrows-up-down',
    ];

    $statusIcons = [
        'success' => 'check-circle',
        'pending' => 'clock',
        'processing' => 'arrow-path',
        'verifying' => 'finger-print',
        'failed' => 'x-mark',
        'cancelled' => 'no-symbol',
    ];

    $dashboardFilterStatuses = ['pending', 'processing', 'success', 'failed', 'cancelled', 'verifying'];
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-12">

        {{-- Page heading --}}
        <header class="mb-8 md:mb-10">
            <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Welcome back, {{ $user->name }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                Here is what is happening with your account today.
            </p>

            {{-- Once the introduction has been dismissed it stays reachable here,
                 so it is a one-time greeting rather than a one-time opportunity. --}}
            @if(! $showTips)
                <form method="POST" action="{{ route('tips.replay') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="link inline-flex items-center gap-1.5 text-xs font-medium">
                        <x-icon name="arrow-path" class="h-3.5 w-3.5" />
                        Show the getting-started tips again
                    </button>
                </form>
            @endif
        </header>

        @if($showTips)
            @include('partials.dashboard-tips')
        @endif

        {{-- Announcements. Rendered only when the controller found live
             promotions — no fallback array, no placeholder copy. The marquee
             accepts the models directly and renders nothing when empty. --}}
        @if($announcements->isNotEmpty())
            <div class="card mb-8 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border px-5 py-3">
                    <x-icon name="megaphone" class="h-4 w-4 text-brand-600" />
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">Announcements</span>
                </div>
                <div class="px-2 py-1">
                    <x-marquee :items="$announcements" speed="40" :pauseOnHover="true" compact />
                </div>
            </div>
        @endif

        {{-- Figures. Exactly one accent surface on the page: the balance card. --}}
        <div class="mb-8 grid grid-cols-1 gap-4 md:grid-cols-3 md:gap-6">
            <div class="rounded-xl border border-brand-600 bg-brand-500 p-5 text-white shadow-subtle md:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-brand-50">Total balance</p>
                        <p class="mt-2 text-3xl font-semibold tabular-nums md:text-4xl">
                            &#8358;{{ number_format((float) $userStats['balance'], 2) }}
                        </p>
                    </div>
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white/15">
                        <x-icon name="wallet" variant="solid" class="h-5 w-5 text-white" />
                    </span>
                </div>
                <a href="{{ \Illuminate\Support\Facades\Route::has('wallet.fund') ? route('wallet.fund') : route('wallet.index') }}"
                   class="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-white underline-offset-4 hover:underline">
                    Fund wallet
                    <x-icon name="chevron-right" class="h-4 w-4" />
                </a>
            </div>

            <div class="card p-5 md:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="stat-label">Transactions</p>
                        <p class="stat-value mt-2">{{ number_format((int) $userStats['total_transactions']) }}</p>
                    </div>
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600">
                        <x-icon name="queue-list" class="h-5 w-5" />
                    </span>
                </div>
                <div class="mt-6 space-y-1.5 text-sm text-muted-foreground">
                    <p>
                        <span class="font-medium text-foreground tabular-nums">&#8358;{{ number_format((float) $userStats['spent_this_month'], 2) }}</span>
                        spent this month
                    </p>
                    <p>
                        <span class="font-medium text-foreground tabular-nums">{{ number_format((int) $userStats['successful_transactions']) }}</span>
                        successful &middot;
                        <span class="font-medium text-foreground tabular-nums">{{ number_format((int) $userStats['pending_transactions']) }}</span>
                        pending
                    </p>
                </div>
            </div>

            <div class="card p-5 md:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="stat-label">Funded this month</p>
                        <p class="stat-value mt-2">&#8358;{{ number_format((float) $userStats['funded_this_month'], 2) }}</p>
                    </div>
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600">
                        <x-icon name="banknotes" class="h-5 w-5" />
                    </span>
                </div>
                <div class="mt-6 space-y-1.5 text-sm text-muted-foreground">
                    <p>
                        Lifetime spend
                        <span class="font-medium text-foreground tabular-nums">&#8358;{{ number_format((float) $transactionStats['total_spent'], 2) }}</span>
                    </p>
                    <p>
                        Average
                        <span class="font-medium text-foreground tabular-nums">&#8358;{{ number_format((float) $transactionStats['average_transaction'], 2) }}</span>
                    </p>
                    <p class="inline-flex items-center gap-1.5 text-xs">
                        <x-icon name="clock" class="h-4 w-4 text-ink-400" />
                        @if($transactionStats['last_transaction_date'])
                            Last activity {{ \Illuminate\Support\Carbon::parse($transactionStats['last_transaction_date'])->format('M j, Y') }}
                        @else
                            No activity yet
                        @endif
                    </p>
                </div>
            </div>
        </div>

        {{-- Quick actions. `route` is a bare route name from the controller; a
             name that is not registered degrades to '#' rather than throwing. --}}
        <section class="mb-8 md:mb-10">
            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="mt-2 text-xl font-semibold">Quick actions</h2>
                </div>
                <p class="text-sm text-muted-foreground">Fast access to the services you use most.</p>
            </div>

            @if(! empty($quickActions))
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($quickActions as $action)
                        @php
                            // Most actions are internal routes; support is an
                            // external WhatsApp link, so `url` wins when present.
                            $href = $action['url']
                                ?? (\Illuminate\Support\Facades\Route::has($action['route'] ?? '') ? route($action['route']) : '#');
                            $external = ! empty($action['url']);
                        @endphp
                        <a href="{{ $href }}"
                           @if($external) target="_blank" rel="noopener noreferrer" @endif
                           class="card group flex items-center gap-4 p-5 transition-colors hover:border-brand-300 hover:bg-accent">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-border bg-white text-brand-600">
                                <x-icon :name="$action['icon']" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 text-sm font-semibold text-foreground">{{ $action['title'] }}</span>
                            <x-icon name="arrow-up-right" class="ml-auto h-4 w-4 shrink-0 text-ink-400 transition-colors group-hover:text-brand-600" />
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Recent transactions --}}
        <section class="card overflow-hidden">
            <div class="card-header flex-row flex-wrap items-center justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="card-title">Recent transactions</h2>
                    <p class="card-description">Your latest ten movements.</p>
                </div>
                <a href="{{ route('transactions.index') }}" class="btn btn-outline btn-sm shrink-0">
                    View all
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            </div>

            {{-- GET filter form. It submits only the three params the controller
                 validates — type, status, date — and nothing else. --}}
            <form method="GET" action="{{ route('dashboard') }}"
                  class="grid grid-cols-1 gap-3 border-b border-border bg-surface px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="label" for="dashboard-type">Type</label>
                    <select id="dashboard-type" name="type" class="select mt-1.5">
                        <option value="">All types</option>
                        <option value="credit" @selected(request('type') === 'credit')>Credit</option>
                        <option value="debit" @selected(request('type') === 'debit')>Debit</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="dashboard-status">Status</label>
                    <select id="dashboard-status" name="status" class="select mt-1.5">
                        <option value="">All statuses</option>
                        @foreach($dashboardFilterStatuses as $statusOption)
                            <option value="{{ $statusOption }}" @selected(request('status') === $statusOption)>{{ ucfirst($statusOption) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="dashboard-date">Date</label>
                    <input id="dashboard-date" type="date" name="date" value="{{ request('date') }}" class="input mt-1.5">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="funnel" class="h-4 w-4" />
                        Apply
                    </button>
                    <a href="{{ route('dashboard') }}" class="btn btn-ghost btn-sm">Reset</a>
                </div>
            </form>

            @forelse($recentTransactions as $transaction)
                @if($loop->first)
                    <div class="card-content overflow-x-auto p-0">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Description</th>
                                    <th scope="col">Reference</th>
                                    <th scope="col" class="text-right">Amount</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                @endif

                            <tr>
                                <td class="whitespace-nowrap">
                                    <span class="block text-sm font-medium">{{ $transaction->created_at?->format('M j, Y') ?? '—' }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ $transaction->created_at?->format('g:i A') }}</span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="badge {{ $transaction->type === 'credit' ? 'badge-success' : 'badge-neutral' }}">
                                        <x-icon :name="$transaction->type === 'credit' ? 'arrow-down-circle' : 'arrow-up-circle'" class="h-3.5 w-3.5" />
                                        {{ $transaction->type ? ucfirst($transaction->type) : 'Unknown' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="flex items-start gap-2.5">
                                        <x-icon :name="$serviceTypeIcons[$transaction->service_type] ?? 'arrow-path'"
                                                class="mt-0.5 h-4 w-4 text-ink-500" />
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium">{{ $transaction->description ?: 'Transaction' }}</span>
                                            @if($transaction->service_type)
                                                <span class="badge {{ $transaction->service_type_badge }} mt-1">
                                                    {{ ucwords(str_replace('-', ' ', $transaction->service_type)) }}
                                                </span>
                                            @endif
                                            @if($transaction->recipient)
                                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                                    To {{ $transaction->recipient }}@if($transaction->provider) &middot; {{ $transaction->provider }}@endif
                                                </span>
                                            @endif
                                        </span>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="font-mono text-xs text-muted-foreground">{{ $transaction->reference ?: '—' }}</span>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <span class="block text-sm font-semibold tabular-nums {{ $transaction->type === 'credit' ? 'text-green-700' : 'text-foreground' }}">
                                        {{ $transaction->type === 'credit' ? '+' : '-' }}&#8358;{{ number_format((float) $transaction->amount, 2) }}
                                    </span>

                                    {{--
                                        Before → after, so the effect on the wallet is
                                        legible without doing arithmetic. Both columns are
                                        written by WalletService at the moment of movement,
                                        so they are the authoritative pair — the running
                                        balance is not recomputed here.

                                        They are null while a transaction is still
                                        pending, because no money has moved yet; showing
                                        the current balance next to a pending row would
                                        read as though it already had.
                                    --}}
                                    @if(! is_null($transaction->balance_before) && ! is_null($transaction->balance_after))
                                        <span class="mt-1 block text-xs tabular-nums text-muted-foreground">
                                            <span title="Balance before this transaction">&#8358;{{ number_format((float) $transaction->balance_before, 2) }}</span>
                                            <span aria-hidden="true" class="px-0.5">&rarr;</span>
                                            <span class="font-medium text-foreground" title="Balance after this transaction">&#8358;{{ number_format((float) $transaction->balance_after, 2) }}</span>
                                        </span>
                                    @elseif(! is_null($transaction->balance_after))
                                        <span class="mt-1 block text-xs tabular-nums text-muted-foreground">
                                            Balance &#8358;{{ number_format((float) $transaction->balance_after, 2) }}
                                        </span>
                                    @elseif(in_array($transaction->status, ['pending', 'processing'], true))
                                        <span class="mt-1 block text-xs text-muted-foreground">
                                            Balance unchanged while pending
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="badge {{ $transaction->status_badge }}">
                                        <x-icon :name="$statusIcons[$transaction->status] ?? 'information-circle'" class="h-3.5 w-3.5" />
                                        {{ $transaction->status ? ucfirst($transaction->status) : 'Unknown' }}
                                    </span>
                                </td>
                            </tr>

                @if($loop->last)
                            </tbody>
                        </table>
                    </div>
                @endif
            @empty
                <div class="empty-state card-content">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                        <x-icon name="inbox" class="h-6 w-6" />
                    </span>
                    <h3 class="mt-4 text-base font-semibold">No transactions found</h3>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                        Nothing matches the current filters. Reset them, or make your first purchase to start a history.
                    </p>
                    <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                        <a href="{{ route('dashboard') }}" class="btn btn-outline btn-sm">Reset filters</a>
                        <a href="{{ \Illuminate\Support\Facades\Route::has('airtime-data.index') ? route('airtime-data.index') : '#' }}"
                           class="btn btn-primary btn-sm">
                            Make your first transaction
                        </a>
                    </div>
                </div>
            @endforelse
        </section>
    </div>
</main>
@endsection

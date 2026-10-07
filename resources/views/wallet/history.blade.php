@extends('layouts.app')
@section('title', 'Wallet history')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Wallet history
    |--------------------------------------------------------------------------
    | WalletController::history() passes `$wallet` and a paginated
    | `$transactions`. The previous view ignored both and rendered three
    | hardcoded sample rows with a decorative client-side filter.
    */
    $serviceIcons = [
        'airtime' => 'device-phone-mobile',
        'data' => 'wifi',
        'cable-tv' => 'tv',
        'electricity' => 'bolt',
        'exam' => 'academic-cap',
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

    $statusLabels = [
        'processing' => 'Processing',
        'verifying' => 'Verifying',
    ];

    // Wallet-wide totals come from the wallet row, not from the current page
    // of results, so the summary does not change when the user paginates.
    $lifetimeSpent = (float) ($wallet->total_spent ?? 0);
    $lifetimeFunded = (float) ($wallet->total_funded ?? 0);
    $lifetimeCount = (int) ($wallet->transaction_count ?? 0);
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-12">

        {{-- Page heading --}}
        <header class="mb-8 flex flex-wrap items-end justify-between gap-4 md:mb-10">
            <div>
                <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Wallet history</h1>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                    Every credit and debit recorded on your wallet.
                </p>
            </div>
            <a href="{{ route('wallet.fund') }}" class="btn btn-primary shrink-0">
                <x-icon name="plus" class="h-4 w-4" />
                Fund wallet
            </a>
        </header>

        {{-- Balance summary: the single accent surface on this page. --}}
        <div class="mb-8 rounded-xl border border-brand-600 bg-brand-500 p-5 text-white shadow-subtle md:p-6">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <div>
                    <p class="text-sm font-medium text-brand-50">Available balance</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums md:text-4xl">
                        &#8358;{{ number_format((float) ($wallet->balance ?? 0), 2) }}
                    </p>
                </div>
                <dl class="grid grid-cols-3 gap-4 text-sm sm:gap-8">
                    <div>
                        <dt class="text-brand-50">Lifetime spent</dt>
                        <dd class="mt-1 font-semibold tabular-nums">&#8358;{{ number_format($lifetimeSpent, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-brand-50">Lifetime funded</dt>
                        <dd class="mt-1 font-semibold tabular-nums">&#8358;{{ number_format($lifetimeFunded, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-brand-50">Movements</dt>
                        <dd class="mt-1 font-semibold tabular-nums">{{ number_format($lifetimeCount) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- Filters: a real GET form against WalletController::history(). --}}
        <form method="GET" action="{{ route('wallet.history') }}" class="card mb-6">
            <div class="card-content grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="label" for="search">Search</label>
                    <div class="relative mt-1.5">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                            <x-icon name="magnifying-glass" class="h-4 w-4" />
                        </span>
                        <input type="search" id="search" name="search" value="{{ request('search') }}"
                               placeholder="Reference or description" class="input pl-9">
                    </div>
                </div>

                <div>
                    <label class="label" for="type">Type</label>
                    <select id="type" name="type" class="select mt-1.5">
                        <option value="">All types</option>
                        <option value="credit" @selected(request('type') === 'credit')>Credit</option>
                        <option value="debit" @selected(request('type') === 'debit')>Debit</option>
                    </select>
                </div>

                <div>
                    <label class="label" for="status">Status</label>
                    <select id="status" name="status" class="select mt-1.5">
                        <option value="">All statuses</option>
                        @foreach(['success' => 'Success', 'pending' => 'Pending', 'processing' => 'Processing', 'verifying' => 'Verifying', 'failed' => 'Failed', 'cancelled' => 'Cancelled'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label" for="from_date">From date</label>
                    <input type="date" id="from_date" name="from_date" value="{{ request('from_date') }}" class="input mt-1.5">
                </div>

                <div>
                    <label class="label" for="to_date">To date</label>
                    <input type="date" id="to_date" name="to_date" value="{{ request('to_date') }}" class="input mt-1.5">
                </div>

                <div class="flex items-end gap-2 lg:col-span-2">
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="funnel" class="h-4 w-4" />
                        Apply filters
                    </button>
                    <a href="{{ route('wallet.history') }}" class="btn btn-ghost">Reset</a>
                </div>
            </div>
        </form>

        {{-- Ledger --}}
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 class="card-title">Transactions</h2>
                    <p class="card-description">
                        {{ number_format($transactions->total()) }}
                        {{ Str::plural('record', $transactions->total()) }}
                    </p>
                </div>
                <a href="{{ route('transactions.index') }}" class="btn btn-outline btn-sm shrink-0">
                    Statement view
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            </div>

            @if($transactions->isEmpty())
                <div class="empty-state">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                        <x-icon name="inbox" class="h-6 w-6" />
                    </span>
                    <h3 class="mt-4 text-base font-semibold">No transactions found</h3>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                        @if(request()->hasAny(['search', 'type', 'status', 'from_date', 'to_date']))
                            No records match these filters. Try widening your search.
                        @else
                            Your wallet activity will appear here after your first funding or purchase.
                        @endif
                    </p>
                    <div class="mt-5 flex flex-wrap justify-center gap-3">
                        @if(request()->hasAny(['search', 'type', 'status', 'from_date', 'to_date']))
                            <a href="{{ route('wallet.history') }}" class="btn btn-outline btn-sm">Clear filters</a>
                        @endif
                        <a href="{{ route('airtime-data.index') }}" class="btn btn-primary btn-sm">Start a transaction</a>
                    </div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Type</th>
                                <th scope="col">Description</th>
                                <th scope="col">Reference</th>
                                <th scope="col" class="text-right">Amount</th>
                                <th scope="col" class="text-right">Balance</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $transaction)
                                <tr>
                                    <td class="whitespace-nowrap">
                                        <span class="block text-sm font-medium">{{ $transaction->created_at->format('M j, Y') }}</span>
                                        <span class="block text-xs text-muted-foreground">{{ $transaction->created_at->format('g:i A') }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="badge {{ $transaction->type === 'credit' ? 'badge-success' : 'badge-neutral' }}">
                                            <x-icon :name="$transaction->type === 'credit' ? 'arrow-down-circle' : 'arrow-up-circle'" class="h-3.5 w-3.5" />
                                            {{ ucfirst((string) $transaction->type) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="flex items-start gap-2.5">
                                            <x-icon :name="$serviceIcons[$transaction->service_type] ?? 'arrow-path'"
                                                    class="mt-0.5 h-4 w-4 shrink-0 text-ink-500" />
                                            <span class="min-w-0">
                                                <span class="block text-sm font-medium">
                                                    {{ $transaction->description ?: ucfirst((string) $transaction->service_type) }}
                                                </span>
                                                @if($transaction->recipient)
                                                    <span class="mt-0.5 block text-xs text-muted-foreground">
                                                        To {{ $transaction->recipient }}@if($transaction->provider) &middot; {{ $transaction->provider }}@endif
                                                    </span>
                                                @endif
                                            </span>
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="font-mono text-xs text-muted-foreground">{{ $transaction->reference }}</span>
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        <span class="text-sm font-semibold tabular-nums {{ $transaction->type === 'credit' ? 'text-green-700' : 'text-foreground' }}">
                                            {{ $transaction->type === 'credit' ? '+' : '-' }}&#8358;{{ number_format((float) $transaction->amount, 2) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        @if(is_null($transaction->balance_after))
                                            <span class="text-sm text-muted-foreground">&mdash;</span>
                                        @else
                                            <span class="text-sm tabular-nums text-muted-foreground">
                                                &#8358;{{ number_format((float) $transaction->balance_after, 2) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="badge {{ $statusBadges[$transaction->status] ?? 'badge-neutral' }}">
                                            <x-icon :name="$statusIcons[$transaction->status] ?? 'information-circle'" class="h-3.5 w-3.5" />
                                            {{ $statusLabels[$transaction->status] ?? ucfirst((string) $transaction->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($transactions->hasPages())
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-4">
                        <p class="text-sm text-muted-foreground">
                            Showing
                            <span class="font-medium text-foreground tabular-nums">{{ $transactions->firstItem() }}</span>
                            to
                            <span class="font-medium text-foreground tabular-nums">{{ $transactions->lastItem() }}</span>
                            of
                            <span class="font-medium text-foreground tabular-nums">{{ $transactions->total() }}</span>
                        </p>
                        <div class="flex items-center gap-2">
                            @if($transactions->onFirstPage())
                                <span class="btn btn-outline btn-sm pointer-events-none opacity-50" aria-disabled="true">
                                    <x-icon name="chevron-left" class="h-4 w-4" />
                                    Previous
                                </span>
                            @else
                                <a href="{{ $transactions->previousPageUrl() }}" class="btn btn-outline btn-sm" rel="prev">
                                    <x-icon name="chevron-left" class="h-4 w-4" />
                                    Previous
                                </a>
                            @endif

                            <span class="px-1 text-sm text-muted-foreground tabular-nums">
                                Page {{ $transactions->currentPage() }} of {{ $transactions->lastPage() }}
                            </span>

                            @if($transactions->hasMorePages())
                                <a href="{{ $transactions->nextPageUrl() }}" class="btn btn-outline btn-sm" rel="next">
                                    Next
                                    <x-icon name="chevron-right" class="h-4 w-4" />
                                </a>
                            @else
                                <span class="btn btn-outline btn-sm pointer-events-none opacity-50" aria-disabled="true">
                                    Next
                                    <x-icon name="chevron-right" class="h-4 w-4" />
                                </span>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </section>
    </div>
</main>
@endsection

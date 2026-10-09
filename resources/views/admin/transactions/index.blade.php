@extends('admin.layouts.app')

@section('title', 'Transactions')
@section('page-title', 'Transactions')
@section('page-description', 'Search, filter and inspect every transaction on the platform.')

@section('content')
@php
    $serviceTypeLabels = [
        'airtime' => 'Airtime',
        'data' => 'Data',
        'funding' => 'Funding',
        'transfer' => 'Transfer',
        'cable-tv' => 'Cable TV',
        'electricity' => 'Electricity',
        'exam' => 'Exam',
    ];

    $serviceTypeIcons = [
        'airtime' => 'device-phone-mobile',
        'data' => 'signal',
        'funding' => 'wallet',
        'transfer' => 'arrow-path',
        'cable-tv' => 'tv',
        'electricity' => 'bolt',
        'exam' => 'academic-cap',
    ];

    $hasFilters = request()->hasAny(['search', 'service_type', 'status', 'payment_method', 'date_from', 'date_to', 'min_amount', 'max_amount']);

    $successRate = $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100, 1) : 0;
@endphp

<div class="space-y-6" id="transactionsPage" x-data="{ detailsId: null }">

    {{-- ============================ Metrics ============================ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Total transactions</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="queue-list" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['total']) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Successful volume</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="banknotes" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">&#8358;{{ number_format($stats['total_amount'], 2) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Successful</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700">
                        <x-icon name="check-circle" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['success']) }}</p>
                <p class="mt-1 text-xs tabular-nums text-muted-foreground">{{ $successRate }}% success rate</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Pending</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-700">
                        <x-icon name="clock" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['pending']) }}</p>
                <p class="mt-1 text-xs tabular-nums text-muted-foreground">{{ number_format($stats['failed']) }} failed</p>
            </div>
        </div>
    </div>

    {{-- ====================== Service type mix ======================== --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Service type distribution</h2>
            <p class="card-description">All-time transaction count per service.</p>
        </div>
        <div class="card-content">
            @if(array_sum($serviceTypes) > 0)
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
                    @foreach($serviceTypes as $type => $count)
                        @if($count > 0)
                            <div class="rounded-lg border border-border bg-surface p-3 text-center">
                                <span class="mx-auto flex h-9 w-9 items-center justify-center rounded-lg bg-surface text-brand-600 ring-1 ring-border">
                                    <x-icon :name="$serviceTypeIcons[$type] ?? 'document-text'" class="h-4 w-4" />
                                </span>
                                <p class="mt-2 text-lg font-semibold tabular-nums">{{ number_format($count) }}</p>
                                <p class="text-xs text-muted-foreground">{{ $serviceTypeLabels[$type] ?? ucfirst($type) }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @else
                <div class="empty-state py-8">
                    <x-icon name="chart-bar" class="h-8 w-8 text-ink-300" />
                    <p class="mt-2 text-sm text-muted-foreground">No transactions recorded yet.</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ============================ Filters =========================== --}}
    <div class="card" x-data="{ advanced: {{ $hasFilters && (request()->filled('date_from') || request()->filled('date_to') || request()->filled('min_amount') || request()->filled('max_amount')) ? 'true' : 'false' }} }">
        <div class="card-header flex-row items-center justify-between">
            <div>
                <h2 class="card-title">Filter transactions</h2>
                <p class="card-description">Narrow the list by reference, customer, service or date.</p>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" @click="advanced = !advanced"
                    :aria-expanded="advanced ? 'true' : 'false'" aria-controls="advancedFilters">
                <x-icon name="adjustments-horizontal" class="h-4 w-4" />
                Advanced
                <x-icon name="chevron-down" class="h-3.5 w-3.5 transition-transform" x-bind:class="advanced && 'rotate-180'" />
            </button>
        </div>

        <div class="card-content">
            <form method="GET" action="{{ route('admin.transactions.index', [], false) }}" id="filterForm" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <label class="label mb-2" for="filterSearch">Search</label>
                        <input type="text" id="filterSearch" name="search" value="{{ request('search') }}"
                               placeholder="Reference, user, description..." class="input">
                    </div>

                    <div>
                        <label class="label mb-2" for="filterServiceType">Service type</label>
                        <select id="filterServiceType" name="service_type" class="select">
                            <option value="">All types</option>
                            @foreach($serviceTypeLabels as $value => $label)
                                <option value="{{ $value }}" {{ request('service_type') == $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label mb-2" for="filterStatus">Status</label>
                        <select id="filterStatus" name="status" class="select">
                            <option value="">All statuses</option>
                            @foreach(['pending' => 'Pending', 'processing' => 'Processing', 'success' => 'Success', 'failed' => 'Failed', 'cancelled' => 'Cancelled'] as $value => $label)
                                <option value="{{ $value }}" {{ request('status') == $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label mb-2" for="filterPaymentMethod">Payment method</label>
                        <select id="filterPaymentMethod" name="payment_method" class="select">
                            <option value="">All methods</option>
                            @foreach(['wallet' => 'Wallet', 'bank_transfer' => 'Bank transfer', 'card' => 'Card', 'manual' => 'Manual'] as $value => $label)
                                <option value="{{ $value }}" {{ request('payment_method') == $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div id="advancedFilters" x-show="advanced" x-cloak x-transition.opacity class="grid grid-cols-1 gap-4 border-t border-border pt-4 md:grid-cols-4">
                    <div>
                        <label class="label mb-2" for="filterDateFrom">Date from</label>
                        <input type="date" id="filterDateFrom" name="date_from" value="{{ request('date_from') }}" class="input">
                    </div>

                    <div>
                        <label class="label mb-2" for="filterDateTo">Date to</label>
                        <input type="date" id="filterDateTo" name="date_to" value="{{ request('date_to') }}" class="input">
                    </div>

                    <div>
                        <label class="label mb-2" for="filterMinAmount">Min amount (&#8358;)</label>
                        <input type="number" id="filterMinAmount" name="min_amount" value="{{ request('min_amount') }}"
                               placeholder="0" min="0" step="0.01" class="input tabular-nums">
                    </div>

                    <div>
                        <label class="label mb-2" for="filterMaxAmount">Max amount (&#8358;)</label>
                        <input type="number" id="filterMaxAmount" name="max_amount" value="{{ request('max_amount') }}"
                               placeholder="1000000" min="0" step="0.01" class="input tabular-nums">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="funnel" class="h-4 w-4" />
                        Apply filters
                    </button>
                    <a href="{{ route('admin.transactions.index') }}" class="btn btn-outline btn-sm">
                        <x-icon name="arrow-path" class="h-4 w-4" />
                        Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- ========================== Transactions ======================== --}}
    <div class="card">
        <div class="card-header flex-row flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="card-title">All transactions</h2>
                <p class="card-description tabular-nums">
                    Showing {{ $transactions->firstItem() ?? 0 }}&ndash;{{ $transactions->lastItem() ?? 0 }} of {{ number_format($transactions->total()) }}
                </p>
            </div>
        </div>

        @if($transactions->count())
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Transaction</th>
                            <th>Customer</th>
                            <th>Service</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transactions as $transaction)
                            <tr>
                                <td>
                                    <p class="font-medium tabular-nums">{{ $transaction->reference }}</p>
                                    @if($transaction->description)
                                        <p class="mt-0.5 max-w-xs truncate text-xs text-muted-foreground">{{ $transaction->description }}</p>
                                    @endif
                                    @if($transaction->payment_method)
                                        <span class="badge badge-neutral mt-1">{{ ucfirst(str_replace('_', ' ', $transaction->payment_method)) }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($transaction->user)
                                        <div class="flex items-center gap-2.5">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                                                {{ strtoupper(substr($transaction->user->name, 0, 1)) }}
                                            </span>
                                            <div class="min-w-0">
                                                <p class="truncate font-medium">{{ $transaction->user->name }}</p>
                                                <p class="truncate text-xs text-muted-foreground">{{ $transaction->user->email }}</p>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-sm text-muted-foreground">Customer deleted</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-surface text-ink-600 ring-1 ring-border">
                                            <x-icon :name="$serviceTypeIcons[$transaction->service_type] ?? 'document-text'" class="h-4 w-4" />
                                        </span>
                                        <span class="font-medium">{{ $serviceTypeLabels[$transaction->service_type] ?? ucwords(str_replace('-', ' ', $transaction->service_type)) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <p class="font-semibold tabular-nums">&#8358;{{ number_format($transaction->amount, 2) }}</p>
                                    @if($transaction->service_fee > 0)
                                        <p class="text-xs tabular-nums text-muted-foreground">Fee: &#8358;{{ number_format($transaction->service_fee, 2) }}</p>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $statusClass = match ($transaction->status) {
                                            'success' => 'badge-success',
                                            'pending' => 'badge-warning',
                                            'processing' => 'badge-info',
                                            'failed' => 'badge-destructive',
                                            default => 'badge-neutral',
                                        };
                                    @endphp
                                    <span class="badge {{ $statusClass }}">{{ ucfirst($transaction->status) }}</span>
                                    @if($transaction->status_message)
                                        <p class="mt-1 max-w-xs truncate text-xs text-muted-foreground">{{ $transaction->status_message }}</p>
                                    @endif
                                </td>
                                <td class="text-sm text-muted-foreground">
                                    <span class="tabular-nums">{{ $transaction->created_at->format('M d, Y') }}</span><br>
                                    <span class="text-xs tabular-nums">{{ $transaction->created_at->format('h:i A') }}</span>
                                    @if($transaction->completed_at)
                                        <p class="mt-1 text-xs tabular-nums text-green-700">Completed {{ $transaction->completed_at->format('h:i A') }}</p>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <button type="button"
                                            class="btn btn-outline btn-sm"
                                            data-transaction-id="{{ $transaction->id }}"
                                            @click="viewTransactionDetails($event.currentTarget.dataset.transactionId)">
                                        <x-icon name="eye" class="h-4 w-4" />
                                        View
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($transactions->hasPages())
                <div class="border-t border-border p-4">
                    {{ $transactions->withQueryString()->links() }}
                </div>
            @endif
        @else
            <div class="empty-state">
                <x-icon name="document-text" class="h-10 w-10 text-ink-300" />
                <p class="mt-3 font-medium">No transactions found</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    @if($hasFilters)
                        Try adjusting or resetting your filters.
                    @else
                        Transactions will appear here when customers make them.
                    @endif
                </p>
                @if($hasFilters)
                    <a href="{{ route('admin.transactions.index') }}" class="btn btn-outline btn-sm mt-4">
                        <x-icon name="arrow-path" class="h-4 w-4" />
                        Clear filters
                    </a>
                @endif
            </div>
        @endif
    </div>

    {{-- ===================== Transaction detail modal ================= --}}
    {{-- On a phone this is a bottom sheet, not a centred dialog: a centred box
         with a fixed 90vh cap puts its header above the fold on a short screen,
         so the only way to close it is the backdrop — which is a tap target the
         user has to guess at. Anchored to the bottom with the height capped
         against the *dynamic* viewport (dvh, so mobile browser chrome is
         accounted for), the header and the close button are always on screen.
         From `sm` up there is room for the centred dialog. --}}
    <div x-show="detailsId !== null" x-cloak
         class="fixed inset-0 z-50 flex items-end justify-center bg-ink-950/50 sm:items-center sm:p-4"
         data-refresh-url-template="{{ route('admin.transactions.refresh-status', ['transaction' => '__ID__']) }}"
         @keydown.escape.window="closeTransactionModal()"
         @click.self="closeTransactionModal()">
        <div class="flex max-h-full min-h-0 w-full flex-col overflow-hidden rounded-t-2xl border border-border bg-surface shadow-overlay sm:max-h-[90vh] sm:max-w-4xl sm:rounded-xl"
             role="dialog" aria-modal="true" aria-labelledby="transactionModalTitle">
            <div class="flex shrink-0 items-start justify-between gap-3 border-b border-border px-4 py-3 sm:px-5 sm:py-4">
                <div class="min-w-0">
                    <h3 id="transactionModalTitle" class="card-title">Transaction details</h3>
                    <p id="modalSubtitle" class="card-description">Loading&hellip;</p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon -mr-1 shrink-0" aria-label="Close dialog"
                        @click="closeTransactionModal()">
                    <x-icon name="x-mark" class="h-5 w-5" />
                </button>
            </div>

            <div id="transactionModalContent" class="scrollbar-slim overflow-y-auto overscroll-contain p-4 sm:p-5">
                {{-- Filled over AJAX from admin.transactions.show --}}
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // ---------------------------------------------------------------
    // Transaction detail modal
    // ---------------------------------------------------------------
    // Endpoint and response contract are unchanged: the modal still GETs
    // `/admin/transactions/{id}?modal=true` and injects the returned HTML.
    // The open/close state now lives in Alpine (`detailsId`), so the view no
    // longer toggles Tailwind's `hidden`/`flex` classes by hand.
    (function () {
        const page = () => document.getElementById('transactionsPage');
        const content = () => document.getElementById('transactionModalContent');
        const spinner = `
            <div class="flex items-center justify-center py-12">
                <div class="text-center">
                    <svg class="mx-auto h-7 w-7 animate-spin text-ink-400" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                    </svg>
                    <p class="mt-2 text-sm text-muted-foreground">Loading transaction details&hellip;</p>
                </div>
            </div>`;

        window.viewTransactionDetails = function (transactionId) {
            const Alpine = window.Alpine;
            const shell = page();

            if (Alpine && shell) {
                Alpine.$data(shell).detailsId = transactionId;
            }

            const box = content();
            if (box) box.innerHTML = spinner;

            fetch(`{{ route('admin.transactions.show', '') }}/${transactionId}?modal=true`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html, application/json',
                },
                credentials: 'same-origin',
            })
                .then((response) => {
                    if (!response.ok) throw new Error(`HTTP ${response.status} ${response.statusText}`);
                    return response.text();
                })
                .then((html) => {
                    if (!html || html.trim() === '') throw new Error('The server returned an empty response.');
                    if (box) box.innerHTML = html;

                    // Fill the shell's subtitle from the loaded content. It used
                    // to sit on "Loading…" for the life of the dialog, which read
                    // as a stuck request even after everything had arrived.
                    const subtitle = document.getElementById('modalSubtitle');
                    const reference = box
                        ? box.querySelector('[data-transaction-reference]')?.dataset.transactionReference
                        : null;

                    if (subtitle) {
                        subtitle.textContent = reference
                            ? `Reference: ${reference}`
                            : 'Loaded';
                    }
                })
                .catch((error) => {
                    if (!box) return;
                    box.innerHTML = `
                        <div class="empty-state py-10">
                            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                </svg>
                            </span>
                            <p class="mt-3 font-medium">Could not load this transaction</p>
                            <p class="mt-1 text-sm text-muted-foreground">${escapeHtml(error.message)}</p>
                            <div class="mt-4 flex items-center gap-2">
                                <button type="button" class="btn btn-primary btn-sm" data-retry="${escapeHtml(transactionId)}">Try again</button>
                                <button type="button" class="btn btn-outline btn-sm" data-close-modal>Close</button>
                            </div>
                        </div>`;

                    box.querySelector('[data-retry]')?.addEventListener('click', (event) => {
                        window.viewTransactionDetails(event.currentTarget.dataset.retry);
                    });
                    box.querySelector('[data-close-modal]')?.addEventListener('click', () => {
                        window.closeTransactionModal();
                    });
                });
        };

        window.closeTransactionModal = function () {
            const Alpine = window.Alpine;
            const shell = page();

            if (Alpine && shell) {
                Alpine.$data(shell).detailsId = null;
            }

            const box = content();
            if (box) box.innerHTML = '';
        };

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    })();
</script>
@endpush

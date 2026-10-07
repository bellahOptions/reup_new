@extends('admin.layouts.app')

@section('title', 'Bank Transfers')
@section('page-title', 'Bank transfers')
@section('page-description', 'Review manual funding transfers, approve or reject them, and flag fraud.')

@section('content')
@php
    $hasFilters = request()->hasAny(['search', 'status', 'date_from', 'date_to', 'min_amount', 'max_amount']);

    $statusOptions = [
        'pending' => 'Awaiting review',
        'success' => 'Approved',
        'failed' => 'Rejected',
        'fraudulent' => 'Fraudulent',
    ];
@endphp

<div class="space-y-6">
    {{-- ============================ Metrics ============================ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Awaiting review</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-700">
                        <x-icon name="clock" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['pending'] ?? 0) }}</p>
                <p class="mt-1 text-xs tabular-nums text-muted-foreground">&#8358;{{ number_format($stats['pending_value'] ?? 0, 2) }} held</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Approved</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700">
                        <x-icon name="check-circle" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['approved'] ?? 0) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Rejected</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-700">
                        <x-icon name="x-mark" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['rejected'] ?? 0) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Flagged fraudulent</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-700">
                        <x-icon name="exclamation-triangle" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['fraudulent'] ?? 0) }}</p>
            </div>
        </div>
    </div>

    {{-- ============================ Filters =========================== --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Filter transfers</h2>
            <p class="card-description">Search by customer or reference, or narrow by date.</p>
        </div>

        <div class="card-content">
            <form method="GET" action="{{ route('admin.bank-transfers.index') }}" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <label class="label mb-2" for="transferStatus">Status</label>
                        <select id="transferStatus" name="status" class="select">
                            <option value="">All statuses</option>
                            @foreach($statusOptions as $value => $label)
                                <option value="{{ $value }}" {{ request('status') == $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label mb-2" for="transferSearch">Search</label>
                        <input type="text" id="transferSearch" name="search" value="{{ request('search') }}"
                               placeholder="Customer, reference..." class="input">
                    </div>

                    <div>
                        <label class="label mb-2" for="transferDateFrom">Date from</label>
                        <input type="date" id="transferDateFrom" name="date_from" value="{{ request('date_from') }}" class="input">
                    </div>

                    <div>
                        <label class="label mb-2" for="transferDateTo">Date to</label>
                        <input type="date" id="transferDateTo" name="date_to" value="{{ request('date_to') }}" class="input">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="funnel" class="h-4 w-4" />
                        Apply filters
                    </button>
                    <a href="{{ route('admin.bank-transfers.index') }}" class="btn btn-outline btn-sm">
                        <x-icon name="arrow-path" class="h-4 w-4" />
                        Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- =========================== Transfers ========================== --}}
    <div class="card">
        <div class="card-header flex-row flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="card-title">Bank transfer requests</h2>
                <p class="card-description tabular-nums">
                    Showing {{ $transfers->firstItem() ?? 0 }}&ndash;{{ $transfers->lastItem() ?? 0 }} of {{ number_format($transfers->total()) }}
                </p>
            </div>
        </div>

        @if($transfers->count())
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Reference</th>
                            <th>Proof</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transfers as $transfer)
                            @php
                                $proofPath = $transfer->meta['proof_path'] ?? null;
                                $isReviewable = in_array($transfer->status, ['pending', 'verifying'], true);
                            @endphp
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                                            {{ strtoupper(substr($transfer->user->name ?? 'U', 0, 1)) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">{{ $transfer->user->name ?? 'Unknown customer' }}</p>
                                            <p class="truncate text-xs text-muted-foreground">{{ $transfer->user->email ?? 'No email on file' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="font-semibold tabular-nums">&#8358;{{ number_format($transfer->amount, 2) }}</td>
                                <td class="font-mono text-xs tabular-nums">{{ $transfer->reference }}</td>
                                <td>
                                    @if($proofPath)
                                        <button type="button" class="btn btn-ghost btn-sm"
                                                @click="openProof('{{ route('admin.bank-transfers.proof.view', $transfer->id) }}')">
                                            <x-icon name="eye" class="h-4 w-4" />
                                            View proof
                                        </button>
                                    @else
                                        <span class="text-sm text-muted-foreground">No proof</span>
                                    @endif
                                </td>
                                <td>
                                    @if($transfer->is_fraudulent)
                                        <span class="badge badge-destructive">
                                            <x-icon name="exclamation-triangle" variant="solid" class="h-3 w-3" />
                                            Fraudulent
                                        </span>
                                    @elseif($transfer->status === 'success')
                                        <span class="badge badge-success">
                                            <x-icon name="check" class="h-3 w-3" />
                                            Approved
                                        </span>
                                    @elseif($transfer->status === 'failed')
                                        <span class="badge badge-destructive">
                                            <x-icon name="x-mark" class="h-3 w-3" />
                                            Rejected
                                        </span>
                                    @elseif($isReviewable)
                                        <span class="badge badge-warning">
                                            <x-icon name="clock" class="h-3 w-3" />
                                            {{ ucfirst($transfer->status) }}
                                        </span>
                                    @else
                                        <span class="badge badge-neutral">{{ ucfirst($transfer->status) }}</span>
                                    @endif
                                </td>
                                <td class="text-sm text-muted-foreground">
                                    <span class="tabular-nums">{{ $transfer->created_at->format('M d, Y') }}</span><br>
                                    <span class="text-xs tabular-nums">{{ $transfer->created_at->format('h:i A') }}</span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end">
                                        @if($isReviewable)
                                            <button type="button" class="btn btn-primary btn-sm" data-transfer-id="{{ $transfer->id }}"
                                                    @click="reviewTransfer($event.currentTarget.dataset.transferId)">
                                                <x-icon name="clipboard-document-list" class="h-4 w-4" />
                                                Review
                                            </button>
                                        @else
                                            <button type="button" class="btn btn-outline btn-sm" data-transfer-id="{{ $transfer->id }}"
                                                    @click="viewDetails($event.currentTarget.dataset.transferId)">
                                                <x-icon name="eye" class="h-4 w-4" />
                                                View details
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Proof of payment viewer (sibling of the table, never inside it) --}}
            <div x-data="{
                    proofOpen: false,
                    proofUrl: '',
                    openProof(url) { this.proofUrl = url; this.proofOpen = true; },
                    closeProof() { this.proofOpen = false; this.proofUrl = ''; }
                 }"
                 x-show="proofOpen" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/70 p-4"
                 @keydown.escape.window="closeProof()"
                 @click.self="closeProof()">
                <div class="w-full max-w-4xl overflow-hidden rounded-xl border border-border bg-white shadow-overlay"
                     role="dialog" aria-modal="true" aria-label="Proof of payment">
                    <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                        <h3 class="card-title">Proof of payment</h3>
                        <button type="button" class="btn btn-ghost btn-icon" aria-label="Close proof viewer" @click="closeProof()">
                            <x-icon name="x-mark" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="bg-surface p-4">
                        <img :src="proofUrl" alt="Proof of payment" class="h-auto max-h-[75vh] w-full rounded-lg object-contain">
                    </div>
                </div>
            </div>

            @if($transfers->hasPages())
                <div class="border-t border-border p-4">
                    {{ $transfers->withQueryString()->links() }}
                </div>
            @endif
        @else
            <div class="empty-state">
                <x-icon name="building-library" class="h-10 w-10 text-ink-300" />
                <p class="mt-3 font-medium">No bank transfers found</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    @if($hasFilters)
                        Try adjusting or resetting the filters.
                    @else
                        Transfers will appear here when customers submit them.
                    @endif
                </p>
                @if($hasFilters)
                    <a href="{{ route('admin.bank-transfers.index') }}" class="btn btn-outline btn-sm mt-4">
                        <x-icon name="arrow-path" class="h-4 w-4" />
                        Clear filters
                    </a>
                @endif
            </div>
        @endif
    </div>
</div>

{{-- ========================= Review transfer modal ==================== --}}
<div id="reviewModal" x-data="{ open: false }" x-show="open" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/50 p-4"
     @keydown.escape.window="closeReviewModal()"
     @click.self="closeReviewModal()">
    <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-border bg-white shadow-overlay"
         role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle">
        <div class="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div>
                <h3 id="reviewModalTitle" class="card-title">Review bank transfer</h3>
                <p class="card-description">Approve, reject or flag this transfer.</p>
            </div>
            <button type="button" class="btn btn-ghost btn-icon" aria-label="Close dialog" @click="closeReviewModal()">
                <x-icon name="x-mark" class="h-5 w-5" />
            </button>
        </div>

        <div id="modalContent" class="scrollbar-slim overflow-y-auto p-5">
            {{-- Filled over AJAX from admin.bank-transfers.show --}}
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // ---------------------------------------------------------------
    // Bank transfer modals
    // ---------------------------------------------------------------
    // Endpoints and response contract are unchanged: both actions GET
    // `/admin/bank-transfers/{id}?modal=true` and inject the returned HTML.
    // Visibility is Alpine-driven (`open`) instead of hand-toggled classes.
    (function () {
        const modalRoot = () => document.getElementById('reviewModal');
        const content = () => document.getElementById('modalContent');

        const loadingState = (message) => `
            <div class="flex items-center justify-center py-12">
                <div class="text-center">
                    <svg class="mx-auto h-7 w-7 animate-spin text-ink-400" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                    </svg>
                    <p class="mt-2 text-sm text-muted-foreground">${message}</p>
                </div>
            </div>`;

        function openModal() {
            const root = modalRoot();
            const Alpine = window.Alpine;

            if (Alpine && root) {
                Alpine.$data(root).open = true;
            } else if (root) {
                root.style.display = '';
            }
        }

        function loadTransfer(transferId, reloadFn, message) {
            const box = content();
            if (box) box.innerHTML = loadingState(message);
            openModal();

            fetch(`{{ route('admin.bank-transfers.show', '') }}/${transferId}?modal=true`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((response) => {
                    if (!response.ok) throw new Error(`HTTP ${response.status} ${response.statusText}`);
                    return response.text();
                })
                .then((html) => {
                    if (box) box.innerHTML = html;
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
                            <p class="mt-3 font-medium">Could not load this transfer</p>
                            <p class="mt-1 text-sm text-muted-foreground">${escapeHtml(error.message)}</p>
                            <div class="mt-4 flex items-center justify-center gap-2">
                                <button type="button" class="btn btn-primary btn-sm" data-retry>Try again</button>
                                <button type="button" class="btn btn-outline btn-sm" data-close-modal>Close</button>
                            </div>
                        </div>`;

                    box.querySelector('[data-retry]')?.addEventListener('click', () => reloadFn(transferId));
                    box.querySelector('[data-close-modal]')?.addEventListener('click', () => window.closeReviewModal());
                });
        }

        window.reviewTransfer = function (transferId) {
            loadTransfer(transferId, window.reviewTransfer, 'Loading transfer details\u2026');
        };

        window.viewDetails = function (transferId) {
            loadTransfer(transferId, window.viewDetails, 'Loading transfer details\u2026');
        };

        window.closeReviewModal = function () {
            const root = modalRoot();
            const Alpine = window.Alpine;

            if (Alpine && root) {
                Alpine.$data(root).open = false;
            } else if (root) {
                root.style.display = 'none';
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

        // Forms injected by admin/bank-transfers/show are bound here because
        // inline <script> tags inserted with innerHTML never execute. The
        // controller redirects with a flash message rather than returning
        // JSON, so both shapes are handled.
        document.getElementById('modalContent')?.addEventListener('submit', function (event) {
            const form = event.target;
            if (!form.matches('form')) return;

            event.preventDefault();

            const submit = form.querySelector('button[type="submit"]');
            if (submit) submit.disabled = true;

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json, text/html',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify(Object.fromEntries(new FormData(form))),
            })
                .then((response) => response.redirected
                    ? { success: true }
                    : response.json().catch(() => ({ success: false, message: 'The server returned an unexpected response.' })))
                .then((data) => {
                    if (!data.success) throw new Error(data.message || 'The action failed.');
                    window.location.reload();
                })
                .catch((error) => {
                    if (submit) submit.disabled = false;
                    const box = content();
                    if (box) {
                        box.insertAdjacentHTML('afterbegin', `
                            <p class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                                ${escapeHtml(error.message)}
                            </p>`);
                    }
                });
        });
    })();
</script>
@endpush

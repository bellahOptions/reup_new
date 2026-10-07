@extends('admin.layouts.app')

@section('title', 'User Details - ' . $user->name)
@section('page-title', 'Customer profile')
@section('page-description', $user->name)

@section('page-actions')
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline btn-sm">
        <x-icon name="arrow-left" class="h-4 w-4" />
        Back to customers
    </a>
@endsection

@section('content')
@php
    $transactionStatusBadge = static function (?string $status): string {
        return match ($status) {
            'success' => 'badge-success',
            'pending' => 'badge-warning',
            'processing' => 'badge-info',
            'failed' => 'badge-destructive',
            default => 'badge-neutral',
        };
    };
@endphp

<div class="space-y-6" x-data="customerProfile">

    {{-- ============================ Profile =========================== --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-1">
            <div class="card">
                <div class="card-content text-center">
                    <span class="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-brand-50 text-2xl font-semibold text-brand-700">
                        {{ substr($user->name ?? 'U', 0, 1) }}
                    </span>
                    <h2 class="text-lg font-semibold">{{ $user->name }}</h2>
                    <p class="text-sm text-muted-foreground">{{ $user->email }}</p>

                    <div class="mt-4 flex justify-center">
                        @if($user->email_verified_at)
                            <span class="badge badge-success">
                                <x-icon name="check-circle" variant="solid" class="h-3 w-3" />
                                Email verified
                            </span>
                        @else
                            <span class="badge badge-warning">
                                <x-icon name="exclamation-triangle" variant="solid" class="h-3 w-3" />
                                Email not verified
                            </span>
                        @endif
                    </div>

                    @if(!$user->email_verified_at)
                        <div class="mt-4 flex flex-col gap-2">
                            <a href="{{ route('admin.users.edit', $user->id) }}" class="btn btn-outline btn-sm">
                                <x-icon name="pencil-square" class="h-4 w-4" />
                                Edit profile
                            </a>
                            <button type="button" class="btn btn-primary btn-sm" :disabled="verifying" @click="verifyUser()">
                                <x-icon name="check" class="h-4 w-4" />
                                <span x-text="verifying ? 'Verifying...' : 'Verify email'">Verify email</span>
                            </button>
                        </div>
                    @else
                        <a href="{{ route('admin.users.edit', $user->id) }}" class="btn btn-outline btn-sm mt-4">
                            <x-icon name="pencil-square" class="h-4 w-4" />
                            Edit profile
                        </a>
                    @endif
                </div>

                <div class="border-t border-border p-5">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-border pb-3">
                            <dt class="stat-label flex items-center gap-2">
                                <x-icon name="identification" class="h-4 w-4 text-ink-400" />
                                User ID
                            </dt>
                            <dd class="font-medium tabular-nums">#{{ str_pad($user->id, 6, '0', STR_PAD_LEFT) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-b border-border pb-3">
                            <dt class="stat-label flex items-center gap-2">
                                <x-icon name="phone" class="h-4 w-4 text-ink-400" />
                                Phone
                            </dt>
                            <dd class="font-medium tabular-nums">{{ $user->phone ?? 'Not set' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-b border-border pb-3">
                            <dt class="stat-label flex items-center gap-2">
                                <x-icon name="chat-bubble-left-right" class="h-4 w-4 text-ink-400" />
                                WhatsApp
                            </dt>
                            <dd class="font-medium tabular-nums">{{ $user->whatsapp ?? 'Not set' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-b border-border pb-3">
                            <dt class="stat-label flex items-center gap-2">
                                <x-icon name="calendar" class="h-4 w-4 text-ink-400" />
                                Birthday
                            </dt>
                            <dd class="font-medium">
                                @if($user->birthday)
                                    {{ $user->birthday->format('M d, Y') }} ({{ $user->age }} yrs)
                                @else
                                    Not set
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-b border-border pb-3">
                            <dt class="stat-label flex items-center gap-2">
                                <x-icon name="user" class="h-4 w-4 text-ink-400" />
                                Gender
                            </dt>
                            <dd class="font-medium">{{ ucfirst($user->gender ?? 'Not set') }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-b border-border pb-3">
                            <dt class="stat-label flex items-center gap-2">
                                <x-icon name="clock" class="h-4 w-4 text-ink-400" />
                                Joined
                            </dt>
                            <dd class="font-medium">{{ $user->created_at->format('M d, Y') }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="stat-label flex items-center gap-2">
                                <x-icon name="arrow-right-on-rectangle" class="h-4 w-4 text-ink-400" />
                                Last login
                            </dt>
                            <dd class="font-medium">{{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Never' }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="border-t border-border p-5">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="stat-label">Profile completion</span>
                        <span class="text-sm font-semibold tabular-nums text-brand-700">{{ $user->profile_completion_percentage }}%</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-ink-100">
                        <div class="h-2 rounded-full bg-brand-500" style="width: {{ $user->profile_completion_percentage }}%"></div>
                    </div>
                </div>
            </div>

            @if($user->address || $user->city || $user->state)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title flex items-center gap-2">
                            <x-icon name="map-pin" class="h-4 w-4 text-ink-500" />
                            Address
                        </h3>
                    </div>
                    <div class="card-content space-y-2 text-sm">
                        @if($user->address)
                            <p>{{ $user->address }}</p>
                        @endif
                        @if($user->city || $user->state)
                            <p class="text-muted-foreground">
                                {{ $user->city }}{{ $user->city && $user->state ? ', ' : '' }}{{ $user->state }}
                            </p>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- ========================== Wallet stats ==================== --}}
        <div class="space-y-6 lg:col-span-2">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="card">
                    <div class="card-content">
                        <div class="flex items-start justify-between gap-3">
                            <span class="stat-label">Current balance</span>
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                                <x-icon name="wallet" class="h-4 w-4" />
                            </span>
                        </div>
                        <p class="stat-value mt-2">&#8358;{{ number_format($user->wallet_balance ?? 0, 2) }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Available to spend</p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-content">
                        <div class="flex items-start justify-between gap-3">
                            <span class="stat-label">Total spent</span>
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-700">
                                <x-icon name="arrow-trending-up" class="h-4 w-4" />
                            </span>
                        </div>
                        <p class="stat-value mt-2">&#8358;{{ number_format($user->wallet->total_spent ?? 0, 2) }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Lifetime spending</p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-content">
                        <div class="flex items-start justify-between gap-3">
                            <span class="stat-label">Total funded</span>
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-700">
                                <x-icon name="arrow-down-circle" class="h-4 w-4" />
                            </span>
                        </div>
                        <p class="stat-value mt-2">&#8358;{{ number_format($user->wallet->total_funded ?? 0, 2) }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">Total deposits</p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-content">
                        <div class="flex items-start justify-between gap-3">
                            <span class="stat-label">Transactions</span>
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface text-ink-600 ring-1 ring-border">
                                <x-icon name="queue-list" class="h-4 w-4" />
                            </span>
                        </div>
                        <p class="stat-value mt-2">{{ number_format($user->wallet->transaction_count ?? 0) }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">All-time count</p>
                    </div>
                </div>
            </div>

            {{-- ====================== Recent transactions ================= --}}
            <div class="card">
                <div class="card-header flex-row flex-wrap items-center justify-between gap-2">
                    <h3 class="card-title">Recent transactions</h3>
                    <a href="{{ route('admin.transactions.index', ['user_id' => $user->id]) }}" class="btn btn-outline btn-sm">
                        View all
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                </div>

                @php $recentTransactions = $user->transactions()->latest()->take(10)->get(); @endphp

                @if($recentTransactions->count())
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Reference</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentTransactions as $transaction)
                                    <tr>
                                        <td>
                                            <div class="flex items-center gap-2.5">
                                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-surface text-ink-600 ring-1 ring-border">
                                                    <x-icon :name="$transaction->type === 'credit' ? 'arrow-down-circle' : 'arrow-up-circle'" class="h-4 w-4" />
                                                </span>
                                                <span class="font-medium">{{ ucfirst($transaction->service_type) }}</span>
                                            </div>
                                        </td>
                                        <td class="font-mono text-xs tabular-nums">{{ $transaction->reference }}</td>
                                        <td class="font-semibold tabular-nums {{ $transaction->type === 'credit' ? 'text-green-700' : 'text-red-700' }}">
                                            {{ $transaction->type === 'credit' ? '+' : '-' }}&#8358;{{ number_format($transaction->amount, 2) }}
                                        </td>
                                        <td>
                                            <span class="badge {{ $transactionStatusBadge($transaction->status) }}">
                                                {{ ucfirst($transaction->status) }}
                                            </span>
                                        </td>
                                        <td class="text-sm tabular-nums text-muted-foreground">
                                            {{ $transaction->created_at->format('M d, Y') }} &middot; {{ $transaction->created_at->format('h:i A') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="empty-state">
                        <x-icon name="document-text" class="h-10 w-10 text-ink-300" />
                        <p class="mt-3 font-medium">No transactions yet</p>
                        <p class="mt-1 text-sm text-muted-foreground">This customer has not made any transactions.</p>
                    </div>
                @endif
            </div>

            {{-- ========================= Quick stats ===================== --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title flex items-center gap-2">
                        <x-icon name="chart-bar" class="h-4 w-4 text-ink-500" />
                        Service breakdown
                    </h3>
                </div>

                <div class="card-content grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-border bg-surface p-4">
                        <span class="stat-label">Airtime</span>
                        <p class="stat-value mt-1">{{ number_format($user->transactions()->where('service_type', 'airtime')->count()) }}</p>
                    </div>
                    <div class="rounded-lg border border-border bg-surface p-4">
                        <span class="stat-label">Data</span>
                        <p class="stat-value mt-1">{{ number_format($user->transactions()->where('service_type', 'data')->count()) }}</p>
                    </div>
                    <div class="rounded-lg border border-border bg-surface p-4">
                        <span class="stat-label">Wallet fundings</span>
                        <p class="stat-value mt-1">{{ number_format($user->transactions()->where('service_type', 'funding')->count()) }}</p>
                    </div>
                    <div class="rounded-lg border border-border bg-surface p-4">
                        <span class="stat-label">Success rate</span>
                        <p class="stat-value mt-1">
                            {{ $user->transactions()->count() > 0 ? round(($user->transactions()->where('status', 'success')->count() / $user->transactions()->count()) * 100, 1) : 0 }}%
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // ---------------------------------------------------------------
    // Manual email verification
    // ---------------------------------------------------------------
    // The endpoint and payload are unchanged (POST /admin/users/{id}/verify).
    // The inline `onclick="verifyUser(...)"` global is gone; the confirm and
    // the request now live on the component that owns the button.
    document.addEventListener('alpine:init', () => {
        Alpine.data('customerProfile', () => ({
            verifying: false,

            async verifyUser() {
                if (!confirm('Are you sure you want to manually verify this email address?')) return;

                this.verifying = true;

                try {
                    const response = await fetch(`/admin/users/{{ $user->id }}/verify`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    const data = await response.json();

                    if (!data.success) {
                        throw new Error(data.message || 'Verification failed.');
                    }

                    window.location.reload();
                } catch (error) {
                    alert('Error: ' + (error.message || 'An error occurred. Please try again.'));
                } finally {
                    this.verifying = false;
                }
            },
        }));
    });
</script>
@endpush

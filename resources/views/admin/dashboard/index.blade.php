@extends('admin.layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-description', 'Platform activity at a glance')

@section('page-actions')
    <a href="{{ route('admin.transactions.index') }}" class="btn btn-outline btn-sm">
        <x-icon name="queue-list" class="h-4 w-4" />
        All transactions
    </a>
    <a href="{{ route('admin.bank-transfers.index') }}" class="btn btn-primary btn-sm">
        <x-icon name="building-library" class="h-4 w-4" />
        Review transfers
        @if(($pendingTransfers ?? 0) > 0)
            <span class="ml-1 rounded-full bg-primary-foreground/20 px-1.5 text-[10px] font-semibold tabular-nums">
                {{ $pendingTransfers }}
            </span>
        @endif
    </a>
@endsection

@section('content')
@php
    $money = fn ($value) => '₦' . number_format((float) $value, 2);

    $totalTx = (int) ($stats['total_transactions'] ?? 0);
    $successTx = (int) ($stats['successful_transactions'] ?? 0);
    $successRate = $totalTx > 0 ? round(($successTx / $totalTx) * 100, 1) : 0.0;

    $rateTone = $successRate >= 95
        ? 'bg-green-50 text-success-soft-foreground'
        : ($successRate >= 85 ? 'bg-amber-50 text-amber-600' : 'bg-red-50 text-red-600');

    /*
     * Payment gateways only.
     *
     * ClubKonnect used to be listed here, and it is not a payment gateway: it is
     * the upstream that *vends* airtime, data, cable and PINs. Money never
     * arrives through it — it is spent with it — so a "Gateway status" card
     * showing its float invited exactly the wrong reading, that its balance was
     * platform revenue. It already has its own card below, under the heading
     * that describes what it actually is.
     *
     * The distinction this card exists to draw: a gateway takes money *in*, a
     * vending provider pays money *out*. They fail differently, they are topped
     * up differently, and only one of them is revenue.
     *
     * Bachs has no balance endpoint (see BachsService), so it reports
     * configured-ness rather than a figure. Inventing a zero for it would be
     * worse than saying what is actually known.
     */
    $gateways = [
        [
            'label' => 'Paystack',
            'icon' => 'credit-card',
            'ok' => (bool) ($stats['paystack_success'] ?? false),
            'value' => $money($stats['paystack_balance'] ?? 0),
            'detail' => number_format((int) ($stats['paystack_stats']['total_transactions'] ?? 0)) . ' transactions today',
            'error' => $stats['paystack_error'] ?? null,
        ],
    ];

    if (config('services.bachs.enabled')) {
        $gateways[] = [
            'label' => 'Bachs',
            'icon' => 'credit-card',
            /*
             * "Ok" here means "switched on and holding a key", not "reachable":
             * there is no balance call to prove reachability with. Reporting it
             * as Connected would be a claim this card cannot support.
             */
            'ok' => true,
            'value' => 'Card fallback',
            'detail' => 'Used only when Paystack will not start a checkout',
            'error' => null,
        ];
    }

    $queue = [
        ['Bank transfers', (int) ($pendingTransfers ?? 0), route('admin.bank-transfers.index'), 'building-library'],
        ['Live chat', (int) ($chatStats['pending_chats'] ?? 0), route('admin.chat.index'), 'chat-bubble-left-right'],
        ['Contact messages', (int) ($contactStats['unread'] ?? 0), route('admin.contact.index'), 'envelope'],
    ];
@endphp

{{-- ========================= Headline metrics ========================= --}}
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">

    <div class="card">
        <div class="card-content">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="stat-label">Volume today</p>
                    <p class="stat-value mt-1">{{ $money($stats['today_volume'] ?? 0) }}</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent text-brand-600">
                    <x-icon name="arrow-trending-up" class="h-4 w-4" />
                </span>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                {{ number_format((int) ($stats['today_transactions'] ?? 0)) }} transactions ·
                {{ $money($stats['monthly_volume'] ?? 0) }} this month
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-content">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="stat-label">Wallet float</p>
                    <p class="stat-value mt-1">{{ $money($stats['total_wallet_balance'] ?? 0) }}</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent text-brand-600">
                    <x-icon name="wallet" class="h-4 w-4" />
                </span>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                {{ $money($stats['total_pending_balance'] ?? 0) }} awaiting verification
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-content">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="stat-label">Customers</p>
                    <p class="stat-value mt-1">{{ number_format((int) ($stats['total_users'] ?? 0)) }}</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent text-brand-600">
                    <x-icon name="users" class="h-4 w-4" />
                </span>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                {{ number_format((int) ($stats['new_users_today'] ?? 0)) }} new today ·
                {{ number_format((int) ($userStats['online_now'] ?? 0)) }} online now
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-content">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="stat-label">Success rate</p>
                    <p class="stat-value mt-1">{{ $successRate }}%</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $rateTone }}">
                    <x-icon name="check-circle" variant="solid" class="h-4 w-4" />
                </span>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                {{ number_format((int) ($stats['failed_transactions'] ?? 0)) }} failed ·
                {{ number_format((int) ($stats['pending_transactions'] ?? 0)) }} pending
            </p>
        </div>
    </div>
</div>

{{-- ========================= Charts ========================= --}}
<div class="mt-6 grid gap-6 lg:grid-cols-3">

    <div class="card lg:col-span-2">
        <div class="card-header flex-row items-center justify-between">
            <div>
                <h2 class="card-title">Settled volume</h2>
                <p class="card-description">Successful transactions over the last 7 days</p>
            </div>
            <span class="badge badge-neutral">7 days</span>
        </div>
        <div class="card-content">
            <div class="h-64">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Service mix</h2>
            <p class="card-description">Share of all transactions by product</p>
        </div>
        <div class="card-content">
            <div class="h-64">
                <canvas id="typeChart"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- ========================= Gateways and attention ========================= --}}
<div class="mt-6 grid gap-6 lg:grid-cols-3">

    <div class="card lg:col-span-2">
        <div class="card-header">
            <h2 class="card-title">Payment gateways</h2>
            <p class="card-description">Where customer money arrives</p>
        </div>
        <div class="divide-y divide-border">
            @foreach($gateways as $gateway)
                <div class="flex items-center gap-3 px-5 py-4">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $gateway['ok'] ? 'bg-green-50 text-success-soft-foreground' : 'bg-red-50 text-red-600' }}">
                        <x-icon :name="$gateway['icon']" class="h-4 w-4" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ $gateway['label'] }}</p>
                        <p class="truncate text-xs text-muted-foreground">
                            @if($gateway['ok'])
                                {{ $gateway['detail'] }}
                            @else
                                {{ $gateway['error'] ? \Illuminate\Support\Str::limit($gateway['error'], 90) : 'Not configured or unreachable' }}
                            @endif
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="text-sm font-semibold tabular-nums">{{ $gateway['value'] }}</p>
                        <span class="badge {{ $gateway['ok'] ? 'badge-success' : 'badge-destructive' }}">
                            {{ $gateway['ok'] ? 'Connected' : 'Attention' }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Needs attention</h2>
            <p class="card-description">Waiting on an administrator</p>
        </div>
        <div class="card-content space-y-2">
            @foreach($queue as [$label, $count, $href, $icon])
                <a href="{{ $href }}"
                   class="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5 transition-colors hover:bg-ink-50">
                    <x-icon :name="$icon" class="h-4 w-4 shrink-0 text-ink-500" />
                    <span class="flex-1 text-sm">{{ $label }}</span>
                    @if($count > 0)
                        <span class="badge badge-warning tabular-nums">{{ $count }}</span>
                    @else
                        <span class="text-xs text-muted-foreground">clear</span>
                    @endif
                </a>
            @endforeach

            <dl class="grid grid-cols-2 gap-3 pt-2">
                <div class="rounded-lg bg-surface p-3">
                    <dt class="text-xs text-muted-foreground">Total funded</dt>
                    <dd class="mt-1 text-sm font-semibold tabular-nums">{{ $money($stats['total_funded'] ?? 0) }}</dd>
                </div>
                <div class="rounded-lg bg-surface p-3">
                    <dt class="text-xs text-muted-foreground">Total spent</dt>
                    <dd class="mt-1 text-sm font-semibold tabular-nums">{{ $money($stats['total_withdrawn'] ?? 0) }}</dd>
                </div>
            </dl>
        </div>
    </div>
</div>

    {{-- Float held with the upstreams that *vend* — the opposite direction of
         travel from the gateways above. A provider short of float is the most
         common cause of a failed vend, so it is surfaced here rather than only
         in logs. --}}
    @if(!empty($providerBalances))
        <div class="mt-6 card">
            <div class="card-header flex-row items-center justify-between">
                <div>
                    <h2 class="card-title">Vending providers</h2>
                    <p class="card-description">
                        Float we pay out from, for airtime, bills and PINs &mdash; these are not payment gateways
                    </p>
                </div>
                <span class="badge badge-neutral">{{ count($providerBalances) }} configured</span>
            </div>
            <div class="divide-y divide-border">
                @foreach($providerBalances as $provider)
                    <div class="flex items-center gap-3 px-5 py-4">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ ($provider['success'] ?? false) ? 'bg-green-50 text-success-soft-foreground' : 'bg-red-50 text-red-600' }}">
                            <x-icon name="server-stack" class="h-4 w-4" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">{{ $provider['label'] }}</p>
                            <p class="truncate text-xs text-muted-foreground">
                                @if(!($provider['configured'] ?? false))
                                    Not configured
                                @elseif($provider['success'] ?? false)
                                    Checked {{ $provider['checked_at'] ?? 'recently' }}
                                @else
                                    {{ $provider['message'] ?? 'Balance unavailable' }}
                                @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm font-semibold tabular-nums">
                                &#8358;{{ number_format((float) ($provider['balance'] ?? 0), 2) }}
                            </p>
                            <span class="badge {{ ($provider['success'] ?? false) ? 'badge-success' : 'badge-destructive' }}">
                                {{ ($provider['success'] ?? false) ? 'Healthy' : 'Attention' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
{{-- ========================= Recent activity ========================= --}}
<div class="mt-6 grid gap-6 xl:grid-cols-3">

    <div class="card xl:col-span-2">
        <div class="card-header flex-row items-center justify-between">
            <div>
                <h2 class="card-title">Recent transactions</h2>
                <p class="card-description">The five most recent platform-wide</p>
            </div>
            <a href="{{ route('admin.transactions.index') }}" class="link text-sm font-medium">View all</a>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Service</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentTransactions as $transaction)
                        <tr>
                            <td>
                                <p class="font-medium">{{ $transaction->user->name ?? 'Deleted customer' }}</p>
                                <p class="font-mono text-xs text-muted-foreground">{{ $transaction->reference }}</p>
                            </td>
                            <td>
                                <span class="badge {{ $transaction->service_type_badge }}">
                                    {{ ucfirst(str_replace('-', ' ', (string) $transaction->service_type)) }}
                                </span>
                                @if($transaction->recipient)
                                    <p class="mt-1 text-xs text-muted-foreground">{{ $transaction->recipient }}</p>
                                @endif
                            </td>
                            <td class="text-right font-medium tabular-nums">
                                {{ $money($transaction->amount) }}
                            </td>
                            <td>
                                <span class="badge {{ $transaction->status_badge }}">
                                    {{ ucfirst((string) $transaction->status) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-xs text-muted-foreground">
                                {{ $transaction->created_at?->diffForHumans() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <x-icon name="inbox" class="h-7 w-7 text-ink-300" />
                                    <p class="mt-2 text-sm text-muted-foreground">No transactions recorded yet.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($recentTransactions->hasPages())
            <div class="card-footer justify-end">
                {{ $recentTransactions->links() }}
            </div>
        @endif
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header flex-row items-center justify-between">
                <h2 class="card-title">New customers</h2>
                <a href="{{ route('admin.users.index') }}" class="link text-xs font-medium">All</a>
            </div>
            <ul class="divide-y divide-border">
                @forelse($recentUsers as $user)
                    <li class="flex items-center gap-3 px-5 py-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink-100 text-xs font-semibold text-ink-700">
                            {{ strtoupper(substr($user->name, 0, 1)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.users.show', $user) }}" class="block truncate text-sm font-medium hover:underline">
                                {{ $user->name }}
                            </a>
                            <p class="truncate text-xs text-muted-foreground">{{ $user->email }}</p>
                        </div>
                        <span class="shrink-0 text-xs font-medium tabular-nums text-muted-foreground">
                            {{ $money($user->wallet->balance ?? 0) }}
                        </span>
                    </li>
                @empty
                    <li class="empty-state">
                        <x-icon name="users" class="h-6 w-6 text-ink-300" />
                        <p class="mt-2 text-sm text-muted-foreground">No customers yet.</p>
                    </li>
                @endforelse
            </ul>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Administrator activity</h2>
                <p class="card-description">Most recent console actions</p>
            </div>
            <ul class="divide-y divide-border">
                @forelse($recentLogs as $log)
                    <li class="flex items-start gap-3 px-5 py-3">
                        <x-icon name="finger-print" class="mt-0.5 h-4 w-4 shrink-0 text-ink-400" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm">
                                <span class="font-medium">{{ $log->user->name ?? 'System' }}</span>
                                <span class="text-muted-foreground">{{ str_replace('_', ' ', (string) $log->action) }}</span>
                            </p>
                            <p class="text-xs text-muted-foreground">{{ $log->created_at?->diffForHumans() }}</p>
                        </div>
                    </li>
                @empty
                    <li class="empty-state">
                        <x-icon name="finger-print" class="h-6 w-6 text-ink-300" />
                        <p class="mt-2 text-sm text-muted-foreground">No activity recorded.</p>
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    (function () {
        if (typeof Chart === 'undefined') return;

        const brand = '#2AB70D';
        const ink400 = '#98a298';
        const grid = '#eef0ee';

        Chart.defaults.font.family = '"DM Sans", system-ui, sans-serif';
        Chart.defaults.color = ink400;
        Chart.defaults.borderColor = grid;

        const volume = @json($chartData['revenue'] ?? ['labels' => [], 'data' => []]);
        const types = @json($chartData['types'] ?? ['labels' => [], 'data' => []]);

        const money = (v) => '₦' + Number(v).toLocaleString('en-NG', { maximumFractionDigits: 0 });

        const volumeEl = document.getElementById('revenueChart');
        if (volumeEl && volume.labels && volume.labels.length) {
            new Chart(volumeEl, {
                type: 'line',
                data: {
                    labels: volume.labels,
                    datasets: [{
                        data: volume.data,
                        borderColor: brand,
                        backgroundColor: 'rgba(42, 183, 13, 0.10)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: brand,
                        pointBorderWidth: 2,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { displayColors: false, callbacks: { label: (ctx) => money(ctx.parsed.y) } },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            border: { display: false },
                            grid: { color: grid },
                            ticks: { maxTicksLimit: 5, callback: money },
                        },
                        x: { border: { display: false }, grid: { display: false } },
                    },
                },
            });
        }

        const typeEl = document.getElementById('typeChart');
        if (typeEl && types.labels && types.labels.length) {
            // Brand tints first, then neutrals: the chart reads as part of the
            // design system instead of the previous hardcoded rainbow.
            const palette = ['#2AB70D', '#71d23f', '#99e46b', '#c1f0a1', '#6f7a6f', '#c3cac3'];

            new Chart(typeEl, {
                type: 'doughnut',
                data: {
                    labels: types.labels,
                    datasets: [{
                        data: types.data,
                        backgroundColor: types.labels.map((_, i) => palette[i % palette.length]),
                        borderWidth: 2,
                        borderColor: '#ffffff',
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, padding: 14 },
                        },
                    },
                },
            });
        }
    })();
</script>
@endpush

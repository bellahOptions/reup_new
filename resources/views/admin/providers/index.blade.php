@extends('admin.layouts.app')

@section('title', 'Providers')
@section('page-title', 'Providers')
@section('page-description', 'Health, float, alerts and routing for every upstream.')

@section('content')
<div class="space-y-6">

    @if(session('status'))
        <div class="alert alert-success"><p class="text-sm">{{ session('status') }}</p></div>
    @endif

    @if(session('error'))
        <div class="alert alert-error"><p class="text-sm">{{ session('error') }}</p></div>
    @endif

    @if($sandbox)
        <div class="alert">
            <p class="text-sm font-medium">Sandbox mode is on.</p>
            <p class="mt-1 text-sm">
                Every provider is pointed at its sandbox. These are sandbox balances and sandbox availability, and
                costs synced in this mode are not real provider charges.
            </p>
        </div>
    @endif

    {{-- Open alerts first: this is the part an operator has to act on, and burying
         it under a table of healthy providers is how it gets missed. --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Open alerts ({{ $alerts->count() }})</h2>
            <p class="card-description">
                One row per condition, not per observation. A condition that persists updates its alert; resolving
                one allows it to be raised again if it recurs.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Severity</th>
                        <th>Provider</th>
                        <th>Condition</th>
                        <th>Detail</th>
                        <th>Acknowledged</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($alerts as $alert)
                        <tr>
                            <td>
                                <span class="badge">{{ ucfirst($alert->severity) }}</span>
                            </td>
                            <td>{{ $alert->provider?->name ?? '—' }}</td>
                            <td>
                                <span class="font-medium">{{ $alert->title }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $alert->type }}</span>
                            </td>
                            <td class="text-xs">{{ $alert->message }}</td>
                            <td class="text-xs">
                                {{ $alert->acknowledged_at ? $alert->acknowledged_at->diffForHumans() : 'No' }}
                            </td>
                            <td class="text-right whitespace-nowrap">
                                @unless($alert->acknowledged_at)
                                    <form method="POST" action="{{ route('admin.providers.alerts.acknowledge', $alert) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="link text-xs">Acknowledge</button>
                                    </form>
                                @endunless
                                <form method="POST" action="{{ route('admin.providers.alerts.resolve', $alert) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="link text-xs">Resolve</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted-foreground">No open alerts.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Provider table. Ordering is the routing order, so the table reads exactly as
         `ProviderRegistry` will pick. --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Provider health and float</h2>
            <p class="card-description">
                Probe history is retained for {{ (int) config('providers.sync.health_history_days', 90) }} days.
                A provider is marked down after {{ $thresholds['failures_before_down'] }} consecutive failed probes —
                one dropped connection is not an outage.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Driver</th>
                        <th>Capabilities</th>
                        <th>Routing</th>
                        <th>Health</th>
                        <th class="text-right">Balance</th>
                        <th class="text-right">Latency</th>
                        <th>Last checked</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($providers as $row)
                        <tr>
                            <td>
                                <a href="{{ route('admin.providers.show', $row['provider']) }}" class="link font-medium">
                                    {{ $row['label'] }}
                                </a>
                                <span class="block text-xs text-muted-foreground">{{ $row['slug'] }}</span>
                            </td>
                            <td class="text-xs">{{ $row['driver'] }}</td>
                            <td class="text-xs">{{ implode(', ', $row['capabilities']) ?: '—' }}</td>
                            <td class="text-xs">
                                @if(! $row['is_active'])
                                    <span class="badge">Inactive</span>
                                @elseif($row['is_primary'])
                                    <span class="badge">Primary</span>
                                @else
                                    Priority {{ $row['priority'] }}
                                @endif
                            </td>
                            <td class="text-xs">
                                <span class="font-medium">{{ ucfirst(str_replace('_', ' ', (string) $row['health_status'])) }}</span>
                                @if($row['health_message'])
                                    <span class="block text-muted-foreground">{{ \Illuminate\Support\Str::limit($row['health_message'], 60) }}</span>
                                @endif
                                @unless($row['has_adapter'])
                                    <span class="block text-muted-foreground">No adapter in this build</span>
                                @endunless
                                @if($row['has_adapter'] && ! $row['is_configured'])
                                    <span class="block text-muted-foreground">No credential configured</span>
                                @endif
                                @if($row['has_adapter'] && $row['is_configured'] && ! $row['is_operational'])
                                    {{-- Configured is not the same as usable. VTUGate's
                                         international field names are unconfirmed, so it
                                         is skipped by the registry and the route falls
                                         through to the next candidate. --}}
                                    <span class="block text-muted-foreground">Not routable: integration unverified</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">
                                @if($row['balance_minor'] === null)
                                    <span class="text-muted-foreground">—</span>
                                @else
                                    <span class="{{ $row['low_balance'] ? 'text-red-600 font-medium' : '' }}">
                                        ₦{{ number_format($row['balance_minor'] / 100, 2) }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">
                                {{ $row['latency_ms'] === null ? '—' : $row['latency_ms'] . ' ms' }}
                            </td>
                            <td class="text-xs">
                                {{ $row['health_checked_at'] ? $row['health_checked_at']->diffForHumans() : 'Never' }}
                                @if($row['is_sandbox'])
                                    <span class="block text-muted-foreground">sandbox</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <form method="POST" action="{{ route('admin.providers.check', $row['provider']) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="link text-xs">Check now</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted-foreground">
                                No providers are configured. Nothing can be routed until one is.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Routing is edited on each provider's own screen, so the capability list and
         the confirmation of what is being changed sit together. --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Routing</h2>
            <p class="card-description">
                Candidates for a capability are ordered by <code>is_primary</code> first, then ascending
                <code>priority</code>, then id. This is the only routing configuration — there is no second table to
                disagree with it.
            </p>
        </div>
        <div class="card-body space-y-2">
            @foreach($capabilities as $capability)
                @php
                    $forCapability = collect($providers)
                        ->filter(fn ($p) => in_array($capability, $p['capabilities'], true)
                            && $p['is_active'] && $p['is_configured'] && $p['is_operational'])
                        ->values();
                @endphp
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-medium">{{ $capability }}</span>
                    <span class="text-muted-foreground">→</span>
                    @forelse($forCapability as $index => $p)
                        <a href="{{ route('admin.providers.show', $p['provider']) }}" class="link">
                            {{ $index + 1 }}. {{ $p['label'] }}
                        </a>
                    @empty
                        <span class="text-red-600">No provider can serve this capability.</span>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>

    @if($staged > 0)
        <div class="alert">
            <p class="text-sm font-medium">{{ $staged }} provider variant(s) are awaiting a mapping.</p>
            <p class="mt-1 text-sm">
                The catalogue sync records what a provider offers and never maps it to a ReUp product on its own,
                because a wrong mapping sells the wrong thing. Open a provider to review what it found.
            </p>
        </div>
    @endif
</div>
@endsection

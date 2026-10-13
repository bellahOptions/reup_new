@extends('admin.layouts.app')
@section('title', 'Social Boost provider')
@section('page-title', 'Social Boost provider')
@section('page-description', 'Connection, balance, catalogue freshness and recent API activity for Nitro NG.')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Admin — Nitro NG health
    |--------------------------------------------------------------------------
    | The four questions an operator asks when Social Boost stops selling:
    |
    |   1. is the credential there at all — a missing environment variable is a
    |      deployment state, not an outage, and it is reported as one;
    |   2. is there money in the provider wallet — the customer is charged before
    |      the provider is asked, so a dry float produces failed orders;
    |   3. is the catalogue fresh — a service list nobody has synced is a set of
    |      rates that may no longer be what Nitro charges;
    |   4. what did the API say last — the recent interactions below, with the
    |      credential already stripped from every payload.
    */
    $configured = $configured ?? false;
    $statusTone = match ($health['status'] ?? 'unknown') {
        'healthy' => 'badge-success',
        'down' => 'badge-destructive',
        'not_configured' => 'badge-neutral',
        default => 'badge-warning',
    };
@endphp

<div class="space-y-6">

    {{-- ===================== Configuration ===================== --}}
    @unless($configured)
        <div class="card border-red-200">
            <div class="card-body">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-red-800">
                    <x-icon name="exclamation-triangle" variant="solid" class="h-4 w-4" />
                    Nitro NG has no credential configured
                </h2>
                <p class="mt-1.5 text-sm text-red-700">
                    Social Boost cannot sell anything without it, and no order will reach the provider. The command
                    reports this rather than attempting a request, because a guaranteed authentication failure every
                    run looks like an outage and hides the real cause.
                </p>
                <p class="mt-2 text-xs text-red-700">
                    Set <code>{{ $envPrefix }}_API_KEY</code> in the environment, then run
                    <code>php artisan smm:sync-catalogue</code>.
                </p>
            </div>
        </div>
    @endunless

    {{-- ===================== Connection ===================== --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card">
            <div class="card-header flex items-center justify-between gap-3">
                <h2 class="card-title">Connection</h2>
                <span class="badge {{ $statusTone }}">{{ $health['label'] }}</span>
            </div>
            <div class="card-body space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Credential</span>
                    <span class="font-medium">{{ $configured ? 'configured' : 'missing' }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Adapter</span>
                    <span class="font-medium">{{ $adapter?->label() ?? '—' }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Operational</span>
                    <span class="font-medium">{{ $adapter?->isOperational() ? 'yes' : 'no' }}</span>
                </div>
                @if($adapter && ! $adapter->isOperational())
                    <p class="rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">
                        {{ $adapter->operationalReason() }}
                    </p>
                @endif
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Routing</span>
                    <span class="font-medium">{{ ($health['is_active'] ?? false) ? 'active' : 'inactive' }} · priority {{ $health['priority'] ?? '—' }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Rate limit</span>
                    <span class="font-medium tabular-nums">{{ number_format($rateLimitPerMinute) }}/min</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header flex items-center justify-between gap-3">
                <h2 class="card-title">Provider balance</h2>
                @if($health['low_balance'] ?? false)
                    <span class="badge badge-destructive">Below threshold</span>
                @endif
            </div>
            <div class="card-body space-y-3 text-sm">
                <div>
                    <p class="text-2xl font-semibold tabular-nums {{ ($health['low_balance'] ?? false) ? 'text-red-700' : '' }}">
                        {{ $health['balance'] ?? '—' }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Last read {{ $health['checked_at']?->diffForHumans() ?? 'never' }}
                        @if($health['latency_ms'] ?? null)
                            &middot; {{ $health['latency_ms'] }} ms
                        @endif
                    </p>
                </div>

                @if($health['error_code'] ?? null)
                    <p class="rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-800">
                        Last error: <span class="font-mono">{{ $health['error_code'] }}</span>
                    </p>
                @endif

                @if($health['message'] ?? null)
                    <p class="rounded-lg border border-border bg-ink-50 p-2 text-xs text-muted-foreground">
                        {{ $health['message'] }}
                    </p>
                @endif

                <p class="text-xs text-muted-foreground">
                    Top this wallet up before it runs dry: the customer is charged before the provider is asked, so an
                    unaffordable order fails after the debit.
                </p>

                <a href="{{ route('admin.providers.index') }}" class="btn btn-outline btn-sm w-full">
                    Provider console
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Catalogue</h2>
            </div>
            <div class="card-body space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Services</span>
                    <span class="font-medium tabular-nums">{{ number_format($catalogue['services']) }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Sellable</span>
                    <span class="font-medium tabular-nums">{{ number_format($catalogue['sellable']) }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">No published rate</span>
                    <span class="font-medium tabular-nums {{ $catalogue['unpriced'] > 0 ? 'text-amber-700' : '' }}">
                        {{ number_format($catalogue['unpriced']) }}
                    </span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Gone upstream</span>
                    <span class="font-medium tabular-nums">{{ number_format($catalogue['unavailable']) }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-muted-foreground">Awaiting mapping</span>
                    <span class="font-medium tabular-nums {{ $catalogue['staged'] > 0 ? 'text-amber-700' : '' }}">
                        {{ number_format($catalogue['staged']) }}
                    </span>
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-border pt-3">
                    <span class="text-muted-foreground">Last synced</span>
                    <span class="font-medium">
                        {{ $catalogue['last_synced_at'] ? \Illuminate\Support\Carbon::parse($catalogue['last_synced_at'])->diffForHumans() : 'never' }}
                    </span>
                </div>

                <p class="text-xs text-muted-foreground">
                    Hourly on the scheduler. <code>php artisan smm:sync-catalogue</code> refreshes it now;
                    <code>--status</code> reports without fetching.
                </p>
            </div>
        </div>
    </div>

    {{-- ===================== Recent API activity ===================== --}}    <div class="card overflow-hidden">
        <div class="card-header">
            <h2 class="card-title">Recent API activity</h2>
            <p class="card-description">
                The last 30 provider interactions across every Social Boost order. Request credentials never appear in
                a stored payload — the adapter strips them before anything is written.
            </p>
        </div>

        @if($recentTransactions->isEmpty())
            <div class="card-body">
                <p class="text-sm text-muted-foreground">No provider interaction has been recorded yet.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <caption class="sr-only">Recent Nitro API interactions</caption>
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Order</th>
                            <th scope="col">Operation</th>
                            <th scope="col">Result</th>
                            <th scope="col">Provider status</th>
                            <th scope="col">HTTP</th>
                            <th scope="col">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentTransactions as $interaction)
                            <tr>
                                <td class="whitespace-nowrap text-xs">{{ $interaction->created_at?->format('j M, g:i:s A') }}</td>
                                <td class="whitespace-nowrap font-mono text-xs">
                                    @if($interaction->serviceOrder)
                                        <a href="{{ route('admin.social-boost.orders.show', $interaction->serviceOrder->uuid) }}" class="link">
                                            {{ $interaction->serviceOrder->uuid }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-xs">{{ $interaction->operation }}</td>
                                <td class="whitespace-nowrap">
                                    <span class="badge {{ $interaction->status === \App\Providers\Support\ProviderStatus::SUCCESS ? 'badge-success' : (in_array($interaction->status, [\App\Providers\Support\ProviderStatus::FAILED, \App\Providers\Support\ProviderStatus::RETRYABLE], true) ? 'badge-destructive' : 'badge-info') }}">
                                        {{ $interaction->status }}
                                    </span>
                                    @if($interaction->rate_limited)
                                        <span class="badge badge-warning mt-1">rate limited</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-xs">{{ $interaction->provider_status ?? '—' }}</td>
                                <td class="whitespace-nowrap text-xs tabular-nums">{{ $interaction->http_status ?? '—' }}</td>
                                <td class="max-w-md text-xs text-muted-foreground">
                                    <span class="line-clamp-2">{{ $interaction->error_message ?? '—' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

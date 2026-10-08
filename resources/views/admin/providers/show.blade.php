@extends('admin.layouts.app')

@section('title', $provider->name)
@section('page-title', $provider->name)
@section('page-description', $adapter?->label() ?? $provider->driver)

@section('content')
<div class="space-y-6">

    @if(session('status'))
        <div class="alert alert-success"><p class="text-sm">{{ session('status') }}</p></div>
    @endif

    @if(session('error'))
        <div class="alert alert-error"><p class="text-sm">{{ session('error') }}</p></div>
    @endif

    @if($errors->any())
        <div class="alert alert-error">
            @foreach($errors->all() as $error)
                <p class="text-sm">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    @unless($routable)
        <div class="alert">
            <p class="text-sm font-medium">This provider will not currently be routed to.</p>
            <p class="mt-1 text-sm">
                @if($adapter === null)
                    No adapter in this build implements the <code>{{ $provider->driver }}</code> driver.
                @elseif(! $adapter->isConfigured())
                    No credential is configured for it. Set the environment variables named
                    <code>{{ $provider->credential_env_prefix }}_*</code> and check again — a missing key is a
                    deployment state, not an outage.
                @else
                    {{ $adapter->operationalReason() }}
                @endif
            </p>
        </div>
    @endunless

    {{-- Routing and thresholds. Deliberately not the driver, the slug or the
         credential prefix: changing a driver re-points every order at a different
         integration, and both of those are deploys rather than clicks. --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Routing and monitoring</h2>
            <p class="card-description">
                Ordering for every capability this provider declares. Two providers must not both be primary for
                the same capability — that would make the order depend on row ids rather than on a decision.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.providers.update', $provider, false) }}" class="card-body space-y-4">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked($provider->is_active)>
                    Active — a provider that is switched off is never selected
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_primary" value="0">
                    <input type="checkbox" name="is_primary" value="1" @checked($provider->is_primary)>
                    Primary — preferred over any other provider at the same priority
                </label>

                <label class="block text-sm">
                    <span class="block font-medium">Priority</span>
                    <span class="block text-xs text-muted-foreground">Lower is tried first.</span>
                    <input type="number" name="priority" min="0" max="10000"
                           value="{{ old('priority', $provider->priority) }}" class="input mt-1">
                </label>

                <label class="block text-sm">
                    <span class="block font-medium">Low balance threshold (kobo)</span>
                    <span class="block text-xs text-muted-foreground">
                        Raise a critical alert when the float falls below this. Blank means no threshold.
                    </span>
                    <input type="number" name="low_balance_threshold_minor" min="0"
                           value="{{ old('low_balance_threshold_minor', $provider->low_balance_threshold_minor) }}"
                           class="input mt-1">
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary btn-sm">Save routing</button>

                <span class="text-xs text-muted-foreground">
                    Declares: {{ implode(', ', (array) $provider->capabilities) ?: 'nothing' }}
                </span>
            </div>
        </form>

        <div class="card-body border-t">
            <form method="POST" action="{{ route('admin.providers.check', $provider, false) }}">
                @csrf
                <button type="submit" class="btn btn-outline btn-sm">Check now</button>
                <span class="ml-2 text-xs text-muted-foreground">
                    The scheduled probe runs every five minutes; this is for when you have just topped up a wallet
                    or rotated a key.
                </span>
            </form>
        </div>
    </div>

    {{-- Probe history. The table exists so "when did this start" has an answer: a
         single current-status column has no past. --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Probe history</h2>
            <p class="card-description">Most recent 100 probes, oldest first.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Available</th>
                        <th class="text-right">Balance</th>
                        <th class="text-right">Latency</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($history as $check)
                        <tr>
                            <td class="text-xs">{{ $check->checked_at?->toDateTimeString() }}</td>
                            <td class="text-xs">
                                {{ $check->available ? 'Yes' : 'No' }}
                                @if($check->is_sandbox)
                                    <span class="text-muted-foreground">(sandbox)</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums text-xs">
                                {{ $check->balance_minor === null ? '—' : number_format($check->balance_minor / 100, 2) }}
                            </td>
                            <td class="text-right tabular-nums text-xs">{{ $check->latency_ms === null ? '—' : $check->latency_ms . ' ms' }}</td>
                            <td class="text-xs">
                                {{ $check->error_code ?? '' }}
                                @if($check->message)
                                    <span class="block text-muted-foreground">{{ \Illuminate\Support\Str::limit($check->message, 80) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted-foreground">No probes recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Alerts, including resolved ones: the history of what happened is what
         explains an incident afterwards. --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Alerts</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Raised</th>
                        <th>Severity</th>
                        <th>Condition</th>
                        <th>State</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($alerts as $alert)
                        <tr>
                            <td class="text-xs">{{ $alert->created_at?->toDateTimeString() }}</td>
                            <td class="text-xs">{{ ucfirst($alert->severity) }}</td>
                            <td class="text-xs">
                                <span class="font-medium">{{ $alert->title }}</span>
                                <span class="block text-muted-foreground">{{ $alert->message }}</span>
                            </td>
                            <td class="text-xs">
                                @if($alert->resolved_at)
                                    Resolved {{ $alert->resolved_at->diffForHumans() }}
                                @elseif($alert->acknowledged_at)
                                    Acknowledged
                                @else
                                    Open
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                @unless($alert->resolved_at)
                                    @unless($alert->acknowledged_at)
                                        <form method="POST" action="{{ route('admin.providers.alerts.acknowledge', $alert, false) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="link text-xs">Acknowledge</button>
                                        </form>
                                    @endunless
                                    <form method="POST" action="{{ route('admin.providers.alerts.resolve', $alert, false) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="link text-xs">Resolve</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted-foreground">No alerts for this provider.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Products already mapped to a ReUp product, with the cost we are charged.
         Only these can be sold. --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Mapped products ({{ $offerings->count() }})</h2>
            <p class="card-description">
                Costs come from the catalogue sync. A product whose cost moved is flagged, and the movement is what
                the margin needs reviewing against.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>ReUp product</th>
                        <th>Provider's identifier</th>
                        <th class="text-right">Cost now</th>
                        <th class="text-right">Cost before</th>
                        <th>Changed</th>
                        <th>In catalogue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($offerings as $offering)
                        <tr>
                            <td class="text-xs">{{ $offering->serviceProduct?->name ?? '—' }}</td>
                            <td class="text-xs">
                                {{ $offering->provider_product_id }}
                                <span class="block text-muted-foreground">{{ $offering->provider_name }}</span>
                            </td>
                            <td class="text-right tabular-nums text-xs">
                                {{ $offering->provider_cost_minor === null ? 'Not stated' : number_format($offering->provider_cost_minor / 100, 2) }}
                            </td>
                            <td class="text-right tabular-nums text-xs">
                                {{ $offering->previous_cost_minor === null ? '—' : number_format($offering->previous_cost_minor / 100, 2) }}
                            </td>
                            <td class="text-xs">{{ $offering->cost_changed_at?->diffForHumans() ?? '—' }}</td>
                            <td class="text-xs">
                                @if($offering->unavailable_at)
                                    Withdrawn {{ $offering->unavailable_at->diffForHumans() }}
                                @else
                                    Yes
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted-foreground">
                                Nothing from this provider is mapped to a ReUp product yet, so nothing can be sold
                                from it.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($pendingMapping->isNotEmpty())
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Awaiting mapping ({{ $pendingMapping->count() }})</h2>
                <p class="card-description">
                    Products this provider offers that no ReUp product has been matched to. The sync records them and
                    stops: matching a provider's plan name to ours by similarity would sell the wrong bundle the first
                    time the provider rewords one.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Provider's identifier</th>
                            <th>Name</th>
                            <th>Looks like</th>
                            <th class="text-right">Cost</th>
                            <th>First seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pendingMapping as $item)
                            <tr>
                                <td class="text-xs">{{ $item->provider_product_id }}</td>
                                <td class="text-xs">{{ $item->provider_name }}</td>
                                <td class="text-xs">{{ $item->capability }}{{ $item->network ? ' / ' . $item->network : '' }}</td>
                                <td class="text-right tabular-nums text-xs">
                                    {{ $item->provider_cost_minor === null ? '—' : number_format($item->provider_cost_minor / 100, 2) }}
                                </td>
                                <td class="text-xs">{{ $item->first_seen_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Cost history. Append-only, which is what makes a cost-increase claim
         checkable rather than a matter of memory. --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Recorded cost history</h2>
            <p class="card-description">
                Appended on every observed change. Nothing updates a row here, so this cannot be rewritten to make a
                margin look better than it was.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Recorded</th>
                        <th>Source</th>
                        <th class="text-right">Cost</th>
                        <th>Currency</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($priceHistory as $snapshot)
                        <tr>
                            <td class="text-xs">{{ $snapshot->recorded_at?->toDateTimeString() }}</td>
                            <td class="text-xs">{{ $snapshot->source }}</td>
                            <td class="text-right tabular-nums text-xs">{{ number_format($snapshot->cost_minor / 100, 2) }}</td>
                            <td class="text-xs">{{ $snapshot->provider_currency ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted-foreground">No cost changes recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <a href="{{ route('admin.providers.index') }}" class="btn btn-outline btn-sm">Back to providers</a>
</div>
@endsection

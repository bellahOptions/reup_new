@extends('admin.layouts.app')

@section('title', 'Provider costs')
@section('page-title', 'Provider costs')
@section('page-description', 'What each provider charges ReUp. These figures decide the margin on every sale, not the price a customer pays.')

@section('content')
<div class="space-y-6">

    {{--
        The distinction this screen exists to make, stated up front: cost is an
        input, price is an output. The airtime discount used to live as a constant
        inside a service class precisely because there was nowhere to put it.
    --}}
    <div class="card">
        <div class="card-content">
            <h2 class="text-sm font-semibold">Cost is not price</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                This screen records what a provider charges <strong>us</strong>. What a customer pays is decided by
                <a href="{{ route('admin.pricing.rules') }}" class="link">pricing rules</a>. For airtime those are
                FACE_VALUE: the customer pays the airtime they asked for, and the margin is the discount below.
            </p>
            <p class="mt-2 text-sm text-muted-foreground">
                A rate that has not been confirmed against a provider statement is an <strong>assumption</strong>.
                Profit measured on an assumption is recorded as estimated, never as realised.
            </p>
        </div>
    </div>

    {{-- ============================ Policy ============================ --}}
    <div class="card">
        <div class="card-content">
            <h2 class="text-sm font-semibold">When a cost cannot be established</h2>
            <dl class="mt-3 grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                <div>
                    <dt class="stat-label">Policy</dt>
                    <dd class="mt-1 font-medium">{{ $policy['on_unverified_cost'] ?? 'refuse' }}</dd>
                </div>
                <div>
                    <dt class="stat-label">A cost older than this is unknown</dt>
                    <dd class="mt-1 font-medium">{{ $maxAgeHours }} hours</dd>
                </div>
                <div>
                    <dt class="stat-label">Configured assumptions may price a sale</dt>
                    <dd class="mt-1 font-medium">{{ ($policy['allow_configured_assumptions'] ?? true) ? 'Yes (profit marked estimated)' : 'No' }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-muted-foreground">
                Set <code>PROVIDER_COST_POLICY</code>, <code>PROVIDER_COST_MAX_AGE_HOURS</code> and
                <code>PROVIDER_COST_ALLOW_ASSUMPTIONS</code> in the environment. There is no setting that sells on an
                invented cost.
            </p>
        </div>
    </div>

    {{-- ============================ Airtime rates ============================ --}}
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Airtime discounts</h2>
                <p class="card-description">
                    Each provider's discount off face value, per network. One row prices every denomination —
                    a rate, not a per-amount price.
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Network</th>
                        <th class="text-right">Discount</th>
                        <th class="text-right">Cost of &#8358;1,000</th>
                        <th class="text-right">Gross profit on &#8358;1,000</th>
                        <th>Verification</th>
                        <th class="text-right">Last change</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($airtimeTerms as $term)
                        @php
                            $face = 100000; // ₦1,000 in kobo
                            $cost = $term->costFor($face)->minor();
                        @endphp
                        <tr>
                            <td class="font-medium">{{ $term->provider?->name ?? '—' }}</td>
                            <td>
                                {{ $term->network ? (App\Services\NetworkResolver::labelFor($term->network) ?? $term->network) : 'Any network' }}
                            </td>
                            <td class="text-right tabular-nums font-medium">{{ $term->discountLabel() }}</td>
                            <td class="text-right tabular-nums">&#8358;{{ number_format($cost / 100, 2) }}</td>
                            <td class="text-right tabular-nums {{ $cost < $face ? 'text-green-700' : 'text-red-600' }}">
                                &#8358;{{ number_format(($face - $cost) / 100, 2) }}
                            </td>
                            <td>
                                @if($term->isVerified())
                                    <span class="badge badge-success">Verified</span>
                                    @if($term->verified_at)
                                        <span class="block text-xs text-muted-foreground">
                                            {{ $term->verified_at->format('M j, Y') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="badge badge-warning">Assumption</span>
                                    <span class="block text-xs text-muted-foreground">Profit recorded as estimated</span>
                                @endif

                                {{-- Surfaced because an unnoticed expiring cost silently blocks sales. --}}
                                @if($term->isStale())
                                    <span class="badge badge-destructive">Stale</span>
                                @endif
                            </td>
                            <td class="text-right text-xs text-muted-foreground">
                                {{ $term->updated_at?->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted-foreground">
                                No airtime discount has been recorded. Airtime cannot be sold until one is —
                                the purchase path refuses rather than inventing a cost.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ============================ Edit a rate ============================ --}}
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Record or update a rate</h2>
                <p class="card-description">
                    Rates are configurable independently per network, because a 3% assumption must not be applied
                    blindly to a network whose real rate differs.
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.pricing.costs.update', [], false) }}" class="card-body space-y-5">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
                <div>
                    <label for="provider_id" class="form-label">Provider</label>
                    <select name="provider_id" id="provider_id" class="form-select" required>
                        @foreach($providers as $provider)
                            <option value="{{ $provider->id }}" @selected(old('provider_id') == $provider->id)>
                                {{ $provider->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('provider_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="network" class="form-label">Network</label>
                    <select name="network" id="network" class="form-select" required>
                        @foreach($networks as $key => $label)
                            <option value="{{ $key }}" @selected(old('network') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('network') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="discount_percentage" class="form-label">Discount off face value (%)</label>
                    <input type="number" step="0.01" min="0" max="50" name="discount_percentage"
                           id="discount_percentage" class="input tabular-nums"
                           value="{{ old('discount_percentage') }}" required>
                    @error('discount_percentage') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-muted-foreground">
                        3% means &#8358;1,000 of airtime costs ReUp &#8358;970.
                    </p>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="verified" value="1" class="mt-0.5" @checked(old('verified'))>
                    <span>
                        <span class="block text-sm font-medium">I have confirmed this rate against a provider statement</span>
                        <span class="mt-1 block text-xs text-muted-foreground">
                            Leave unchecked unless you have actually checked it. Profit priced on an unverified rate is
                            reported as estimated; this is what promotes it to realised.
                        </span>
                    </span>
                </label>

                <div class="mt-3">
                    <label for="verification_note" class="form-label">Evidence (optional)</label>
                    <input type="text" name="verification_note" id="verification_note" class="input"
                           maxlength="191" value="{{ old('verification_note') }}"
                           placeholder="e.g. ClubKonnect statement, 2026-02-27">
                    @error('verification_note') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <button type="submit" class="btn btn-primary">Save rate</button>
            </div>
        </form>
    </div>

    {{-- ============================ Data costs ============================ --}}
    <div class="card">
        <div class="card-content">
            <h2 class="text-sm font-semibold">Data bundle costs</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                A data bundle's cost is a real per-bundle price published by the provider, not a rate, so it is not
                typed in here. It is read from the provider catalogue, recorded against the mapped provider product,
                and refreshed by the catalogue sync.
            </p>
            <p class="mt-2 text-sm text-muted-foreground">
                A 3% airtime discount is <strong>not</strong> applied to data. If a bundle has no cost in the
                catalogue, it cannot be priced and is shown as unavailable rather than sold at a guess.
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('admin.providers.index') }}" class="btn btn-outline btn-sm">Provider catalogues</a>
                <a href="{{ route('admin.pricing.index') }}" class="btn btn-outline btn-sm">Priced products</a>
            </div>
        </div>
    </div>

</div>
@endsection

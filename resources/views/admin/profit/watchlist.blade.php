@extends('admin.layouts.app')

@section('title', 'Margin watchlist')
@section('page-title', 'Margin watchlist')
@section('page-description', 'Services whose current provider cost cannot meet the configured pricing floor.')

@section('content')
<div class="space-y-6">

    <div class="alert">
        <p class="text-sm">
            Computed live from the pricing engine against each provider's current cost, so a cost that changed
            this morning appears here without waiting for a scheduled job. A service is excluded from sale when
            its rule is set to make it unavailable, and flagged when the rule is set to warn.
        </p>
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Provider</th>
                        <th class="text-right">Provider cost</th>
                        <th class="text-right">Current price</th>
                        <th class="text-right">Gross profit</th>
                        <th class="text-right">Gross margin</th>
                        <th>Problem</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>
                                <span class="font-medium">{{ $row['product']->name }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $row['offering']->provider_name }}</span>
                            </td>
                            <td>{{ $row['offering']->provider?->name ?? '—' }}</td>
                            <td class="text-right tabular-nums">
                                ₦{{ number_format($row['quote']->providerCostMinor / 100, 2) }}
                            </td>
                            <td class="text-right tabular-nums">
                                @if($row['quote']->isSellable())
                                    ₦{{ number_format($row['quote']->customerPriceMinor / 100, 2) }}
                                @else
                                    <span class="text-muted-foreground">Not sellable</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums {{ $row['quote']->isLoss() ? 'text-red-600' : '' }}">
                                ₦{{ number_format($row['quote']->grossProfitMinor / 100, 2) }}
                            </td>
                            <td class="text-right tabular-nums">{{ $row['quote']->grossMarginLabel() }}</td>
                            <td class="text-xs">
                                {{ $row['quote']->refusalReason ?? 'Below the configured margin floor.' }}
                            </td>
                            <td class="text-right">
                                @if($row['quote']->pricingRuleId)
                                    <a href="{{ route('admin.pricing.edit', $row['quote']->pricingRuleId) }}" class="link text-xs">
                                        Edit rule
                                    </a>
                                @else
                                    <a href="{{ route('admin.pricing.create') }}" class="link text-xs">Create rule</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted-foreground">
                                Every available service currently meets its pricing floor.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <a href="{{ route('admin.profit.index') }}" class="btn btn-outline btn-sm">Back to Revenue &amp; Profit</a>
</div>
@endsection

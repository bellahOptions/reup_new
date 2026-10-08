@extends('admin.layouts.app')

@section('title', 'Revenue &amp; Profit')
@section('page-title', 'Revenue &amp; Profit')
@section('page-description', 'Realized revenue and margin. Only finalized, fulfilled orders are counted.')

@section('content')
<div class="space-y-6">

    {{-- ======================= Periods ======================= --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach(['today' => 'Today', 'week' => 'This week', 'month' => 'This month'] as $key => $label)
            <div class="card">
                <div class="card-content">
                    <span class="stat-label">{{ $label }}</span>
                    <p class="stat-value mt-2">{{ $periods[$key]['formatted']['gross_profit'] }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        gross profit on {{ number_format($periods[$key]['transactions']) }} order(s)
                    </p>
                    <dl class="mt-3 space-y-1 text-xs">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Revenue</dt>
                            <dd class="tabular-nums">{{ $periods[$key]['formatted']['revenue'] }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Provider cost</dt>
                            <dd class="tabular-nums">{{ $periods[$key]['formatted']['provider_cost'] }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Gross margin</dt>
                            <dd class="tabular-nums">{{ $periods[$key]['formatted']['gross_margin'] }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ======================= Operations ======================= --}}
    @if($operations['unresolved'] > 0 || $operations['partial'] > 0)
        <div class="alert alert-destructive">
            <p class="text-sm font-medium">Attention needed</p>
            <p class="mt-1 text-sm">
                {{ number_format($operations['unresolved']) }} order(s) have an unresolved provider outcome
                ({{ number_format($operations['unknown']) }} explicitly unknown) and
                {{ number_format($operations['partial']) }} were partially delivered.
                Margin figures exclude them until they resolve, and none of them may be refunded or retried
                until the provider outcome is known.
            </p>
        </div>
    @endif

    {{-- ======================= Filters ======================= --}}
    <div class="card">
        <div class="card-content">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[10rem]">
                    <label for="period" class="form-label">Period</label>
                    <select name="period" id="period" class="form-select">
                        @foreach(['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'quarter' => 'This quarter', 'year' => 'This year', 'custom' => 'Custom range'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['period'] ?? 'month') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="from" class="form-label">From</label>
                    <input type="date" name="from" id="from" class="form-input" value="{{ $filters['from'] ?? '' }}">
                </div>

                <div>
                    <label for="to" class="form-label">To</label>
                    <input type="date" name="to" id="to" class="form-input" value="{{ $filters['to'] ?? '' }}">
                </div>

                <div class="min-w-[12rem]">
                    <label for="provider_slug" class="form-label">Provider</label>
                    <select name="provider_slug" id="provider_slug" class="form-select">
                        <option value="">All providers</option>
                        @foreach($providers as $provider)
                            <option value="{{ $provider->slug }}" @selected(($filters['provider_slug'] ?? null) === $provider->slug)>
                                {{ $provider->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="min-w-[12rem]">
                    <label for="category_slug" class="form-label">Category</label>
                    <select name="category_slug" id="category_slug" class="form-select">
                        <option value="">All categories</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->slug }}" @selected(($filters['category_slug'] ?? null) === $category->slug)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Apply</button>
                <a href="{{ route('admin.profit.index') }}" class="btn btn-outline">Reset</a>
                <a href="{{ route('admin.profit.watchlist') }}" class="btn btn-outline">Margin watchlist</a>
            </form>
        </div>
    </div>

    {{-- ======================= Detail ======================= --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">{{ $window['label'] }}</h2>
            <p class="card-description">
                {{ $window['from']->format('d M Y') }} to {{ $window['to']->format('d M Y') }}
            </p>
        </div>

        <div class="card-content">
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    'Revenue' => $detail['formatted']['revenue'],
                    'Provider cost' => $detail['formatted']['provider_cost'],
                    'Customer fees' => $detail['formatted']['fees'],
                    'Refunds' => $detail['formatted']['refunds'],
                    'Gross profit' => $detail['formatted']['gross_profit'],
                    'Gross margin' => $detail['formatted']['gross_margin'],
                ] as $label => $value)
                    <div>
                        <dt class="stat-label">{{ $label }}</dt>
                        <dd class="mt-1 text-sm font-medium tabular-nums">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-4 text-xs text-muted-foreground">
                {{ number_format($detail['transactions']) }} realized order(s).
                Refund rate over this window:
                {{ number_format($refundRate['rate_bps'] / 100, 2) }}%
                ({{ number_format($refundRate['refunded']) }} of {{ number_format($refundRate['total']) }}).
                Profit is a derived accounting figure and is never posted to a wallet.
            </p>
        </div>
    </div>

    {{-- ======================= Breakdowns ======================= --}}
    @foreach([
        'By service' => $byProduct,
        'By provider' => $byProvider,
        'By category' => $byCategory,
    ] as $heading => $breakdown)
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">{{ $heading }}</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ str_replace('By ', '', $heading) }}</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right">Provider cost</th>
                            <th class="text-right">Gross profit</th>
                            <th class="text-right">Gross margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($breakdown as $row)
                            <tr>
                                <td class="font-medium">{{ $row['key'] }}</td>
                                <td class="text-right tabular-nums">{{ number_format($row['transactions']) }}</td>
                                <td class="text-right tabular-nums">{{ $row['formatted_revenue'] }}</td>
                                <td class="text-right tabular-nums">{{ $row['formatted_provider_cost'] }}</td>
                                <td class="text-right tabular-nums {{ $row['gross_profit_minor'] < 0 ? 'text-red-600' : '' }}">
                                    {{ $row['formatted_gross_profit'] }}
                                </td>
                                <td class="text-right tabular-nums">{{ $row['formatted_gross_margin'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted-foreground">No realized orders in this window.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    {{-- ======================= Daily ======================= --}}
    @if($daily !== [])
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Daily</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right">Provider cost</th>
                            <th class="text-right">Gross profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($daily as $day)
                            <tr>
                                <td class="tabular-nums">{{ \Illuminate\Support\Carbon::parse($day['day'])->format('d M Y') }}</td>
                                <td class="text-right tabular-nums">{{ number_format($day['transactions']) }}</td>
                                <td class="text-right tabular-nums">₦{{ number_format($day['revenue_minor'] / 100, 2) }}</td>
                                <td class="text-right tabular-nums">₦{{ number_format($day['provider_cost_minor'] / 100, 2) }}</td>
                                <td class="text-right tabular-nums">₦{{ number_format($day['gross_profit_minor'] / 100, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection

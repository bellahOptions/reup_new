@extends('admin.layouts.app')

@section('title', 'Pricing')
@section('page-title', 'Pricing')
@section('page-description', 'Provider cost, customer price and margin for every service. Super Admin only.')

@section('content')
<div class="space-y-6">

    {{-- ============================ Summary ============================ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card">
            <div class="card-content">
                <span class="stat-label">Services</span>
                <p class="stat-value mt-2">{{ number_format($summary['total']) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ number_format($summary['priced']) }} priced</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <span class="stat-label">Not priced</span>
                <p class="stat-value mt-2">{{ number_format($summary['unpriced']) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">No rule, or no provider cost recorded</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <span class="stat-label">Below cost</span>
                <p class="stat-value mt-2">{{ number_format($summary['unprofitable']) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">Would sell at a loss</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <span class="stat-label">Below target margin</span>
                <p class="stat-value mt-2">{{ number_format($summary['warning']) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">Selling, but under the configured floor</p>
            </div>
        </div>
    </div>

    {{-- ============================ Filters ============================ --}}
    <div class="card">
        <div class="card-content">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[14rem] flex-1">
                    <label for="category" class="form-label">Category</label>
                    <select name="category" id="category" class="form-select">
                        <option value="">All categories</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected(($filters['category'] ?? null) == $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="min-w-[12rem]">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach(['DISCOVERED','REVIEW','ACTIVE','PAUSED','OUT_OF_STOCK','UNPROFITABLE','PROVIDER_UNAVAILABLE','DISCONTINUED'] as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="min-w-[10rem]">
                    <label for="sort" class="form-label">Sort by</label>
                    <select name="sort" id="sort" class="form-select">
                        @foreach(['name' => 'Service', 'profit' => 'Gross profit', 'revenue' => 'Price', 'status' => 'Status'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('sort') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="min-w-[8rem]">
                    <label for="direction" class="form-label">Order</label>
                    <select name="direction" id="direction" class="form-select">
                        <option value="asc" @selected(request('direction') !== 'desc')>Ascending</option>
                        <option value="desc" @selected(request('direction') === 'desc')>Descending</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Apply</button>
                <a href="{{ route('admin.pricing.index') }}" class="btn btn-outline">Reset</a>
                <a href="{{ route('admin.pricing.rules') }}" class="btn btn-outline">Pricing rules</a>
            </form>
        </div>
    </div>

    {{-- ============================ Table ============================ --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Services</h2>
            <p class="card-description">
                Figures are calculated live from the active pricing rule for the cheapest available provider,
                so what is shown here is what a customer would be charged right now.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Provider</th>
                        <th class="text-right">Provider cost</th>
                        <th class="text-right">Customer price</th>
                        <th class="text-right">Markup</th>
                        @if($showProfit)
                            <th class="text-right">Gross profit</th>
                            <th class="text-right">Gross margin</th>
                        @endif
                        <th>Pricing rule</th>
                        <th>Status</th>
                        <th>Last updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>
                                <span class="font-medium">{{ $row['product']->name }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $row['product']->category?->name }}</span>
                            </td>
                            <td>{{ $row['provider']?->name ?? '—' }}</td>
                            <td class="text-right tabular-nums">
                                {{ $row['provider_cost_minor'] !== null ? '₦' . number_format($row['provider_cost_minor'] / 100, 2) : '—' }}
                            </td>
                            <td class="text-right tabular-nums font-medium">
                                @if($row['price_minor'] !== null)
                                    ₦{{ number_format($row['price_minor'] / 100, 2) }}
                                @else
                                    <span class="text-muted-foreground">Not sellable</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">
                                {{ $row['quote']?->markupPercentageLabel() ?? '—' }}
                            </td>
                            @if($showProfit)
                                <td class="text-right tabular-nums">
                                    @if($row['quote'] !== null)
                                        <span class="{{ $row['quote']->isLoss() ? 'text-red-600' : '' }}">
                                            ₦{{ number_format($row['quote']->grossProfitMinor / 100, 2) }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ $row['quote']?->grossMarginLabel() ?? '—' }}
                                </td>
                            @endif
                            <td>
                                @if($row['rule'])
                                    <a href="{{ route('admin.pricing.edit', $row['rule']) }}" class="link">
                                        {{ $row['rule']->name }}
                                    </a>
                                    <span class="block text-xs text-muted-foreground">{{ $row['rule']->scope }}</span>
                                @else
                                    <span class="text-muted-foreground">None</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $row['status'] === 'ACTIVE' ? 'badge-success' : 'badge-neutral' }}">
                                    {{ $row['status'] }}
                                </span>
                                @if($row['availability'] === 'AVAILABLE_WITH_WARNING')
                                    <span class="badge badge-warning mt-1">Under margin</span>
                                @endif
                            </td>
                            <td class="text-xs text-muted-foreground">
                                {{ $row['cost_synced_at']?->diffForHumans() ?? 'never' }}
                            </td>
                            <td class="text-right">
                                @if($row['problem'])
                                    <span class="text-xs text-amber-700" title="{{ $row['problem'] }}">Review</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $showProfit ? 11 : 9 }}" class="text-center text-muted-foreground">
                                No services match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($products->hasPages())
            <div class="card-content border-t">
                {{ $products->links() }}
            </div>
        @endif
    </div>

    <p class="text-xs text-muted-foreground">
        Provider cost and margin are commercially sensitive. Customers never see them; the storefront shows
        a price and a total only.
    </p>
</div>
@endsection

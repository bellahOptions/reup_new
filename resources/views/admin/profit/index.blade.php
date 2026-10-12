@extends('admin.layouts.app')

@section('title', 'Revenue &amp; Profit')
@section('page-title', 'Revenue &amp; Profit')
@section('page-description', 'Realized revenue and margin, per service and overall. Live — refreshes every 30 seconds.')

@section('content')
<div class="space-y-6"
     x-data="profitLive({
        today: @js($today['formatted']['gross_profit']),
        todayRevenue: @js($today['formatted']['revenue']),
        todayOrders: {{ (int) $today['transactions'] }},
        endpoint: @js(route('admin.profit.data', [], false)),
     })"
     x-init="start()">

    {{--
        Live figures.

        The numbers come from the server, not from arithmetic in the browser: the
        polling call returns figures already computed and formatted against
        `profit_records`, so a tampered response cannot make the console display
        earnings that were not recorded. Before the first poll lands, the values
        rendered by Blade are shown, so the panel is never blank.
    --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Profit today</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="banknotes" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2 tabular-nums" x-text="today"></p>
                <p class="mt-1 text-xs text-muted-foreground">
                    <span x-text="todayOrders"></span> sale(s) · <span x-text="todayRevenue"></span> revenue
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <span class="stat-label">Measured on a confirmed cost</span>
                <p class="stat-value mt-2 tabular-nums">{{ $periods['today']['formatted']['verified_profit'] }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Today's profit booked against a provider rate that has been verified.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <span class="stat-label">Estimated — not yet confirmed</span>
                <p class="stat-value mt-2 tabular-nums">{{ $periods['today']['formatted']['estimated_profit'] }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{-- The distinction the whole figure depends on: a margin computed
                         from a rate somebody typed into the console is a forecast. --}}
                    {{ number_format($periods['today']['estimated_transactions']) }} of
                    {{ number_format($periods['today']['transactions']) }} sale(s) priced against an
                    assumed cost. Confirm the network rate under Pricing → Provider costs to move it
                    into the verified column.
                </p>
            </div>
        </div>
    </div>

    {{-- Today, per service. The first question an operator asks. --}}
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Today by service</h2>
            <p class="card-description">Profit recorded so far today, from settled sales.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th class="text-right">Sales</th>
                        <th class="text-right">Revenue</th>
                        <th class="text-right">Provider cost</th>
                        <th class="text-right">Profit</th>
                        <th class="text-right">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($today['by_service'] as $row)
                        <tr>
                            <td class="font-medium">{{ ucfirst(str_replace('-', ' ', $row['key'])) }}</td>
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
                            <td colspan="6" class="text-center text-muted-foreground">
                                No settled sales yet today.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

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
        'By service' => $byService,
        'By product' => $byProduct,
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

{{--
    The polling script is pushed *after* `@endsection`, at the file's root, which is
    where the admin layout's `@stack('scripts')` picks it up — the same placement
    `admin/admins/index.blade.php` uses.

    It was previously inside `@section('content')` while a comment claimed it was
    outside. That is why the "profit today" figure never updated: a `@push` inside a
    section is buffered with the section rather than registered against the stack, so
    the script was never emitted and nothing reported an error. A test now asserts the
    script is present in the rendered page, because this is exactly the kind of
    omission that no PHP error and no page-level assertion would have caught.
--}}
@endsection

@push('scripts')
<script>
    /*
     * Polls the profit endpoint so the figures move while an operator watches.
     *
     * Deliberately small, and deliberately dumb: it fetches numbers the server has
     * already computed and formatted, and writes them into the DOM as text. It does
     * no arithmetic, because the browser is the one place a money figure must never
     * be derived — and because a client that summed its own totals would be a
     * second, silently different answer to the same question.
     *
     * `visibilitychange` stops the timer on a hidden tab. A dashboard left open
     * overnight should not poll all night, and a background poll that resumes on
     * return gives the operator current figures the moment they look.
     */
    document.addEventListener('alpine:init', () => {
        Alpine.data('profitLive', (initial) => ({
            today: initial.today,
            todayRevenue: initial.todayRevenue,
            todayOrders: initial.todayOrders,
            timer: null,

            start() {
                this.timer = setInterval(() => this.refresh(), 30000);

                document.addEventListener('visibilitychange', () => {
                    if (document.hidden) {
                        clearInterval(this.timer);
                    } else {
                        this.refresh();
                        this.timer = setInterval(() => this.refresh(), 30000);
                    }
                });
            },

            async refresh() {
                if (document.hidden) {
                    return;
                }

                try {
                    const response = await fetch(initial.endpoint, {
                        headers: { Accept: 'application/json' },
                    });

                    if (!response.ok) {
                        return;
                    }

                    const data = await response.json();

                    // The server's formatted strings, assigned verbatim.
                    this.today = data.today.formatted.gross_profit;
                    this.todayRevenue = data.today.formatted.revenue;
                    this.todayOrders = data.today.transactions;
                } catch (error) {
                    /*
                     * A failed poll is not an error worth showing. The figures on
                     * screen are the last ones the server confirmed, which is a
                     * better answer than a blank panel or a toast that says the
                     * dashboard is broken.
                     */
                }
            },
        }));
    });
</script>
@endpush

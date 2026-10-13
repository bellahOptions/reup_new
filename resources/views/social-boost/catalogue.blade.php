@extends('layouts.app')
@section('title', 'Social Boost services')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Social Boost — service catalogue
    |--------------------------------------------------------------------------
    | Server-rendered and paginated rather than infinitely scrolled, for three
    | reasons: it works without JavaScript, it is crawlable, and a customer can
    | share or bookmark a filtered URL. Filtering is a normal GET, so the
    | back button behaves and no state lives in the browser.
    |
    | Everything shown comes from the synchronised catalogue. A service with no
    | published rate, or whose offering has vanished upstream, is not listed at
    | all — see `SmmCatalogue::sellableProducts()`.
    */
    $activePlatform = $filters['platform'] ?? null;
    $activeType = $filters['type'] ?? null;
    $term = $filters['q'] ?? null;

    $query = function (array $overrides = []) use ($activePlatform, $activeType, $term) {
        return array_filter(array_merge([
            'platform' => $activePlatform,
            'type' => $activeType,
            'q' => $term,
        ], $overrides), fn ($value) => $value !== null && $value !== '');
    };

    // Resolved from the platform list rather than from the raw filter, so the
    // heading names the platform the way the customer read it on the card.
    $activePlatformLabel = $activePlatform
        ? (collect($platforms)->firstWhere('key', $activePlatform)['label'] ?? null)
        : null;
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        {{-- ===================== Heading ===================== --}}
        <nav class="mb-4 flex items-center gap-2 text-xs text-muted-foreground" aria-label="Breadcrumb">
            <a href="{{ route('social-boost.index', [], false) }}" class="link">Social Boost</a>
            <x-icon name="chevron-right" class="h-3 w-3" />
            <span>Services</span>
        </nav>

        <header class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight">Boost services</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                {{ number_format($results['total']) }}
                {{ \Illuminate\Support\Str::plural('service', $results['total']) }}
                available{{ $activePlatformLabel ? ' on ' . $activePlatformLabel : '' }}.
            </p>
        </header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-4 lg:gap-8">

            {{-- ===================== Filters ===================== --}}
            <aside class="lg:col-span-1">
                <form method="GET" action="{{ route('social-boost.catalogue', [], false) }}" class="card p-4 md:p-5">
                    <div>
                        <label for="q" class="label">Search</label>
                        <div class="relative mt-1.5">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                <x-icon name="magnifying-glass" class="h-4 w-4" />
                            </span>
                            <input type="search" id="q" name="q" value="{{ $term }}"
                                   class="input pl-9" placeholder="followers, views, Instagram…"
                                   autocomplete="off">
                        </div>
                    </div>

                    @if($activePlatform)
                        <input type="hidden" name="platform" value="{{ $activePlatform }}">
                    @endif

                    <div class="mt-5">
                        <span class="label">Platform</span>
                        <div class="mt-2 flex flex-wrap gap-1.5" role="group" aria-label="Filter by platform">
                            <a href="{{ route('social-boost.catalogue', $query(['platform' => null, 'type' => null])) }}"
                               class="rounded-full border px-3 py-1 text-xs font-medium transition-colors
                                      {{ $activePlatform ? 'border-border bg-surface text-muted-foreground hover:bg-ink-50' : 'border-brand-500 bg-brand-500 text-primary-foreground' }}">
                                All
                            </a>
                            @foreach($platforms as $platform)
                                <a href="{{ route('social-boost.catalogue', $query(['platform' => $platform['key'], 'type' => null])) }}"
                                   class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors
                                          {{ $activePlatform === $platform['key'] ? 'border-brand-500 bg-brand-500 text-primary-foreground' : 'border-border bg-surface text-muted-foreground hover:bg-ink-50' }}">
                                    <x-icon :name="$platform['icon']" class="h-3.5 w-3.5" />
                                    {{ $platform['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>

                    @if(!empty($serviceTypes))
                        <div class="mt-5">
                            <span class="label">Category</span>
                            <div class="mt-2 flex flex-wrap gap-1.5" role="group" aria-label="Filter by category">
                                <a href="{{ route('social-boost.catalogue', $query(['type' => null])) }}"
                                   class="rounded-full border px-3 py-1 text-xs font-medium transition-colors
                                          {{ $activeType ? 'border-border bg-surface text-muted-foreground hover:bg-ink-50' : 'border-brand-500 bg-brand-500 text-primary-foreground' }}">
                                    Any
                                </a>
                                @foreach($serviceTypes as $type => $count)
                                    <a href="{{ route('social-boost.catalogue', $query(['type' => $type])) }}"
                                       class="rounded-full border px-3 py-1 text-xs font-medium transition-colors
                                              {{ $activeType === $type ? 'border-brand-500 bg-brand-500 text-primary-foreground' : 'border-border bg-surface text-muted-foreground hover:bg-ink-50' }}">
                                        {{ $type }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="mt-5 flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-1">Apply</button>
                        @if($activePlatform || $activeType || $term)
                            <a href="{{ route('social-boost.catalogue', [], false) }}" class="btn btn-outline btn-sm">Clear</a>
                        @endif
                    </div>
                </form>

                <div class="card mt-4 p-4 md:p-5">
                    <p class="flex items-start gap-2 text-xs text-muted-foreground">
                        <x-icon name="information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                        <span>
                            Prices depend on the quantity you choose, so each service shows the lowest price it
                            can be bought for.
                            <a href="{{ route('social-boost.help', [], false) }}" class="link font-medium">Read the guide</a>.
                        </span>
                    </p>
                </div>
            </aside>

            {{-- ===================== Results ===================== --}}
            <div class="lg:col-span-3">
                @if($results['services']->isEmpty())
                    <div class="card">
                        <div class="empty-state">
                            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                                <x-icon name="magnifying-glass" class="h-5 w-5" />
                            </span>
                            <p class="mt-4 text-sm font-medium">No services match that</p>
                            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                                @if($term || $activePlatform || $activeType)
                                    Try a different platform or category, or clear the filters.
                                @else
                                    We are updating our service list. Please check back shortly.
                                @endif
                            </p>
                            <a href="{{ route('social-boost.catalogue', [], false) }}" class="btn btn-outline btn-sm mt-5">
                                Show all services
                            </a>
                        </div>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($results['services'] as $row)
                            <article class="card p-4 transition-colors hover:border-brand-300 md:p-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                                <x-icon :name="collect($platforms)->firstWhere('key', $row['platform_key'])['icon'] ?? 'globe'"
                                                        class="h-3 w-3" />
                                                {{ $row['platform_label'] }}
                                            </span>
                                            <span class="rounded-full bg-ink-100 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                                {{ $row['type'] }}
                                            </span>
                                            @if($row['supports_refill'])
                                                <span class="rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700">
                                                    Refill available
                                                </span>
                                            @endif
                                        </div>

                                        <h3 class="mt-2 text-sm font-semibold">{{ $row['name'] }}</h3>

                                        @if($row['description'])
                                            <p class="mt-1 line-clamp-2 text-xs text-muted-foreground">{{ $row['description'] }}</p>
                                        @endif

                                        {{-- A requirement is not marketing copy: it is
                                             setup the buyer must complete or the order
                                             cannot be delivered. Surfaced here, and
                                             again on the order form. --}}
                                        @if($row['requirement'])
                                            <p class="mt-2 flex items-start gap-1.5 rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
                                                <x-icon name="exclamation-triangle" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                <span>{{ \Illuminate\Support\Str::limit($row['requirement'], 160) }}</span>
                                            </p>
                                        @endif

                                        <p class="mt-2 text-xs text-muted-foreground">
                                            Minimum {{ $row['min_quantity_label'] }}@if($row['max_quantity_label']) &middot; maximum {{ $row['max_quantity_label'] }}@endif
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 flex-col items-start gap-2 sm:items-end">
                                        @if($row['minimum_price_formatted'])
                                            <div class="text-left sm:text-right">
                                                <p class="text-xs text-muted-foreground">From</p>
                                                <p class="text-lg font-semibold tabular-nums">{{ $row['minimum_price_formatted'] }}</p>
                                            </div>
                                            <a href="{{ route('social-boost.configure', $row['key']) }}" class="btn btn-primary btn-sm w-full sm:w-auto">
                                                Order now
                                                <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                                            </a>
                                        @else
                                            {{-- A service the engine cannot price is not
                                                 offered rather than offered and then
                                                 refused at checkout. --}}
                                            <span class="badge badge-neutral">Unavailable</span>
                                            <span class="text-xs text-muted-foreground">Pricing is being updated</span>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    {{-- ===================== Pagination ===================== --}}
                    @if($results['pages'] > 1)
                        <nav class="mt-6 flex items-center justify-between gap-4" aria-label="Pagination">
                            <div class="text-xs text-muted-foreground">
                                Page {{ $results['page'] }} of {{ $results['pages'] }}
                            </div>
                            <div class="flex gap-2">
                                @if($results['page'] > 1)
                                    <a href="{{ route('social-boost.catalogue', $query(['page' => $results['page'] - 1])) }}"
                                       class="btn btn-outline btn-sm">
                                        <x-icon name="chevron-left" class="h-3.5 w-3.5" />
                                        Previous
                                    </a>
                                @endif
                                @if($results['page'] < $results['pages'])
                                    <a href="{{ route('social-boost.catalogue', $query(['page' => $results['page'] + 1])) }}"
                                       class="btn btn-outline btn-sm">
                                        Next
                                        <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                                    </a>
                                @endif
                            </div>
                        </nav>
                    @endif
                @endif
            </div>
        </div>
    </div>
</main>
@endsection

@extends('layouts.app')

{{--
    The service catalogue.

    Every value on this page comes from `$catalogue`, which is assembled by
    App\Catalogue\ServiceCatalogue from the database. Nothing here hardcodes a
    service, an availability, a price or a provider (§51.42).

    Two rules this template follows deliberately:

      * it lists **only what can be bought**. A browsing surface that advertises
        services it cannot sell is a longer list of disappointments, and a section
        with nothing available is omitted rather than rendered as a heading over an
        apology. The §51.36 "temporarily unavailable" copy belongs on a service's own
        page, when a customer reaches for that specific service;
      * no price and no provider name. A price belongs to a quote at checkout (§51.38),
        and providers are an internal implementation detail (§51.36, §51.44).
--}}

@section('title', 'Services')

@section('content')
<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-12">

        <header class="mb-8 md:mb-10">
            <h1 class="text-2xl font-semibold md:text-3xl">{{ \App\Support\UiCopy::get('search.placeholder') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                {{ $catalogue['search']['hint'] }}
            </p>
        </header>

        @foreach($catalogue['sections'] as $section)
            <section class="mb-10" aria-labelledby="section-{{ $section['group'] }}">
                <h2 id="section-{{ $section['group'] }}" class="text-lg font-semibold md:text-xl">
                    {{ $section['name'] }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">{{ $section['dashboard_description'] }}</p>

                <ul class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($section['services'] as $service)
                        <li class="card p-4">
                            <p class="font-medium">{{ $service['name'] }}</p>
                            @if($service['description'])
                                <p class="mt-1 text-sm text-muted-foreground">{{ $service['description'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        {{-- "My ReUp" is not a catalogue group: it is the customer's own payments,
             saved bills and reminders (§51.8). --}}
        <section class="mb-4" aria-labelledby="section-my-reup">
            <h2 id="section-my-reup" class="text-lg font-semibold md:text-xl">{{ $catalogue['my_reup']['name'] }}</h2>
            <p class="mt-1 text-sm text-muted-foreground">{{ $catalogue['my_reup']['description'] }}</p>
        </section>

    </div>
</main>
@endsection

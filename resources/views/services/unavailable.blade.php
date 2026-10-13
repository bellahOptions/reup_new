@extends('layouts.app')

@section('title', $state === App\Services\ServiceGate::COMING_SOON ? $label . ' — coming soon' : $label . ' — not available')

@push('styles')
    {{-- A placeholder page has no business in a search index, and neither has a
         refusal: the first is not content and the second is not a destination. --}}
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
@php
    $isComingSoon = $state === App\Services\ServiceGate::COMING_SOON;

    $heading = str_replace(':service', $label, (string) ($copy[$isComingSoon ? 'coming_soon_heading' : 'disabled_heading'] ?? ''));
    $body = (string) ($copy[$isComingSoon ? 'coming_soon_body' : 'disabled_body'] ?? '');
    $eyebrow = (string) ($copy[$isComingSoon ? 'coming_soon_title' : 'disabled_title'] ?? '');
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page flex min-h-[70vh] items-center justify-center py-12">
        <div class="w-full max-w-lg text-center">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl {{ $isComingSoon ? 'bg-brand-50 text-brand-600' : 'bg-ink-100 text-ink-500' }}">
                <x-icon :name="$isComingSoon ? 'rocket-launch' : 'exclamation-triangle'" variant="solid" class="h-8 w-8" />
            </span>

            <p class="mt-5 text-xs font-semibold uppercase tracking-wide {{ $isComingSoon ? 'text-brand-600' : 'text-ink-500' }}">
                {{ $eyebrow }}
            </p>

            <h1 class="mt-2 text-2xl font-semibold md:text-3xl">
                {{ $heading }}
            </h1>

            <p class="mx-auto mt-3 max-w-md text-sm text-muted-foreground md:text-base">
                {{ $body }}
            </p>

            @if($isComingSoon)
                {{-- The one thing a customer on a coming-soon page actually wants:
                     somewhere useful to go. A placeholder that dead-ends is how a
                     customer concludes the whole site is unfinished. --}}
                <div class="mt-7 flex flex-col items-center justify-center gap-2 sm:flex-row">
                    <a href="{{ route($availableNow, [], false) }}" class="btn btn-primary w-full sm:w-auto">
                        {{ $copy['coming_soon_cta'] ?? 'Explore what is available now' }}
                    </a>
                    @if(Route::has('contact'))
                        <a href="{{ route('contact', [], false) }}" class="btn btn-outline w-full sm:w-auto">
                            Ask us about it
                        </a>
                    @endif
                </div>
            @else
                <div class="mt-7 flex flex-col items-center justify-center gap-2 sm:flex-row">
                    <a href="{{ route($availableNow, [], false) }}" class="btn btn-primary w-full sm:w-auto">
                        Back to what is available
                    </a>
                    @if(Route::has('contact'))
                        <a href="{{ route('contact', [], false) }}" class="btn btn-outline w-full sm:w-auto">
                            Contact support
                        </a>
                    @endif
                </div>
            @endif

            <p class="mt-6 text-xs text-muted-foreground">
                Your wallet is untouched — nothing was charged and no order was created.
            </p>
        </div>
    </div>
</main>
@endsection

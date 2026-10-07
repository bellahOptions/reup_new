@extends('layouts.main')
@section('title', 'Privacy policy')
@section('main')
@php
    /*
    |--------------------------------------------------------------------------
    | Privacy policy
    |--------------------------------------------------------------------------
    | TermsController::showPrivacy() passes a nullable `$privacy` (the active row
    | of terms_privacies). `content` is authored by admins, so it is the one
    | field rendered unescaped.
    |
    | The inline <style> block and the quill.snow.css CDN <link> are gone; the
    | typography now comes from the scoped `.legal-prose` rules in app.css.
    */
    $supportEmail = $siteSettings['support_email'] ?? config('services.support.email');
    $supportPhone = $siteSettings['contact_phone'] ?? config('services.support.phone');
@endphp

<main class="min-h-screen bg-background">
    <div class="container-page py-10 md:py-16">

        {{-- Page heading --}}
        <header class="mb-8 max-w-3xl md:mb-10">
            <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Privacy policy</h1>
            <p class="mt-3 text-sm text-muted-foreground">
                Last updated {{ $privacy ? $privacy->formatted_version_date : now()->format('F j, Y') }}
            </p>
        </header>

        @if($privacy && filled($privacy->content))
            <article class="legal-prose overflow-hidden">
                {!! $privacy->content !!}
            </article>
        @else
            {{-- No active document row, or one with an empty body. --}}
            <div class="card max-w-3xl">
                <div class="empty-state">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                        <x-icon name="lock-closed" class="h-6 w-6" />
                    </span>
                    <h2 class="mt-4 text-base font-semibold">Privacy policy not published yet</h2>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                        Our privacy policy has not been published on this site yet. We only collect what we
                        need to process your payments and deliver your orders.
                    </p>
                    <div class="mt-5 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('contact') }}" class="btn btn-primary btn-sm">
                            <x-icon name="envelope" class="h-4 w-4" />
                            Ask about your data
                        </a>
                        <a href="{{ route('terms-of-service') }}" class="btn btn-outline btn-sm">Read our terms</a>
                    </div>
                </div>
            </div>
        @endif

        {{-- Data requests --}}
        <section class="mt-12 max-w-3xl border-t border-border pt-8">
            <h2 class="text-base font-semibold">Your data, your call</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                You can ask us for a copy of the personal data we hold, ask us to correct it, or ask us to
                delete it. Reach the support team and we will action the request.
            </p>
            <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                @if($supportEmail)
                    <span class="flex items-center gap-2">
                        <x-icon name="envelope" class="h-4 w-4 shrink-0 text-ink-400" />
                        <a href="mailto:{{ $supportEmail }}" class="link break-all">{{ $supportEmail }}</a>
                    </span>
                @endif
                @if($supportPhone)
                    <span class="flex items-center gap-2">
                        <x-icon name="phone" class="h-4 w-4 shrink-0 text-ink-400" />
                        <a href="tel:{{ preg_replace('/\s+/', '', $supportPhone) }}" class="link tabular-nums">{{ $supportPhone }}</a>
                    </span>
                @endif
                <span class="flex items-center gap-2">
                    <x-icon name="document-check" class="h-4 w-4 shrink-0 text-ink-400" />
                    <a href="{{ route('terms-of-service') }}" class="link">Terms of service</a>
                </span>
            </div>
        </section>
    </div>
</main>
@endsection

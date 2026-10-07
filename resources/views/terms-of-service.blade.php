@extends('layouts.main')
@section('title', 'Terms of service')
@section('main')
@php
    /*
    |--------------------------------------------------------------------------
    | Terms of service
    |--------------------------------------------------------------------------
    | TermsController::showTerms() passes a nullable `$terms` (the active row of
    | terms_privacies). `content` is authored by admins in a rich-text editor,
    | so it is the one field rendered unescaped.
    |
    | The previous version pulled quill.snow.css from a CDN and carried two
    | inline <style> blocks with `@apply` (which Tailwind never processes inside
    | a view). Typography now comes from the scoped `.legal-prose` rules in
    | resources/css/app.css, so there is no stylesheet to fetch and no <style>
    | block to emit.
    */
    $supportEmail = $siteSettings['support_email'] ?? config('services.support.email');
    $supportPhone = $siteSettings['contact_phone'] ?? config('services.support.phone');
@endphp

<main class="min-h-screen bg-background">
    <div class="container-page py-10 md:py-16">

        {{-- Page heading --}}
        <header class="mb-8 max-w-3xl md:mb-10">
            <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Terms of service</h1>
            <p class="mt-3 text-sm text-muted-foreground">
                Last updated {{ $terms ? $terms->formatted_version_date : now()->format('F j, Y') }}
            </p>
        </header>

        @if($terms && filled($terms->content))
            <div class="mb-8 flex max-w-3xl items-start gap-3 rounded-lg border border-border bg-surface p-4">
                <x-icon name="document-check" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />
                <p class="text-sm text-muted-foreground">
                    By accessing or using ReUp you agree to be bound by these terms. Please read them carefully.
                </p>
            </div>

            <article class="legal-prose overflow-hidden">
                {!! $terms->content !!}
            </article>
        @else
            {{-- No active document row, or one with an empty body. --}}
            <div class="card max-w-3xl">
                <div class="empty-state">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                        <x-icon name="document-text" class="h-6 w-6" />
                    </span>
                    <h2 class="mt-4 text-base font-semibold">Terms not published yet</h2>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                        Our terms of service have not been published on this site yet. In the meantime,
                        our support team can answer any question about how ReUp works.
                    </p>
                    <div class="mt-5 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('contact') }}" class="btn btn-primary btn-sm">
                            <x-icon name="envelope" class="h-4 w-4" />
                            Contact support
                        </a>
                        <a href="{{ route('faq') }}" class="btn btn-outline btn-sm">Read the FAQ</a>
                    </div>
                </div>
            </div>
        @endif

        {{-- Contact block --}}
        <section class="mt-12 max-w-3xl border-t border-border pt-8">
            <h2 class="text-base font-semibold">Questions about these terms?</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Reach the team that maintains this document.
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
                    <x-icon name="document-text" class="h-4 w-4 shrink-0 text-ink-400" />
                    <a href="{{ route('privacy-policy') }}" class="link">Privacy policy</a>
                </span>
            </div>
        </section>
    </div>
</main>
@endsection

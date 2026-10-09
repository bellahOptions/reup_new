<!doctype html>
{{-- Appearance is resolved once, here, and published on <html> so the inline
     bootstrap in partials/theme can read it and correct it before the first
     paint. Never move these attributes to <body>: the browser will already
     have painted a white page by then. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" {!! $themeState->attributes() !!}>
<head>
    @include('partials.head')

    @hasSection('title')
        <title>@yield('title') — ReUp</title>
    @else
        <title>ReUp — Dashboard</title>
    @endif

    @stack('styles')
</head>
<body class="min-h-screen bg-surface">
    <div class="flex min-h-screen flex-col">
        @include('layouts.navbar')

        {{-- Phone-number nudge. Rendered from the controller-provided attribute
             rather than querying the user in the view. --}}
        @auth
            @if(auth()->user()->requires_phone_update && !request()->is('profile*'))
                <div class="border-b border-amber-200 bg-amber-50">
                    <div class="container-page flex flex-col items-start justify-between gap-2 py-2.5 sm:flex-row sm:items-center">
                        <p class="flex items-center gap-2 text-sm text-amber-900">
                            <x-icon name="exclamation-triangle" class="h-4 w-4 shrink-0 text-amber-600" />
                            <span><strong class="font-semibold">Action required:</strong> add your phone number so we can reach you about transactions.</span>
                        </p>
                        <a href="{{ route('profile.index') }}" class="btn btn-sm shrink-0 bg-amber-600 text-white hover:brightness-95">
                            Add phone number
                        </a>
                    </div>
                </div>
            @endif
        @endauth

        <main class="flex-1">
            @yield('content')
        </main>

        @include('layouts.footer')
    </div>

    @include('partials.support-widget')

    {{-- Flash messages, delivered through a single toast channel. --}}
    @include('partials.flash')

    @stack('scripts')
</body>
</html>

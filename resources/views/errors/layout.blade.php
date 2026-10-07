<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')

    @hasSection('title')
        <title>@yield('title') — {{ config('app.name', 'ReUp') }}</title>
    @else
        <title>{{ config('app.name', 'ReUp') }}</title>
    @endif

    {{-- Error pages must never be indexed. --}}
    <meta name="robots" content="noindex, nofollow">
</head>
<body class="min-h-screen bg-surface">
    <div class="flex min-h-screen flex-col">

        <header class="border-b border-border bg-white">
            <div class="container-page flex h-16 items-center">
                <a href="{{ url('/') }}" class="flex items-center gap-2">
                    <img src="{{ asset('images/reup-03.svg') }}" alt="ReUp" class="h-7 w-auto">
                </a>
            </div>
        </header>

        <main class="flex flex-1 items-center justify-center px-4 py-16">
            <div class="w-full max-w-lg text-center">
                @yield('content')
            </div>
        </main>

        <footer class="border-t border-border bg-white">
            <div class="container-page flex flex-col items-center justify-between gap-2 py-5 text-xs text-muted-foreground sm:flex-row">
                <p>&copy; {{ date('Y') }} {{ config('app.name', 'ReUp') }}. All rights reserved.</p>
                <nav class="flex items-center gap-4">
                    <a href="{{ url('/') }}" class="link">Home</a>
                    <a href="{{ route('contact') }}" class="link">Support</a>
                    <a href="{{ route('privacy-policy') }}" class="link">Privacy</a>
                    <a href="{{ route('terms-of-service') }}" class="link">Terms</a>
                </nav>
            </div>
        </footer>
    </div>
</body>
</html>

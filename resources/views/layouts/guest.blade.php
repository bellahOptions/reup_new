<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <title>@yield('title', 'ReUp') | {{ config('app.name', 'ReUp') }}</title>
    @stack('styles')
</head>
<body class="min-h-screen bg-surface">
    <div class="flex min-h-screen flex-col">
        <header class="border-b bg-white">
            <div class="container-page flex h-16 items-center justify-between">
                <a href="{{ route('home') }}" class="flex items-center gap-2">
                    <img src="{{ asset('images/reup-03.svg') }}" alt="ReUp" class="h-7 w-auto">
                </a>
                <a href="{{ route('home') }}" class="btn btn-ghost btn-sm text-muted-foreground">
                    <x-icon name="arrow-left" class="h-4 w-4" />
                    Back to home
                </a>
            </div>
        </header>

        <main class="flex flex-1 items-center justify-center px-4 py-10 sm:py-16">
            <div class="w-full max-w-md">
                {{ $slot }}
            </div>
        </main>

        <footer class="border-t bg-white">
            <div class="container-page flex flex-col items-center justify-between gap-2 py-5 text-sm text-muted-foreground sm:flex-row">
                <p>&copy; {{ date('Y') }} {{ config('app.name', 'ReUp') }}</p>
                <div class="flex items-center gap-4">
                    <a href="{{ route('privacy-policy') }}" class="link">Privacy</a>
                    <a href="{{ route('terms-of-service') }}" class="link">Terms</a>
                    <a href="{{ route('faq') }}" class="link">FAQ</a>
                </div>
            </div>
        </footer>
    </div>
    {{-- Guests have no dashboard and no contact link on these screens, so the
         WhatsApp bubble is their only route to a human. --}}
    @include('partials.support-widget')
    @stack('scripts')
</body>
</html>

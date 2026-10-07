<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')

    <title>Admin sign in — {{ config('app.name', 'ReUp') }}</title>
    <meta name="robots" content="noindex, nofollow">
</head>
<body class="min-h-screen bg-surface">
    <div class="grid min-h-screen lg:grid-cols-2">

        {{-- Brand panel. Replaces the gradient-and-glow hero; the console is an
             internal tool, so it reads as one. --}}
        <aside class="relative hidden flex-col justify-between bg-ink-950 p-12 lg:flex">
            <img src="{{ asset('images/reup-04.svg') }}" alt="ReUp" class="h-7 w-auto brightness-0 invert">

            <div>
                <h1 class="max-w-sm text-3xl font-semibold leading-tight tracking-tight text-white">
                    Operate the platform.
                </h1>
                <p class="mt-4 max-w-sm text-sm leading-relaxed text-ink-400">
                    Settlement, customer records, wallet adjustments, support and
                    announcements — in one place, with every action logged.
                </p>

                <ul class="mt-10 space-y-3.5">
                    @foreach([
                        ['Transactions settle against the gateway before a wallet moves.', 'shield-check'],
                        ['Every administrative action is attributed and timestamped.', 'finger-print'],
                        ['Access is scoped by role, not by trust.', 'lock-closed'],
                    ] as [$point, $icon])
                        <li class="flex items-start gap-3">
                            <x-icon :name="$icon" class="mt-0.5 h-4 w-4 shrink-0 text-brand-400" />
                            <span class="text-sm text-ink-300">{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <p class="text-xs text-ink-600">
                &copy; {{ date('Y') }} {{ config('app.name', 'ReUp') }} · Bellah Options
            </p>
        </aside>

        {{-- Form --}}
        <main class="flex items-center justify-center px-4 py-12 sm:px-8">
            <div class="w-full max-w-sm">
                <a href="{{ route('home') }}" class="mb-10 flex items-center gap-2 lg:hidden">
                    <img src="{{ asset('images/reup-03.svg') }}" alt="ReUp" class="h-7 w-auto">
                </a>

                <h2 class="text-2xl font-semibold tracking-tight">Sign in</h2>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    Administrator credentials required.
                </p>

                @if($errors->any())
                    <div class="mt-6 flex items-start gap-2.5 rounded-lg border border-red-200 bg-red-50 px-3.5 py-3">
                        <x-icon name="exclamation-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-red-800">Could not sign you in</p>
                            <ul class="mt-1 space-y-0.5">
                                @foreach($errors->all() as $error)
                                    <li class="text-sm text-red-700">{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                <form action="{{ route('admin.login.post') }}" method="POST" class="mt-6 space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="label">Email address</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="you@reup.com.ng"
                            class="input mt-1.5 @error('email') input-error @enderror">
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label for="password" class="label">Password</label>
                            <a href="{{ route('password.request') }}" class="link text-xs font-medium">Forgot?</a>
                        </div>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••••••"
                            class="input mt-1.5 @error('password') input-error @enderror">
                    </div>

                    <label for="remember" class="flex cursor-pointer items-center gap-2.5">
                        <input id="remember" name="remember" type="checkbox" class="checkbox">
                        <span class="text-sm text-muted-foreground">Keep me signed in</span>
                    </label>

                    <button type="submit" class="btn btn-primary w-full">
                        <x-icon name="lock-closed" class="h-4 w-4" />
                        Sign in
                    </button>
                </form>

                <div class="mt-8 flex items-start gap-2.5 rounded-lg border border-border bg-white px-3.5 py-3">
                    <x-icon name="finger-print" class="mt-0.5 h-4 w-4 shrink-0 text-ink-400" />
                    <p class="text-xs text-muted-foreground">
                        Admin sessions are recorded with IP address and user agent.
                        Repeated failed attempts are rate limited.
                    </p>
                </div>

                <p class="mt-6 text-center text-xs text-muted-foreground">
                    Need help?
                    <a href="mailto:{{ config('services.support.email') }}" class="link font-medium">Contact support</a>
                </p>
            </div>
        </main>
    </div>
</body>
</html>

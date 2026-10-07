<x-guest-layout>
    @section('title', 'Sign in')

    <div class="card">
        <div class="card-content sm:p-7">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold tracking-tight">Welcome back</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    Sign in to fund your wallet and pay bills.
                </p>
            </div>

            @if(session('status'))
                <div class="mb-5 flex items-start gap-2.5 rounded-lg border border-brand-200 bg-accent px-3.5 py-3">
                    <x-icon name="information-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                    <p class="text-sm text-accent-foreground">{{ session('status') }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-5">
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
                        placeholder="you@example.com"
                        class="input mt-1.5 @error('email') input-error @enderror">
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label for="password" class="label">Password</label>
                        @if(Route::has('password.request'))
                            <a href="{{ route('password.request') }}" class="link text-xs font-medium">Forgot password?</a>
                        @endif
                    </div>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        placeholder="••••••••"
                        class="input mt-1.5 @error('password') input-error @enderror">
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <label for="remember_me" class="flex cursor-pointer items-center gap-2.5">
                    <input id="remember_me" name="remember" type="checkbox" class="checkbox">
                    <span class="text-sm text-muted-foreground">Keep me signed in on this device</span>
                </label>

                <button type="submit" class="btn btn-primary w-full">
                    Sign in
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </button>
            </form>

            @if(Route::has('login.code'))
                <div class="my-5 flex items-center gap-3">
                    <span class="h-px flex-1 bg-border"></span>
                    <span class="text-xs uppercase tracking-wide text-muted-foreground">or</span>
                    <span class="h-px flex-1 bg-border"></span>
                </div>

                <a href="{{ route('login.code') }}" class="btn btn-outline w-full">
                    <x-icon name="envelope" class="h-4 w-4" />
                    Sign in with a one-time code
                </a>
            @endif
        </div>

        <div class="card-footer justify-center">
            <p class="text-sm text-muted-foreground">
                New to ReUp?
                <a href="{{ route('register') }}" class="link font-medium">Create an account</a>
            </p>
        </div>
    </div>

    <p class="mt-6 text-center text-xs text-muted-foreground">
        By signing in you agree to our
        <a href="{{ route('terms-of-service') }}" class="link">terms</a> and
        <a href="{{ route('privacy-policy') }}" class="link">privacy policy</a>.
    </p>
</x-guest-layout>

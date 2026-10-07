<x-guest-layout>
    @section('title', 'Reset your password')

    <div class="card">
        <div class="card-content sm:p-7">
            <div class="mb-6">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-brand-600">
                    <x-icon name="key" class="h-5 w-5" />
                </span>
                <h1 class="mt-5 text-2xl font-semibold tracking-tight">Forgot your password?</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    Enter the email on your account and we will send you a link to choose a new one.
                </p>
            </div>

            @if(session('status'))
                <div class="mb-5 flex items-start gap-2.5 rounded-lg border border-brand-200 bg-accent px-3.5 py-3">
                    <x-icon name="check-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                    <p class="text-sm text-accent-foreground">{{ session('status') }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
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

                <button type="submit" class="btn btn-primary w-full">
                    Email me a reset link
                    <x-icon name="paper-airplane" class="h-4 w-4" />
                </button>
            </form>
        </div>

        <div class="card-footer items-center justify-between">
            <a href="{{ route('login') }}" class="link inline-flex items-center gap-1.5 text-sm font-medium">
                <x-icon name="arrow-left" class="h-3.5 w-3.5" />
                Back to sign in
            </a>
            <a href="{{ route('contact') }}" class="link inline-flex items-center gap-1.5 text-sm font-medium">
                <x-icon name="lifebuoy" class="h-3.5 w-3.5" />
                Need help?
            </a>
        </div>
    </div>
</x-guest-layout>

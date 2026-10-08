<x-guest-layout>
    @section('title', 'Sign in with a code')

    <div class="card">
        <div class="card-content sm:p-7">
            <div class="mb-6">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-1 text-xs font-medium text-muted-foreground">
                    <x-icon name="envelope" class="h-3.5 w-3.5" />
                    Password-free
                </span>
                <h1 class="mt-3 text-2xl font-semibold tracking-tight">Sign in with a code</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    Enter your email address and we will send a 6-digit code. It expires in
                    10 minutes and works once.
                </p>
            </div>

            <form method="POST" action="{{ route('login.code.send', [], false) }}" class="space-y-5">
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
                    Send me a code
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </button>
            </form>
        </div>

        <div class="card-footer justify-center">
            <p class="text-sm text-muted-foreground">
                Remembered your password?
                <a href="{{ route('login') }}" class="link font-medium">Sign in with a password</a>
            </p>
        </div>
    </div>

    <div class="mt-6 flex items-start gap-2.5 rounded-lg border border-border bg-surface px-4 py-3">
        <x-icon name="shield-check" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
        <p class="text-xs text-muted-foreground">
            We never say whether an email address has an account, so this form cannot be
            used to find out who banks with us.
        </p>
    </div>
</x-guest-layout>

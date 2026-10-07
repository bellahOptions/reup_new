<x-guest-layout>
    @section('title', 'Confirm your password')

    <div class="card">
        <div class="card-content sm:p-7">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-brand-600">
                <x-icon name="shield-check" class="h-5 w-5" />
            </span>

            <h1 class="mt-5 text-2xl font-semibold tracking-tight">Confirm your password</h1>
            <p class="mt-1.5 text-sm text-muted-foreground">
                This is a protected area. Please re-enter your password to continue.
            </p>

            <form method="POST" action="{{ route('password.confirm') }}" class="mt-6 space-y-5">
                @csrf

                <div>
                    <label for="password" class="label">Password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autofocus
                        autocomplete="current-password"
                        class="input mt-1.5 @error('password') input-error @enderror">
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    Confirm and continue
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>

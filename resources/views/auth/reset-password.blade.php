<x-guest-layout>
    @section('title', 'Choose a new password')

    <div class="card" x-data="{ showPassword: false, showConfirmation: false }">
        <div class="card-content sm:p-7">
            <div class="mb-6">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-brand-600">
                    <x-icon name="lock-closed" class="h-5 w-5" />
                </span>
                <h1 class="mt-5 text-2xl font-semibold tracking-tight">Choose a new password</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    Pick something you have not used before. Minimum 8 characters.
                </p>
            </div>

            <form method="POST" action="{{ route('password.update', [], false) }}" class="space-y-5">
                @csrf
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div>
                    <label for="email" class="label">Email address</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email', $request->email) }}"
                        required
                        autocomplete="username"
                        class="input mt-1.5 @error('email') input-error @enderror">
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="label">New password</label>
                    <div class="relative mt-1.5">
                        <input
                            id="password"
                            name="password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            placeholder="At least 8 characters"
                            class="input pr-11 @error('password') input-error @enderror">
                        <button type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-400 hover:text-ink-700"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'">
                            <x-icon name="eye" class="h-4 w-4" x-show="!showPassword" />
                            <x-icon name="eye-slash" class="h-4 w-4" x-show="showPassword" x-cloak />
                        </button>
                    </div>
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="label">Confirm new password</label>
                    <div class="relative mt-1.5">
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            :type="showConfirmation ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            placeholder="Repeat your new password"
                            class="input pr-11">
                        <button type="button"
                                @click="showConfirmation = !showConfirmation"
                                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-400 hover:text-ink-700"
                                :aria-label="showConfirmation ? 'Hide password' : 'Show password'">
                            <x-icon name="eye" class="h-4 w-4" x-show="!showConfirmation" />
                            <x-icon name="eye-slash" class="h-4 w-4" x-show="showConfirmation" x-cloak />
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    Update password
                    <x-icon name="check" class="h-4 w-4" />
                </button>
            </form>
        </div>

        <div class="card-footer justify-center">
            <a href="{{ route('login') }}" class="link inline-flex items-center gap-1.5 text-sm font-medium">
                <x-icon name="arrow-left" class="h-3.5 w-3.5" />
                Back to sign in
            </a>
        </div>
    </div>
</x-guest-layout>

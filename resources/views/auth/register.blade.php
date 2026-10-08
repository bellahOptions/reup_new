<x-guest-layout>
    @section('title', 'Create an account')

    <div class="card">
        <div class="card-content sm:p-7">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold tracking-tight">Create your account</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    One wallet for airtime, data, TV, power and exam PINs.
                </p>
            </div>

            <form method="POST" action="{{ route('register', [], false) }}" class="space-y-5">
                @csrf

                {{-- Referral attribution. Carried through as a hidden field so it
                     survives a validation round trip; an unknown code is ignored
                     server-side rather than blocking the sign-up. --}}
                <input type="hidden" name="ref" value="{{ old('ref', $referralCode ?? '') }}">

                @if(! empty($referralCode))
                    <div class="flex items-start gap-2.5 rounded-lg border border-brand-200 bg-accent px-3.5 py-3">
                        <x-icon name="users" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                        <p class="text-sm text-accent-foreground">
                            You were invited with code <span class="font-mono font-semibold">{{ $referralCode }}</span>.
                        </p>
                    </div>
                @endif

                <div>
                    <label for="name" class="label">Full name</label>
                    <input
                        id="name"
                        name="name"
                        type="text"
                        value="{{ old('name') }}"
                        required
                        autofocus
                        autocomplete="name"
                        placeholder="Ada Obi"
                        class="input mt-1.5 @error('name') input-error @enderror">
                    @error('name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="label">Email address</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        required
                        autocomplete="username"
                        placeholder="you@example.com"
                        class="input mt-1.5 @error('email') input-error @enderror">
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="label">Password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="new-password"
                        placeholder="At least 8 characters"
                        class="input mt-1.5 @error('password') input-error @enderror">
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="label">Confirm password</label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        placeholder="Repeat your password"
                        class="input mt-1.5">
                </div>

                <div>
                    <label for="terms" class="flex cursor-pointer items-start gap-2.5">
                        <input id="terms" name="terms" type="checkbox" value="1" required
                               class="checkbox mt-0.5" @checked(old('terms'))>
                        <span class="text-sm text-muted-foreground">
                            I agree to the
                            <a href="{{ route('terms-of-service') }}" class="link">terms of service</a>
                            and
                            <a href="{{ route('privacy-policy') }}" class="link">privacy policy</a>.
                        </span>
                    </label>
                    @error('terms')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    Create account
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </button>
            </form>
        </div>

        <div class="card-footer justify-center">
            <p class="text-sm text-muted-foreground">
                Already registered?
                <a href="{{ route('login') }}" class="link font-medium">Sign in</a>
            </p>
        </div>
    </div>
</x-guest-layout>

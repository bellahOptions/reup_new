<x-guest-layout>
    @section('title', 'Enter your code')

    <div class="card"
         x-data="{
            code: '',
            submitting: false,
            get complete() { return this.code.replace(/\D/g, '').length === 6; }
         }">
        <div class="card-content sm:p-7">
            <div class="mb-6">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-1 text-xs font-medium text-muted-foreground">
                    <x-icon name="envelope" class="h-3.5 w-3.5" />
                    Step 2 of 2
                </span>
                <h1 class="mt-3 text-2xl font-semibold tracking-tight">Enter your code</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">
                    We sent a 6-digit code to
                    <span class="font-medium text-foreground">{{ $maskedEmail }}</span>.
                </p>
            </div>

            @if(session('status'))
                <div class="mb-5 flex items-start gap-2.5 rounded-lg border border-brand-200 bg-accent px-3.5 py-3">
                    <x-icon name="information-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                    <p class="text-sm text-accent-foreground">{{ session('status') }}</p>
                </div>
            @endif

            {{-- `data-no-loading`: this form renders its own "Verifying…" state
                 from the Alpine flag below, so the global button lock would only
                 duplicate it. --}}
            <form method="POST" action="{{ route('login.code.verify.post') }}" class="space-y-5"
                  data-no-loading
                  @submit="submitting = true">
                @csrf

                <div>
                    <label for="code" class="label">6-digit code</label>
                    <input
                        id="code"
                        name="code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        required
                        autofocus
                        x-model="code"
                        placeholder="000000"
                        aria-describedby="code-help"
                        class="input mt-1.5 text-center font-mono text-2xl tracking-[0.4em] @error('code') input-error @enderror">
                    <p id="code-help" class="mt-1.5 text-xs text-muted-foreground">
                        Paste or type the code exactly as it appears in the email.
                    </p>
                    @error('code')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Alpine owns the submitting state, but the button is never
                     disabled by it: if the bundle fails to load, a disabled
                     submit button would make the form unusable. The server
                     re-validates the code length regardless. --}}
                <button type="submit" class="btn btn-primary w-full">
                    <span x-show="!submitting">
                        Sign in
                    </span>
                    <span x-show="submitting" x-cloak>
                        Verifying&hellip;
                    </span>
                    <x-icon name="arrow-right" class="h-4 w-4" x-show="!submitting" />
                </button>
            </form>

            <div class="mt-6 flex items-center justify-between gap-3 border-t border-border pt-5">
                <form method="POST" action="{{ route('login.code.resend') }}">
                    @csrf
                    <button type="submit"
                            class="btn btn-ghost btn-sm"
                            @if($resendIn > 0) disabled @endif>
                        <x-icon name="arrow-path" class="h-4 w-4" />
                        @if($resendIn > 0)
                            Resend in {{ $resendIn }}s
                        @else
                            Resend code
                        @endif
                    </button>
                </form>

                <a href="{{ route('login.code') }}" class="link text-sm font-medium">
                    Use a different email
                </a>
            </div>
        </div>
    </div>

    <div class="mt-6 flex items-start gap-2.5 rounded-lg border border-border bg-surface px-4 py-3">
        <x-icon name="shield-check" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
        <p class="text-xs text-muted-foreground">
            ReUp will never ask you to read a code aloud or send it to anyone. If a code
            arrives that you did not request, ignore it &mdash; it only works for the account
            it was sent to.
        </p>
    </div>
</x-guest-layout>

{{--
    The transaction-PIN prompt: a banner on the dashboard, and a modal that asks for
    the PIN itself.

    ## Why a banner *and* a modal
    ##
    ## The banner is the standing reminder and the modal is the ask. The banner alone
    ## is a line of text a customer scrolls past; the modal alone disappears the
    ## moment it is dismissed, and a customer who is not ready to set a PIN then has
    ## no way back to it except through Settings. Together, the modal makes the point
    ## once and the banner keeps it reachable for as long as it is true — which is
    ## exactly as long as the prompt should exist, because it goes away by itself
    ## when the PIN is set.
    ##
    ## The modal reuses the profile form's contract exactly
    ##
    ## It posts to `profile.pin` with the same fields the profile page sends, so
    ## there is one PIN-setting path in the application rather than two that can
    ## disagree. That matters more here than usual: this endpoint is the one that
    ## installs the credential which authorises spending, and it requires a one-time
    ## code emailed to the account owner precisely so that a hijacked session cannot
    ## install a PIN on its own. A dashboard modal that skipped the code to be
    ## convenient would be a security regression dressed as an improvement.
    ##
    ## It is therefore a two-step form, and the copy says so up front so the code
    ## arriving by email is expected rather than a surprise.
--}}
@php
    $pinBanner = $prompts['pin'] ?? [];
@endphp

<div x-data="pinPrompt()"
     data-pin-prompt
     data-code-url="{{ route('profile.pin.code', [], false) }}"
     x-cloak>

    {{-- ===================== The standing banner ===================== --}}
    <div class="card mb-8 border-amber-300 bg-amber-50">
        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:gap-4 sm:p-5">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                <x-icon name="key" variant="solid" class="h-5 w-5" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-amber-900">
                    {{ $pinBanner['title'] ?? 'Set your transaction PIN' }}
                </p>
                <p class="mt-1 text-sm text-amber-900/90">
                    {{ $pinBanner['body'] ?? '' }}
                </p>
            </div>

            <div class="flex w-full shrink-0 flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                <button type="button"
                        class="btn btn-primary w-full sm:w-auto"
                        @click="open()">
                    Set my PIN now
                </button>

                {{-- The path for a customer whose JavaScript did not load: the same
                     form, rendered as a normal page. Without this the prompt would be
                     a banner with a button that does nothing, which is worse than no
                     prompt. --}}
                <a href="{{ route('profile.index', [], false) }}#security"
                   class="link w-full text-center text-xs font-medium sm:w-auto">
                    or open it on my profile
                </a>
            </div>
        </div>
    </div>

    {{-- ===================== The modal ===================== --}}
    <div x-show="show"
         x-effect="document.body.classList.toggle('overflow-hidden', show)"
         @keydown.escape.window="show && dismiss()"
         class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
         role="dialog"
         aria-modal="true"
         aria-labelledby="pinPromptTitle">
        <div class="absolute inset-0 bg-ink-900/60" @click="dismiss()" aria-hidden="true"></div>

        <div x-ref="panel"
             tabindex="-1"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-3 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             class="relative w-full max-w-lg rounded-2xl border border-border bg-surface shadow-overlay">
            <div class="max-h-[85vh] overflow-y-auto p-5 sm:p-7">
                <div class="flex items-start gap-4">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                        <x-icon name="lock-closed" variant="solid" class="h-6 w-6" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="pinPromptTitle" class="text-lg font-semibold">Set your transaction PIN</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            This 4-digit PIN authorises every purchase you make on ReUp, so it is the one
                            thing standing between your wallet and anyone who gets into your account.
                        </p>
                    </div>
                </div>

                @if($errors->has('pin') || $errors->has('pin_code') || $errors->has('current_pin'))
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3">
                        @foreach(['pin', 'pin_code', 'current_pin'] as $field)
                            @error($field)
                                <p class="text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('profile.pin', [], false) }}" class="mt-5 space-y-4">
                    @csrf

                    {{-- Step 1: the emailed code. Asked for first because the form
                         cannot be submitted without it, and a customer who fills in
                         a PIN before discovering they need a code has wasted the
                         effort. --}}
                    <div class="rounded-xl border border-border bg-ink-50 p-4">
                        <label for="pin_prompt_code" class="label">Step 1 — the code we email you</label>
                        <p class="mt-1 text-xs text-muted-foreground">
                            We send a 6-digit code to
                            <span class="font-medium break-all text-ink-700">{{ $user->email }}</span>
                            to prove it is you.
                        </p>

                        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                            <input type="text"
                                   id="pin_prompt_code"
                                   name="pin_code"
                                   class="input flex-1"
                                   inputmode="numeric"
                                   autocomplete="one-time-code"
                                   maxlength="6"
                                   pattern="[0-9]{6}"
                                   placeholder="6-digit code"
                                   required>
                            <button type="button"
                                    class="btn btn-outline shrink-0"
                                    :disabled="sending || cooldown > 0"
                                    @click="sendCode()">
                                <span x-show="!sending && cooldown === 0">Email me a code</span>
                                <span x-show="sending" x-cloak>Sending&hellip;</span>
                                <span x-show="!sending && cooldown > 0" x-cloak x-text="'Resend in ' + cooldown + 's'"></span>
                            </button>
                        </div>

                        <p class="mt-2 text-xs text-brand-700" x-show="sent" x-cloak>
                            <span class="inline-flex items-center gap-1">
                                <x-icon name="check-circle" class="h-3.5 w-3.5" />
                                Code sent. Check your inbox, and your spam folder if it is not there.
                            </span>
                        </p>
                        <p class="mt-2 text-xs text-red-700" x-show="error" x-cloak x-text="error"></p>
                    </div>

                    {{-- Step 2: the PIN itself. --}}
                    <div>
                        <label for="pin_prompt_pin" class="label">Step 2 — choose your 4-digit PIN</label>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Avoid 1234, 0000, your birth year, or anything somebody who knows you would guess.
                        </p>
                        <div class="mt-2 grid grid-cols-2 gap-3">
                            <input type="password"
                                   id="pin_prompt_pin"
                                   name="pin"
                                   class="input text-center text-lg tracking-[0.5em]"
                                   inputmode="numeric"
                                   autocomplete="new-password"
                                   maxlength="4"
                                   pattern="[0-9]{4}"
                                   placeholder="••••"
                                   required>
                            <input type="password"
                                   name="pin_confirmation"
                                   class="input text-center text-lg tracking-[0.5em]"
                                   inputmode="numeric"
                                   autocomplete="new-password"
                                   maxlength="4"
                                   pattern="[0-9]{4}"
                                   placeholder="••••"
                                   aria-label="Confirm your PIN"
                                   required>
                        </div>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Nobody at ReUp can see your PIN, and we will never ask you for it by email,
                        phone or chat.
                    </p>

                    <div class="flex flex-col gap-2 pt-1 sm:flex-row-reverse">
                        <button type="submit" class="btn btn-primary w-full sm:w-auto">
                            Save my PIN
                        </button>
                        <button type="button"
                                class="btn btn-outline w-full sm:w-auto"
                                @click="dismiss()">
                            I will do it later
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

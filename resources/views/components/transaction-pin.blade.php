{{--
    Transaction PIN entry.

    Required when the purchase is paid from the wallet balance: the PIN is verified
    against a bcrypt hash server-side before the debit, and this component only
    collects it.

    ## Not required when the card rail is chosen

    `<x-payment-method>` announces the chosen rail with a `payment-method` event. When
    that rail is the gateway, the PIN is both `required`-less in the markup and
    `disabled` — because a disabled input is not submitted at all, which is what makes
    the server's `required_unless:payment_method,paystack` rule agree with what the
    browser allowed. Requiring a PIN *and* sending the customer to their bank would be
    two authorisations for one payment.

    The `required` attribute is bound rather than toggled so that a browser with
    JavaScript disabled keeps the original, safe behaviour: the wallet rail is the
    default, and its PIN is enforced exactly as before.

    `idempotency_key` accompanies it so a double submit — double click, refresh of a
    POST, flaky network retry — returns the original result instead of charging twice.
    It is generated per page load.
--}}
@props([
    'id' => 'pin',
    'label' => 'Transaction PIN',
    'model' => null,
])

@php
    $modelVar = $model ?: $id;
@endphp

<div class="space-y-1.5"
     x-data="{ cardRail: false }"
     @payment-method.window="cardRail = $event.detail.method === 'paystack'">
    <label for="{{ $id }}" class="label" x-text="cardRail ? '{{ $label }} (not needed)' : '{{ $label }}'">{{ $label }}</label>

    <div class="relative" x-data="{ show: false }">
        <input
            id="{{ $id }}"
            name="pin"
            :type="show ? 'text' : 'password'"
            inputmode="numeric"
            autocomplete="off"
            pattern="[0-9]{4}"
            maxlength="4"
            :required="! cardRail"
            :disabled="cardRail"
            @if($model) x-model="{{ $modelVar }}" @endif
            @input="$event.target.value = $event.target.value.replace(/\D/g, '').slice(0, 4)"
            placeholder="4 digits"
            class="input pr-11 tracking-[0.5em] disabled:cursor-not-allowed disabled:opacity-50 @error('pin') input-error @enderror">

        <button type="button"
                @click="show = !show"
                x-show="! cardRail"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-400 hover:text-ink-700"
                :aria-label="show ? 'Hide PIN' : 'Show PIN'">
            <x-icon name="eye" class="h-4 w-4" x-show="!show" />
            <x-icon name="eye-slash" class="h-4 w-4" x-show="show" x-cloak />
        </button>
    </div>

    @error('pin')
        <p class="field-error">{{ $message }}</p>
    @else
        <p class="text-xs text-muted-foreground" x-show="! cardRail">
            Set or change your PIN in <a href="{{ route('profile.index') }}" class="link">profile settings</a>.
        </p>
        <p class="text-xs text-muted-foreground" x-show="cardRail" x-cloak>
            No PIN needed &mdash; you authorise this payment on the secure payment page.
        </p>
    @enderror
</div>

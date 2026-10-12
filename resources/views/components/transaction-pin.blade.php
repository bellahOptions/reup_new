{{--
    Transaction PIN entry.

    Required when the purchase is paid from the wallet balance: the PIN is verified
    against a bcrypt hash server-side before the debit, and this component only
    collects it.

    ## Not required when the card rail is chosen

    The rail is read from the `payment_method` control the form already carries, so
    this component and `<x-payment-method>` cannot disagree about which rail is
    selected. When it is the gateway the PIN is `disabled`, because a disabled input is
    not submitted at all — which is what makes the server's
    `required_unless:payment_method,paystack` rule agree with what the browser
    permitted. Requiring a PIN *and* sending the customer to their bank would be two
    authorisations for one payment.

    ## `required` is in the markup, and only *removed* by Alpine

    It was `:required="! cardRail"` for a while, which looks equivalent and is not: a
    bound attribute is absent from the served HTML, so with JavaScript disabled or
    still loading the field was simply not required, and a wallet submission skipped the
    browser's own validation. Static `required` plus a `x-bind` that removes it means
    the *safe* case is the one that does not depend on JavaScript having run, and the
    relaxation is the thing that has to be earned.

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
     x-init="
        const rail = $el.closest('form')?.querySelector('[name=payment_method]');
        cardRail = rail?.value === 'paystack';
        rail?.addEventListener('change', () => { cardRail = rail.value === 'paystack'; });
     ">
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
            required
            x-bind:required="! cardRail"
            x-bind:disabled="cardRail"
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

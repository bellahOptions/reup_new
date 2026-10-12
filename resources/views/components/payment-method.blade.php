{{--
    How the customer wants to pay: their wallet balance, or their card / bank
    transfer through the gateway.

    ## Why the choice exists

    A customer with an empty wallet should not have to fund it first in order to buy
    ₦500 of airtime. Paying by card is one step instead of two, and the price is
    identical either way — ReUp's margin comes out of the provider's discount, not out
    of the customer, so there is nothing to choose between the two rails on cost.

    ## What changes when "card" is chosen

      * the transaction PIN is not required. The gateway authorises the payment, and a
        four-digit code *as well* would be asking them to authorise the same payment
        twice — once with their PIN and once with their bank;
      * the customer is taken to the gateway for the exact amount shown, and
        **nothing is vended until that payment settles**. A customer who closes the tab
        is charged nothing and receives nothing.

    ## A real `<select>`, and three earlier attempts that were wrong

    The rail is one form control with a value, so it is a `<select>`. Three previous
    shapes each broke something, and all three failures were invisible to the tests
    that only posted to the endpoint:

      * **`<input type="hidden" :value="method" value="wallet">`** — a static `value`
        *and* a bound one. The static attribute wins in the DOM, so the form always
        submitted `wallet`: the card rail was unselectable, and choosing it left the
        PIN disabled and the submit rejected. A silent, total failure of the feature;
      * **radios with `x-model` across two scopes** — not a binding at all, so the
        flag never reached the PIN field outside the component;
      * **radios driving a hidden input via `$dispatch`** — worked, but needed a
        listener outside the component and left the no-JavaScript path depending on
        which radio the browser happened to submit.

    A `<select>` has none of those problems: it is one control, its value is simply
    submitted, and `selected` marks the default in plain HTML so a browser without
    JavaScript submits `wallet` exactly as before. The `x-model` is a convenience for
    anything that wants to read the choice; the form itself does not depend on it.
--}}
@props([
    'wallet' => null,
    'name' => 'payment_method',
])

@php
    $rails = [
        'wallet' => [
            'label' => 'My wallet balance',
            'hint' => 'Balance: ₦' . number_format((float) ($wallet ?? 0), 2),
        ],
        'paystack' => [
            'label' => 'Card or bank transfer',
            'hint' => 'Pay the exact amount — no PIN needed',
        ],
    ];
@endphp

{{--
    No `@error` block, deliberately.

    There is no validation message this field could ever carry: its rule is
    `nullable|in:wallet,paystack`, and a `<select>` with exactly two options cannot
    submit a third value. An error display that can never appear is dead markup — and
    it is worse than dead here, because `@error` reads the `$errors` view variable,
    which Laravel shares only with views rendered through the HTTP stack. Rendering
    this component in isolation (as several tests do) threw
    `Undefined variable $errors` on a line that could never have shown anything.

    The hint text carries the information instead, which is the part a customer
    actually needs: what each rail costs them and whether a PIN is involved.
--}}
<div class="space-y-2" x-data="{ rail: @js(old($name, 'wallet')) }">
    <label for="{{ $name }}-rail" class="label">Pay with</label>

    <select id="{{ $name }}-rail" name="{{ $name }}" class="select" x-model="rail">
        @foreach($rails as $value => $rail)
            <option value="{{ $value }}" @selected(old($name, 'wallet') === $value)>
                {{ $rail['label'] }} — {{ $rail['hint'] }}
            </option>
        @endforeach
    </select>
</div>

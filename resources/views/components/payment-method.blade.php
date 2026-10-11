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

    ## Why it announces itself

    The PIN field and the submit button are outside this component, so the choice has
    to reach them. Two earlier attempts were wrong in ways worth recording:

      * putting `x-data` on a wrapper here scopes the flag to this element, so the PIN
        input outside it stays `required` and silently blocks the submit — the customer
        is told no PIN is needed and then cannot proceed;
      * using `x-model` across two Alpine scopes is not a binding at all, so the same
        thing happens with no error anywhere.

    The radios therefore write the value into a **hidden input** — which is also what
    makes the form work with JavaScript disabled, because that input is then simply
    absent and the server defaults to the wallet — and dispatch `payment-method`
    upward for the PIN field and the button to listen to. One value, announced once.
--}}
@props([
    'wallet' => null,
])

<div class="space-y-3"
     x-data="{
        method: 'wallet',
        choose(value) {
            this.method = value;
            this.$dispatch('payment-method', { method: value });
        },
     }">
    <span class="label">Pay with</span>

    <input type="hidden" name="payment_method" :value="method" value="wallet">

    <div class="grid grid-cols-2 gap-3">
        <label class="cursor-pointer">
            <input type="radio" name="payment_rail" value="wallet" class="sr-only"
                   :checked="method === 'wallet'" @change="choose('wallet')">
            <span class="flex flex-col gap-1 rounded-xl border border-border bg-surface p-3.5 transition-colors"
                  :class="method === 'wallet' ? 'border-brand-400 bg-accent' : 'hover:border-brand-300'">
                <span class="flex items-center gap-2 text-sm font-semibold">
                    <x-icon name="wallet" class="h-4 w-4" />
                    Wallet
                </span>
                @if($wallet !== null)
                    <span class="text-xs text-muted-foreground tabular-nums">
                        Balance: &#8358;{{ number_format((float) $wallet, 2) }}
                    </span>
                @endif
            </span>
        </label>

        <label class="cursor-pointer">
            <input type="radio" name="payment_rail" value="paystack" class="sr-only"
                   :checked="method === 'paystack'" @change="choose('paystack')">
            <span class="flex flex-col gap-1 rounded-xl border border-border bg-surface p-3.5 transition-colors"
                  :class="method === 'paystack' ? 'border-brand-400 bg-accent' : 'hover:border-brand-300'">
                <span class="flex items-center gap-2 text-sm font-semibold">
                    <x-icon name="credit-card" class="h-4 w-4" />
                    Card or transfer
                </span>
                <span class="text-xs text-muted-foreground">Pay the exact amount</span>
            </span>
        </label>
    </div>

    {{--
        Says what happens next. "Card or transfer" on a page that usually spends your
        balance is exactly the kind of choice a customer hesitates over, and a
        hesitation at the payment step is a lost sale.
    --}}
    <p class="text-xs text-muted-foreground" x-show="method === 'paystack'" x-cloak>
        You will be taken to a secure page to pay the amount shown. Your order is sent
        the moment the payment is confirmed &mdash; you are not charged until then.
    </p>

    <p class="text-xs text-muted-foreground" x-show="method === 'wallet'">
        Paid from your wallet balance, authorised with your transaction PIN.
    </p>
</div>

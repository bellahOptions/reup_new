<?php

return [
    'minimum_funding' => env('WALLET_MIN_FUNDING', 100),
    'maximum_funding' => env('WALLET_MAX_FUNDING', 1000000),
    
    'bank' => [
        'account_name' => env('BANK_ACCOUNT_NAME', 'bellahoptions/reup'),
        'account_number' => env('BANK_ACCOUNT_NUMBER', '9740162844'),
        'bank_name' => env('BANK_NAME', 'Paystack-Titan'),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Funding service fee
    |--------------------------------------------------------------------------
    | ADDED TO THE WALLET, NOT TO THE CHECKOUT.
    |
    | The customer pays the amount they chose, exactly, and is credited that
    | amount. This fee is then taken from the wallet as a separate, recorded
    | debit — so the *net* increase is the amount minus the fee, and the
    | customer never sees a total larger than the figure they typed.
    |
    | It replaces `paystack` (1.5% + ₦20) and `bank_transfer`, which were added
    | to the checkout total. Those keys are gone rather than set to zero: a
    | leftover percentage is an invitation to add it back to a total.
    |
    | `percentage` is taken of the funded amount; `additional` is flat; `cap`
    | limits their sum (null means uncapped). Both default to zero, so the
    | shipped behaviour is "no fee" until a business decision is made — and the
    | mechanism is already in place, tested, when one is.
    |
    | Paystack's own charge is deliberately NOT modelled here. It is a cost of
    | taking the payment and is deducted by the gateway at settlement; passing it
    | to the customer is a separate commercial decision, not a default. See
    | "Gateway charges are not customer fees" in docs/RUNNING.md.
    */
    'funding_fee' => [
        'percentage' => (float) env('WALLET_FUNDING_FEE_PERCENT', 0),
        'additional' => (float) env('WALLET_FUNDING_FEE_FLAT', 0),
        'cap' => env('WALLET_FUNDING_FEE_CAP') !== null
            ? (float) env('WALLET_FUNDING_FEE_CAP')
            : null,
    ],
];
<?php

return [
    'minimum_funding' => env('WALLET_MIN_FUNDING', 100),
    'maximum_funding' => env('WALLET_MAX_FUNDING', 1000000),
    
    'bank' => [
        'account_name' => env('BANK_ACCOUNT_NAME', 'bellahoptions/reup'),
        'account_number' => env('BANK_ACCOUNT_NUMBER', '9740162844'),
        'bank_name' => env('BANK_NAME', 'Paystack-Titan'),
    ],
    
    'fees' => [
        'paystack' => [
            'percentage' => 1.5,
            'additional' => 20,
        ],
        'bank_transfer' => [
            'fixed' => 0,
        ],
    ],
];
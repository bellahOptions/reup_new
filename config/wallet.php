<?php

return [
    'minimum_funding' => env('WALLET_MIN_FUNDING', 100),
    'maximum_funding' => env('WALLET_MAX_FUNDING', 1000000),
    
    'bank' => [
        'account_name' => env('BANK_ACCOUNT_NAME', 'Bellah Options'),
        'account_number' => env('BANK_ACCOUNT_NUMBER', '1627251518'),
        'bank_name' => env('BANK_NAME', 'Access Bank'),
    ],
    
    'fees' => [
        'paystack' => [
            'percentage' => 1.5,
            'additional' => 50,
        ],
        'bank_transfer' => [
            'fixed' => 0,
        ],
    ],
];
<?php

/**
 * The approved customer-facing copy, as a single reviewable baseline.
 *
 * ## Why the copy lives in configuration rather than in the views
 *
 * Two reasons, and the second is the one that matters.
 *
 * The obvious reason is reviewability: an approved copy deck is a document, and a
 * document that has been copied into forty Blade templates cannot be reviewed
 * against its source. Here, one diff shows every change to customer-visible
 * language.
 *
 * The important reason is the rule in §51.42 — the frontend must not hardcode
 * anything dynamic. Copy and *values* have to be separable for that to hold. A view
 * that reads `copy.checkout.heading` and a `$total` from the pricing engine cannot
 * accidentally substitute a hardcoded price for a real one, because the two never
 * appear in the same place. A view that contains the sentence "Confirm your ₦500
 * payment" has already broken the rule.
 *
 * ## What must never appear here
 *
 * No price, no provider name, no commission, no profit figure, no product
 * catalogue, no availability. Those come from the database through
 * `App\Catalogue\ServiceCatalogue` and the pricing engine. A number in this file
 * would be a number the frontend had hardcoded, which is the failure §51.42 exists
 * to prevent.
 *
 * ## Tone (§51)
 *
 * Modern, clear, confident, simple, consumer-focused, Nigerian but internationally
 * understandable. Short enough for mobile. No jargon, no exaggerated claims.
 */
return [

    /* ---------------------------------------------------------------------
     | Shared vocabulary and states
     |------------------------------------------------------------------- */
    'brand' => [
        'tagline' => 'One wallet for everything digital.',
        'supporting' => 'Pay bills, buy data, get gift cards, top up international numbers, '
            . 'activate eSIMs and grow your social presence — all from one simple ReUp wallet.',
        'promise' => 'Fund once. Pay for anything.',
        'statements' => [
            'One wallet. More possibilities.',
            'Everything in one ReUp wallet.',
            'Built for everyday payments.',
            'Fund once. Pay for anything.',
            'Simple payments. More possibilities.',
            'Your everyday digital payments, all in one place.',
        ],
    ],

    'actions' => [
        'get_started' => 'Get Started',
        'sign_in' => 'Sign In',
        'sign_out' => 'Sign Out',
        'continue' => 'Continue',
        'cancel' => 'Cancel',
        'done' => 'Done',
        'try_again' => 'Try Again',
        'go_home' => 'Go Home',
        'view_all' => 'View All',
        'view_transaction' => 'View Transaction',
        'pay_again' => 'Pay Again',
        'view_receipt' => 'View Receipt',
        'download_receipt' => 'Download Receipt',
        'share_receipt' => 'Share Receipt',
        'confirm_and_pay' => 'Confirm & Pay',
        'fund_wallet' => 'Fund Wallet',
        'save_a_bill' => 'Save a Bill',
        'save_first_bill' => 'Save Your First Bill',
        'set_a_reminder' => 'Set a Reminder',
        'view_reminders' => 'View Reminders',
        'browse_gift_cards' => 'Browse Gift Cards',
        'explore_social_boost' => 'Explore Social Boost',
        'contact_support' => 'Contact Support',
        'invite_friends' => 'Invite Friends',
        'review_price' => 'Review Price',
    ],

    'home' => [
        'headline' => 'One wallet for everything digital.',
    ],

    /* ---------------------------------------------------------------------
     | Dashboard (§51.8)
     |------------------------------------------------------------------- */
    'dashboard' => [
        'statement' => 'What would you like to pay for today?',
        'greetings' => [
            'morning' => 'Good morning',
            'afternoon' => 'Good afternoon',
            'evening' => 'Good evening',
        ],
        'sections' => [
            'pay_bills' => 'Pay Bills',
            'digital' => 'Digital',
            'social' => 'Social',
            'my_reup' => 'My ReUp',
        ],
        'my_reup_description' => 'Your payments, saved bills and reminders.',
        'recent_payments' => 'Recent Payments',
        'recent_payments_empty' => 'Your recent payments will appear here.',
        'saved_bills' => 'Saved Bills',
        'upcoming_reminders' => 'Upcoming Reminders',
    ],

    /* ---------------------------------------------------------------------
     | Wallet (§51.6)
     |------------------------------------------------------------------- */
    'wallet' => [
        'heading' => 'Your ReUp Wallet',
        'description' => 'Fund your wallet once and use it across your favourite ReUp services.',
        'available_balance' => 'Available Balance',
        'total_spent' => 'Total Spent',
        'recent_transactions' => 'Recent Transactions',
        'insufficient' => 'Your wallet balance isn\'t enough for this payment.',
        'insufficient_supporting' => 'Fund your wallet to continue.',
        'fund_heading' => 'Fund Your Wallet',
        'fund_description' => 'Add money to your ReUp wallet and use it across all supported services.',
        'fund_success' => 'Wallet Funded Successfully',
        'continue_to_payment' => 'Continue to Payment',
    ],

    /* ---------------------------------------------------------------------
     | Saved bills, Pay Again, reminders (§51.3 – §51.5)
     |------------------------------------------------------------------- */
    'saved_bills' => [
        'heading' => 'Your bills. Saved.',
        'description' => 'Save your favourite bill accounts and pay them again without entering '
            . 'the details every time.',
        'empty' => 'No saved bills yet.',
        'empty_supporting' => 'Save a bill account to make your next payment faster.',
    ],

    'pay_again' => [
        'heading' => 'Pay again in seconds.',
        'description' => 'Your recent payments are always within reach. Repeat a payment without '
            . 'starting from scratch.',
        'empty' => 'You haven\'t made any payments yet.',
    ],

    'reminders' => [
        'heading' => 'Never forget a bill again.',
        'description' => 'Set reminders for recurring bills and let ReUp remind you when it\'s time to pay.',
        'empty' => 'No reminders set.',
        'empty_supporting' => 'Add a reminder for your next recurring bill.',
    ],

    /* ---------------------------------------------------------------------
     | Transaction outcomes (§51.20 – §51.23)
     |
     | The single most important copy in this file. A transaction whose provider
     | outcome is UNKNOWN must never be described as failed — see UiCopy, which
     | resolves these labels and enforces that.
     |------------------------------------------------------------------- */
    'outcome' => [
        'success' => [
            'heading' => 'Payment Successful',
            'description' => 'Your payment has been completed successfully.',
        ],
        'processing' => [
            'heading' => 'Payment Processing',
            'description' => 'We\'re confirming your payment with the service provider. '
                . 'Please don\'t make another payment yet.',
        ],
        'reconciling' => [
            'description' => 'We\'re still confirming the status of this transaction. '
                . 'You don\'t need to pay again while we check.',
        ],
        'failed' => [
            'heading' => 'Payment Failed',
            'description' => 'We couldn\'t complete this payment.',
            'refunded' => 'Your wallet has been refunded.',
            'refund_pending' => 'If your wallet was charged, the amount will be reversed after '
                . 'the transaction is confirmed.',
        ],
        'refund' => [
            'heading' => 'Refund Processed',
            'description' => 'Your refund has been added back to your ReUp wallet.',
        ],
        'cancelled' => [
            'heading' => 'Payment Cancelled',
            'description' => 'This payment was cancelled and you have not been charged.',
        ],

        /*
         * Per-service success headings, from §51.20. Used where the service is known,
         * so the customer reads what actually happened rather than a generic
         * "Payment Successful".
         */
        'service_success' => [
            'airtime' => 'Airtime Sent Successfully',
            'data' => 'Data Activated Successfully',
            'electricity' => 'Electricity Payment Successful',
            'cable_tv' => 'Cable TV Payment Successful',
            'internet' => 'Internet Payment Successful',
            'education' => 'Education Payment Successful',
            'epin' => 'Your ePIN is ready.',
            'giftcard' => 'Gift Card Purchased Successfully',
            'esim' => 'eSIM Purchased Successfully',
            'international' => 'International top-up successful.',
            'smm' => 'Your social boost order has been received.',
            'funding' => 'Wallet Funded Successfully',
            'refund' => 'Refund Processed',
        ],

        /* The same, for the in-flight states. §51.9 – §51.19. */
        'service_processing' => [
            'airtime' => 'Your airtime purchase is being processed.',
            'data' => 'Your data purchase is being processed.',
            'internet' => 'Your internet payment is being processed.',
            'giftcard' => 'Your gift card purchase is being processed.',
            'esim' => 'Your eSIM purchase is being processed.',
            'international' => 'Your international top-up is being processed.',
            'smm' => 'Your social boost order has been received.',
            'funding' => 'Your wallet funding is being processed.',
        ],

        'service_failed' => [
            'airtime' => 'We couldn\'t complete your airtime purchase. Your wallet will only be '
                . 'charged if the transaction is successful.',
        ],
    ],

    /* ---------------------------------------------------------------------
     | Transaction history, receipts, checkout (§51.25, §51.26, §51.39, §51.40)
     |------------------------------------------------------------------- */
    'transactions' => [
        'heading' => 'Transactions',
        'empty' => 'No transactions found.',
        'filters' => [
            'all' => 'All',
            'successful' => 'Successful',
            'processing' => 'Processing',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
        ],
        'fields' => [
            'service' => 'Service',
            'amount' => 'Amount',
            'status' => 'Status',
            'date' => 'Date',
            'reference' => 'Reference',
        ],
    ],

    'receipt' => [
        'heading' => 'Payment Receipt',
    ],

    'checkout' => [
        'heading' => 'Review Payment',
        'reminder' => 'Make sure the details above are correct before confirming your payment.',
        'fields' => [
            'service' => 'Service',
            'recipient' => 'Recipient',
            'product' => 'Product',
            'quantity' => 'Quantity',
            /* Customer-facing price labels only (§51.37). */
            'amount' => 'Amount',
            'fees' => 'Fees',
            'discount' => 'Discount',
            'total' => 'Total',
        ],
        'confirm_heading' => 'Confirm Payment',
        'confirm_body' => 'You are about to pay ₦{amount} for {service}.',
    ],

    /* Price change (§51.38). Never silently charge a different amount. */
    'price_change' => [
        'notice' => 'The price for this service has changed.',
        'supporting' => 'Please review the updated price before continuing.',
    ],

    /* ---------------------------------------------------------------------
     | Service discovery, search, availability (§51.32, §51.36)
     |------------------------------------------------------------------- */
    'search' => [
        'placeholder' => 'What are you looking for?',
        'hint' => 'Search services, bills, gift cards...',
        'empty' => 'We couldn\'t find what you\'re looking for.',
        'empty_supporting' => 'Try another search or browse the available services.',
    ],

    'availability' => [
        'heading' => 'Service Temporarily Unavailable',
        'description' => 'This service is currently unavailable. Please try again later.',
        /*
         * Shown when a product is withheld because its provider cost no longer
         * supports the configured pricing. It says nothing about cost or margin —
         * it tells the customer when the service comes back and why, and nothing
         * about ReUp's economics.
         */
        'pricing' => 'This service is temporarily unavailable while we update its pricing.',
        /* Shown when a service has not been published yet. */
        'unpublished' => 'This service isn\'t available yet.',
    ],

    /* ---------------------------------------------------------------------
     | Errors (§51.35)
     |------------------------------------------------------------------- */
    'errors' => [
        'generic' => 'Something went wrong.',
        'generic_supporting' => 'We couldn\'t complete that request. Please try again.',
        'network' => 'We couldn\'t connect to ReUp.',
        'network_supporting' => 'Check your internet connection and try again.',
        '404' => 'We couldn\'t find that page.',
        '403' => 'You don\'t have permission to access this page.',
        '401' => 'Please sign in to continue.',
        '500' => 'Something went wrong on our end.',
        '500_supporting' => 'Our team has been notified. Please try again shortly.',
    ],

    /* ---------------------------------------------------------------------
     | Auth, profile, security (§51.27, §51.28, §51.33, §51.34)
     |------------------------------------------------------------------- */
    'auth' => [
        'login_heading' => 'Welcome back.',
        'login_description' => 'Sign in to continue using ReUp.',
        'forgot_password' => 'Forgot Password?',
        'create_account' => 'Create an Account',
        'register_heading' => 'Create your ReUp account.',
        'register_description' => 'One account. One wallet. More ways to pay.',
        'register_cta' => 'Create Account',
        'verify_email' => 'Check your email to verify your account.',
    ],

    'profile' => [
        'heading' => 'My ReUp',
        'description' => 'Manage your account, payments and ReUp preferences.',
    ],

    'security' => [
        'heading' => 'Keep Your Account Secure',
        'description' => 'Protect your ReUp account with strong authentication and security controls.',
        'pin_description' => 'Your transaction PIN helps protect payments made from your wallet.',
        /* Shown wherever a PIN would otherwise be displayed. See §51.15 and §51.28. */
        'pin_never_shown' => 'For your security we never show your PIN again. Reset it if you have forgotten it.',
    ],

    'support' => [
        'heading' => 'How can we help?',
        'categories' => [
            'Payment Issues',
            'Wallet & Funding',
            'Account & Security',
            'Airtime & Data',
            'Bills',
            'Gift Cards',
            'eSIM',
            'International Top-Up',
            'Social Boost',
            'Other',
        ],
    ],

    'notifications' => [
        'heading' => 'Notifications',
        'empty' => 'You\'re all caught up.',
    ],

    'referral' => [
        'heading' => 'Earn With ReUp',
        'description' => 'Invite people to ReUp and earn eligible rewards when they complete '
            . 'qualifying activities.',
    ],

    /* ---------------------------------------------------------------------
     | Sensitive delivery (§51.15, §51.16, §51.28)
     |------------------------------------------------------------------- */
    'delivery' => [
        'epin_ready' => 'Your ePIN is ready.',
        'gift_card_ready' => 'Your gift card is ready.',
        'esim_ready' => 'Your eSIM is ready.',
        'esim_instructions' => 'Follow the activation instructions to install your eSIM.',
        'review_gift_card' => 'Review Gift Card',
        'gift_card_success' => 'Gift Card Purchased',
        'reauthenticate' => 'Confirm your password to view this.',
    ],

    /* ---------------------------------------------------------------------
     | Maintenance (§51.36)
     |------------------------------------------------------------------- */
    'maintenance' => [
        'heading' => 'Service Temporarily Unavailable',
        'description' => 'This service is currently unavailable. Please try again later.',
    ],
];

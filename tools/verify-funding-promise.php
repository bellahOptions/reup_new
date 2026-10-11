<?php

/**
 * Verify the funding promise against the real database, and print what happens.
 *
 * A test asserts; this *shows*. It is for confirming the behaviour as an
 * operator would see it — the figures the customer is charged, credited and
 * charged back — rather than for regression protection.
 *
 * Usage: php tools/verify-funding-promise.php [amount]
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Services\PaystackService;
use App\Support\Money;

$amount = $argv[1] ?? '1000';

// ---------------------------------------------------------------
// The configured fee
// ---------------------------------------------------------------
$config = config('wallet.funding_fee');

echo "Configured funding fee: " . json_encode($config) . "\n";

$percent = (float) ($config['percentage'] ?? 0);
$flat = (float) ($config['additional'] ?? 0);

if ($percent === 0.0 && $flat === 0.0) {
    echo "  -> no customer fee is set, so the net increase equals the amount funded.\n";
    echo "     (Set WALLET_FUNDING_FEE_PERCENT / WALLET_FUNDING_FEE_FLAT to change that.)\n";
}

echo "\n";

// ---------------------------------------------------------------
// The scenario
// ---------------------------------------------------------------
$email = 'promise-check-' . bin2hex(random_bytes(4)) . '@example.test';

$user = User::create([
    'name' => 'Promise Check',
    'email' => $email,
    'phone' => '08000000000',
    'password' => bcrypt('password'),
    'email_verified_at' => now(),
]);

$money = Money::fromNaira($amount);
$fee = app(App\Services\WalletService::class)->fundingFeeFor($money);

$funding = Transactions::create([
    'user_id' => $user->id,
    'type' => 'credit',
    'service_type' => 'funding',
    'description' => 'Wallet funding — Card',
    'amount' => $money->toDecimalString(),
    // What the customer is charged, and what the gateway is asked for.
    'total_amount' => $money->toDecimalString(),
    'service_fee' => '0.00',
    'recipient' => $user->email,
    'provider' => 'paystack',
    'payment_method' => 'paystack',
    'payment_status' => 'pending',
    'status' => 'pending',
    'meta' => ['service_fee' => $fee->toDecimalString()],
]);

$before = (string) Wallet::where('user_id', $user->id)->value('balance');

echo "Customer funds            {$money->format()}\n";
echo "  checkout (charged)      " . Money::fromDatabase($funding->total_amount)->format() . "\n";
echo "  balance before          " . Money::fromDatabase($before)->format() . "\n";

// Settle exactly as the webhook would.
app(PaystackService::class)->settle($funding->fresh(), [
    'reference' => $funding->gatewayReference(),
    'status' => 'success',
    'amount' => Money::fromDatabase($funding->total_amount)->minor(),
    'currency' => 'NGN',
    'channel' => 'card',
    'gateway_response' => 'Successful',
]);

$after = (string) Wallet::where('user_id', $user->id)->value('balance');
$funding = $funding->fresh();

echo "  wallet credited         " . Money::fromDatabase($funding->amount)->format() . "\n";

$feeRow = Transactions::where('reference', App\Services\WalletService::fundingFeeReference((string) $funding->reference))->first();
$chargedFee = $feeRow ? Money::fromDatabase($feeRow->amount) : Money::fromNaira('0');

echo "  service fee taken       " . ($feeRow ? $chargedFee->format() : 'none (no fee configured)') . "\n";
echo "  balance after           " . Money::fromDatabase($after)->format() . "\n";
echo "  net increase            " . Money::fromDatabase($after)->minus(Money::fromDatabase($before))->format() . "\n";

echo "\nLedger:\n";
foreach (WalletLedger::where('user_id', $user->id)->orderBy('id')->get() as $entry) {
    printf(
        "  %-8s %-12s %s\n",
        $entry->entry_type,
        Money::fromDatabase($entry->amount)->format(),
        $entry->description
    );
}

$wallet = Wallet::where('user_id', $user->id)->first();
$verification = app(App\Services\WalletService::class)->verify($wallet);

echo "\nReconciles to ledger: " . ($verification['consistent'] ? 'yes' : 'NO') . "\n";
echo "total_funded (gross): " . Money::fromDatabase($wallet->total_funded)->format() . "\n";
echo "fee status on row:    " . ($funding->meta['funding_fee_status'] ?? '(none)') . "\n";

// ---------------------------------------------------------------
// Clean up: this is a check, not a fixture.
// ---------------------------------------------------------------
WalletLedger::where('user_id', $user->id)->delete();
Transactions::where('user_id', $user->id)->delete();
Wallet::where('user_id', $user->id)->delete();
$user->forceDelete();

echo "\n(probe data removed)\n";

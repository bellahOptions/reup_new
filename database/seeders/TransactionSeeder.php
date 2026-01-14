<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Transactions;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class TransactionSeeder extends Seeder
{
    public function run()
    {
        // Find or create the specific user
        // In TransactionSeeder.php, update the user creation:
$user = User::firstOrCreate([
    'email' => 'muyiwadavis65@gmail.com'
], [
    'name' => 'Ahmed Olumuyiwa Davis',
    'password' => Hash::make('12345678'), // Use bcrypt() instead of Hash::make()
    'wallet_balance' => 50000.00,
    'email_verified_at' => now(),
]);

        // Create realistic sequential transactions with proper balance calculations
        $this->createRealisticTransactions($user, 20); // Reduced to 20 for testing
    }

    private function createRealisticTransactions(User $user, $count = 20)
    {
        $currentBalance = $user->wallet_balance;
        $transactions = [];
        
        for ($i = 0; $i < $count; $i++) {
            $type = $this->getTransactionType($i);
            
            if ($type === 'credit') {
                // Funding transactions
                $amount = $this->getFundingAmount();
                $balanceBefore = $currentBalance;
                $currentBalance += $amount;
                
                // In your TransactionSeeder, update the transaction creation:
$transactionData = [
    'user_id' => $user->id,
    'type' => 'debit',
    'service_type' => 'airtime',
    'description' => 'Airtime purchase',
    'amount' => $amount,
    'service_fee' => $amount * 0.02, // Add service fee
    'total_amount' => $amount + ($amount * 0.02), // Add total amount
    'balance_before' => $balanceBefore,
    'balance_after' => $currentBalance,
    'recipient' => '08012345678',
    'provider' => 'MTN',
    'payment_method' => 'wallet',
    'payment_status' => 'success',
    'status' => 'success',
    'reference' => 'TXN-' . strtoupper(uniqid()),
    'meta' => json_encode([
        'phone_number' => '08012345678',
        'network' => 'MTN',
    ]),
    'created_at' => now()->subDays(rand(0, 30)),
    'updated_at' => now(),
];
            } else {
                // Purchase transactions
                $serviceType = $this->getServiceType();
                $amount = $this->getPurchaseAmount($serviceType);
                
                // Check if user has enough balance
                if ($currentBalance < $amount) {
                    // Add a funding transaction first
                    $fundingAmount = $amount * 2;
                    $balanceBefore = $currentBalance;
                    $currentBalance += $fundingAmount;
                    
                    $fundingTransaction = [
                        'user_id' => $user->id,
                        'type' => 'credit',
                        'service_type' => 'funding',
                        'description' => 'Wallet funding via Bank Transfer',
                        'reference' => 'FND-' . strtoupper(uniqid()),
                        'amount' => $fundingAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $currentBalance,
                        'status' => 'success',
                        'payment_method' => 'bank_transfer',
                        'payment_reference' => 'PAY-' . strtoupper(uniqid()),
                        'meta' => json_encode([ // JSON encode
                            'payment_channel' => 'Bank Transfer',
                            'narration' => 'Auto funding for purchase'
                        ]),
                        'created_at' => now()->subDays(rand(0, 30))->subHours(rand(0, 23))->subMinutes(rand(0, 59)),
                        'updated_at' => now(),
                    ];
                    
                    $transactions[] = $fundingTransaction;
                    $i++; // Increment counter since we added a transaction
                }
                
                $balanceBefore = $currentBalance;
                $currentBalance -= $amount;
                
                $recipient = $this->getRecipient($serviceType);
                $provider = $this->getProvider($serviceType);
                
                $transactionData = [
                    'user_id' => $user->id,
                    'type' => 'debit',
                    'service_type' => $serviceType,
                    'description' => $this->getPurchaseDescription($serviceType),
                    'reference' => strtoupper(substr($serviceType, 0, 2)) . '-' . strtoupper(uniqid()),
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $currentBalance,
                    'status' => 'success',
                    'recipient' => $recipient,
                    'provider' => $provider,
                    'meta' => json_encode($this->getMetaData($serviceType, $recipient, $provider, $amount)), // JSON encode
                    'created_at' => now()->subDays(rand(0, 30))->subHours(rand(0, 23))->subMinutes(rand(0, 59)),
                    'updated_at' => now(),
                ];
            }
            
            $transactions[] = $transactionData;
        }
        
        // Create transactions using the model's create method instead of bulk insert
        foreach ($transactions as $transaction) {
            Transactions::create($transaction);
        }
        
        // Update user's final balance
        $user->update(['wallet_balance' => $currentBalance]);
        
        $this->command->info("Created " . count($transactions) . " realistic transactions for user: {$user->email}");
        $this->command->info("Final wallet balance: ₦" . number_format($currentBalance, 2));
    }
    
    private function getTransactionType($index)
    {
        // More purchases than funding (70% purchases, 30% funding)
        return (rand(1, 10) <= 7) ? 'debit' : 'credit';
    }
    
    private function getServiceType()
    {
        $services = ['airtime', 'data', 'cable-tv', 'jamb'];
        $weights = [4, 3, 2, 1];
        
        $total = array_sum($weights);
        $rand = mt_rand(1, $total);
        $current = 0;
        
        for ($i = 0; $i < count($services); $i++) {
            $current += $weights[$i];
            if ($rand <= $current) {
                return $services[$i];
            }
        }
        
        return 'airtime';
    }
    
    private function getFundingAmount()
    {
        $amounts = [5000, 10000, 20000, 50000];
        return $amounts[array_rand($amounts)];
    }
    
    private function getPurchaseAmount($serviceType)
    {
        return match($serviceType) {
            'airtime' => rand(100, 5000),
            'data' => rand(500, 10000),
            'cable-tv' => rand(2500, 25000),
            'jamb' => rand(6200, 15700),
            default => rand(100, 5000)
        };
    }
    
    private function getFundingDescription()
    {
        $methods = [
            'Bank Transfer' => ['Access Bank', 'GTBank', 'Zenith Bank', 'First Bank'],
            'Card Payment' => ['Visa', 'Mastercard'],
            'USSD' => ['*966#', '*737#']
        ];
        
        $method = array_rand($methods);
        $provider = $methods[$method][array_rand($methods[$method])];
        
        return "Wallet funding via {$method} ({$provider})";
    }
    
    private function getPaymentMethod()
    {
        $methods = ['bank_transfer', 'card', 'ussd'];
        $weights = [5, 3, 2];
        
        $total = array_sum($weights);
        $rand = mt_rand(1, $total);
        $current = 0;
        
        for ($i = 0; $i < count($methods); $i++) {
            $current += $weights[$i];
            if ($rand <= $current) {
                return $methods[$i];
            }
        }
        
        return 'bank_transfer';
    }
    
    private function getPurchaseDescription($serviceType)
    {
        return match($serviceType) {
            'airtime' => "Airtime purchase",
            'data' => "Data bundle purchase",
            'cable-tv' => "Cable TV subscription",
            'jamb' => "JAMB e-PIN purchase",
            default => "Service purchase"
        };
    }
    
    private function getRecipient($serviceType)
    {
        $prefixes = ['080', '081', '070', '090', '091'];
        $prefix = $prefixes[array_rand($prefixes)];
        $number = rand(10000000, 99999999);
        
        return match($serviceType) {
            'airtime', 'data' => $prefix . $number,
            'cable-tv' => 'SC' . rand(1000000000, 9999999999),
            'jamb' => 'JAMB' . rand(100000, 999999),
            default => null
        };
    }
    
    private function getProvider($serviceType)
    {
        return match($serviceType) {
            'airtime', 'data' => ['MTN', 'Airtel', 'Glo', '9Mobile'][array_rand([0, 1, 2, 3])],
            'cable-tv' => ['DStv', 'GOtv', 'StarTimes'][array_rand([0, 1, 2])],
            'jamb' => 'JAMB',
            default => 'Service Provider'
        };
    }
    
    private function getMetaData($serviceType, $recipient, $provider, $amount)
    {
        $baseMeta = [
            'notes' => 'Transaction completed successfully',
            'api_response' => ['status' => 'success', 'message' => 'ORDER_COMPLETED'],
            'timestamp' => now()->toDateTimeString(),
        ];
        
        $additionalMeta = match($serviceType) {
            'airtime' => [
                'phone_number' => $recipient,
                'network' => $provider,
                'transaction_type' => 'airtime_recharge',
                'amount_charged' => $amount,
            ],
            'data' => [
                'phone_number' => $recipient,
                'network' => $provider,
                'data_plan' => $this->getDataPlan($amount),
                'validity' => $this->getValidity($amount) . ' days',
                'amount_charged' => $amount,
            ],
            'cable-tv' => [
                'smartcard_number' => $recipient,
                'bouquet' => $this->getBouquet($amount),
                'duration' => '30 days',
                'amount_charged' => $amount,
            ],
            'jamb' => [
                'profile_id' => 'JAMB' . rand(100000, 999999),
                'exam_type' => $amount > 10000 ? 'DE' : 'UTME',
                'pin_delivered' => true,
                'amount_charged' => $amount,
            ],
            default => []
        };
        
        return array_merge($baseMeta, $additionalMeta);
    }
    
    private function getDataPlan($amount)
    {
        if ($amount <= 1000) return '1GB';
        if ($amount <= 2000) return '2GB';
        if ($amount <= 3500) return '3GB';
        if ($amount <= 5000) return '5GB';
        return '10GB';
    }
    
    private function getValidity($amount)
    {
        if ($amount <= 1000) return 7;
        if ($amount <= 3000) return 14;
        return 30;
    }
    
    private function getBouquet($amount)
    {
        if ($amount <= 5000) return 'Yanga';
        if ($amount <= 12000) return 'Confam';
        if ($amount <= 20000) return 'Compact';
        return 'Premium';
    }
}
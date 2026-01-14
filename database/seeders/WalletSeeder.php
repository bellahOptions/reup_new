<?php


namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Wallet;

class WalletSeeder extends Seeder
{
    public function run(): void
    {
        // Get all users without wallets
        $usersWithoutWallets = User::doesntHave('wallet')->get();
        
        foreach ($usersWithoutWallets as $user) {
            Wallet::create([
                'user_id' => $user->id,
                'balance' => 0,
                'pending_balance' => 0,
                'total_funded' => 0,
                'total_spent' => 0,
                'transaction_count' => 0,
            ]);
            
            echo "Wallet created for user: {$user->name} (ID: {$user->id})\n";
        }
        
        echo "\nTotal wallets created: " . $usersWithoutWallets->count() . "\n";
    }
}
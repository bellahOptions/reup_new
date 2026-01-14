<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Create the specific user
        $user = User::updateOrCreate([
            'email' => 'muyiwadavis65@gmail.com'
        ], [
            'name' => 'Muyiwa Davis',
            'password' => Hash::make('#Panaman247'), // Change to a secure password
            'wallet_balance' => 100000.00, // Initial balance
            'email_verified_at' => now(),
            'phone' => '080' . rand(10000000, 99999999),
        ]);

        $this->command->info("User created/updated: {$user->email}");
    }
}
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionsFactory extends Factory
{
    public function definition()
    {
        $type = $this->faker->randomElement(['credit', 'debit']);
        $serviceTypes = ['airtime', 'data', 'cable-tv', 'jamb', 'electricity', 'funding'];
        
        if ($type === 'credit') {
            $serviceType = 'funding';
            $description = "Wallet funding via " . $this->faker->randomElement(['Bank Transfer', 'Card', 'USSD']);
            $recipient = null;
            $provider = null;
        } else {
            $serviceType = $this->faker->randomElement(['airtime', 'data', 'cable-tv', 'jamb']);
            $description = match($serviceType) {
                'airtime' => "Airtime purchase for {$this->faker->phoneNumber()}",
                'data' => "Data purchase for {$this->faker->phoneNumber()}",
                'cable-tv' => "{$this->faker->randomElement(['DStv', 'GOtv'])} subscription",
                'jamb' => "JAMB e-PIN purchase",
                default => "Service purchase"
            };
            $recipient = $this->faker->phoneNumber();
            $provider = $this->faker->randomElement(['MTN', 'Airtel', 'Glo', '9Mobile', 'DStv', 'GOtv']);
        }

        return [
            'user_id' => User::factory(),
            'type' => $type,
            'service_type' => $serviceType,
            'description' => $description,
            'reference' => strtoupper($this->faker->bothify('TRX-????-####')),
            'external_reference' => $this->faker->optional()->bothify('EXT-#####'),
            'amount' => $this->faker->randomFloat(2, 100, 50000),
            'balance_before' => $this->faker->randomFloat(2, 0, 100000),
            'balance_after' => $this->faker->randomFloat(2, 0, 100000),
            'status' => $this->faker->randomElement(['success', 'pending', 'failed']),
            'recipient' => $recipient,
            'provider' => $provider,
            'payment_method' => $type === 'credit' ? $this->faker->randomElement(['bank_transfer', 'card', 'ussd']) : null,
            'payment_reference' => $type === 'credit' ? $this->faker->bothify('PAY-#####') : null,
            'meta' => [
                'notes' => $this->faker->optional()->sentence(),
                'api_response' => ['status' => 'success', 'message' => 'Transaction completed']
            ],
            'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function credit()
    {
        return $this->state(function (array $attributes) {
            return [
                'type' => 'credit',
                'service_type' => 'funding',
                'description' => 'Wallet funding',
            ];
        });
    }

    public function debit()
    {
        return $this->state(function (array $attributes) {
            return [
                'type' => 'debit',
                'service_type' => $this->faker->randomElement(['airtime', 'data', 'cable-tv']),
            ];
        });
    }

    public function success()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'success',
            ];
        });
    }

    public function pending()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'pending',
            ];
        });
    }

    public function failed()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'failed',
            ];
        });
    }
}
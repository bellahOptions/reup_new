<?php

namespace App\Lifecycle;

/**
 * The trigger events automations may listen for, and the payload each supplies.
 *
 * A closed list, referenced by automations as a string. An automation naming an event
 * that is not here never fires — it is inert data rather than a hook into application
 * code, which is what keeps "select a trigger" a configuration screen rather than a
 * place where arbitrary behaviour is injected.
 */
class LifecycleTrigger
{
    public const USER_REGISTERED = 'user.registered';
    public const WALLET_FUNDED = 'wallet.funded';
    public const TRANSACTION_SUCCEEDED = 'transaction.succeeded';
    public const REFERRAL_REWARD_EARNED = 'referral.reward_earned';
    public const USER_BECAME_INACTIVE = 'user.became_inactive';

    /**
     * Event name => [label, when it fires, the payload it carries].
     *
     * The payload description matters as much as the name: a condition on a payload
     * key that the trigger never supplies silently evaluates as null, so the editor
     * shows the author what they will actually have.
     *
     * @return array<string, array{label:string,description:string,payload:array<int,string>}>
     */
    public static function all(): array
    {
        return [
            self::USER_REGISTERED => [
                'label' => 'Customer registered',
                'description' => 'Fires once, immediately after an account is created.',
                'payload' => [],
            ],
            self::WALLET_FUNDED => [
                'label' => 'Wallet funded',
                'description' => 'Fires when a funding payment settles — card, transfer, or reconciliation.',
                'payload' => ['amount_minor', 'funding_count', 'payment_method'],
            ],
            self::TRANSACTION_SUCCEEDED => [
                'label' => 'Transaction succeeded',
                'description' => 'Fires when a purchase is successfully vended (airtime, data, bills).',
                'payload' => ['service_type', 'amount_minor', 'transaction_count'],
            ],
            self::REFERRAL_REWARD_EARNED => [
                'label' => 'Referral reward earned',
                'description' => 'Fires when a referral reward is actually paid to the referrer.',
                'payload' => ['reward_minor', 'referred_user_id', 'referral_count'],
            ],
            self::USER_BECAME_INACTIVE => [
                'label' => 'Customer became inactive',
                'description' => 'Fires from the scheduled sweep once an account has been idle for the configured number of days.',
                'payload' => ['inactive_days'],
            ],
        ];
    }

    public static function exists(string $event): bool
    {
        return isset(self::all()[$event]);
    }

    /** @return array<int,string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function label(string $event): string
    {
        return self::all()[$event]['label'] ?? $event;
    }
}

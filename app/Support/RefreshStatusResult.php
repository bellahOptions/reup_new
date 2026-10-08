<?php

namespace App\Support;

use App\Models\Transactions;

/**
 * What a status refresh found.
 *
 * A value object rather than a bare array, because the admin console has to
 * distinguish "I checked and it failed" from "I could not check" — the first is
 * a resolved transaction, the second is one the operator must come back to.
 * Collapsing those into a single success flag is how an unreachable gateway
 * gets mistaken for a verdict.
 */
final class RefreshStatusResult
{
    public const OUTCOME_SETTLED = 'settled';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_FINAL = 'already_final';
    public const OUTCOME_UNREACHABLE = 'unreachable';
    public const OUTCOME_CONFIG_ERROR = 'config_error';

    private function __construct(
        public readonly string $outcome,
        public readonly string $message,
        public readonly string $status,
        public readonly string $paymentStatus,
        public readonly int $transactionId,
    ) {
    }

    private static function make(string $outcome, Transactions $transaction, string $message): self
    {
        return new self(
            outcome: $outcome,
            message: $message,
            status: (string) $transaction->status,
            paymentStatus: (string) $transaction->payment_status,
            transactionId: (int) $transaction->getKey(),
        );
    }

    /** The gateway confirmed payment and the wallet was credited. */
    public static function settled(Transactions $transaction, string $message): self
    {
        return self::make(self::OUTCOME_SETTLED, $transaction, $message);
    }

    /** The attempt is definitively dead, or the gateway reported a failure. */
    public static function failed(Transactions $transaction, string $message): self
    {
        return self::make(self::OUTCOME_FAILED, $transaction, $message);
    }

    /** A real status was read and it is still in flight: nothing was written. */
    public static function stillPending(Transactions $transaction, string $message): self
    {
        return self::make(self::OUTCOME_PENDING, $transaction, $message);
    }

    /** Nothing to look up — already success, failed or cancelled. */
    public static function final_(Transactions $transaction, string $message): self
    {
        return self::make(self::OUTCOME_FINAL, $transaction, $message);
    }

    /**
     * The gateway could not be reached, or would not answer for this reference.
     *
     * Deliberately distinct from `failed`: no money question was answered, so
     * the row is left exactly as it was.
     */
    public static function unreachable(Transactions $transaction, string $message): self
    {
        return self::make(self::OUTCOME_UNREACHABLE, $transaction, $message);
    }

    /** This environment cannot perform the lookup at all. */
    public static function configError(Transactions $transaction, string $message): self
    {
        return self::make(self::OUTCOME_CONFIG_ERROR, $transaction, $message);
    }

    /** Did the refresh change the transaction's status? */
    public function changed(): bool
    {
        return in_array($this->outcome, [self::OUTCOME_SETTLED, self::OUTCOME_FAILED], true);
    }

    /**
     * @return array{outcome:string,message:string,status:string,payment_status:string,transaction_id:int}
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'message' => $this->message,
            'status' => $this->status,
            'payment_status' => $this->paymentStatus,
            'transaction_id' => $this->transactionId,
        ];
    }
}

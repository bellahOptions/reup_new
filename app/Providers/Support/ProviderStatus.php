<?php

namespace App\Providers\Support;

/**
 * The one status vocabulary every provider adapter maps into.
 *
 * ## Why a mapper rather than passing provider strings around
 *
 * The four providers this application integrates disagree on vocabulary and,
 * worse, on what the words mean:
 *
 *   * Sogo: `processing`, `completed`, `failed`, `refunded`, `cancelled`
 *   * VTUGate: a boolean `status`, so a stringified `true`/`false`
 *   * VTpass: `delivered`, `pending`, `failed`, plus a three-digit `code`
 *   * Nitro: `Pending`, `In progress`, `Completed`, `Partial`, `Canceled`, `Refunded`
 *
 * If a controller branched on `$response['status']` then "Canceled" and
 * "cancelled" and "Canceled " would each need handling, and the one that was
 * missed would fall through to a default — and the default in a payment pipeline
 * is always either "refund a delivered order" or "mark a live order failed".
 * Mapping explicitly, with an explicit UNKNOWN fallback, makes the unhandled
 * case safe instead of expensive.
 *
 * ## The rule
 *
 * **UNKNOWN is not FAILED.** A status this mapper does not recognise becomes
 * UNKNOWN, never FAILED. UNKNOWN means "we do not know whether the customer was
 * served", and the application's response to it is to reconcile, not to retry,
 * fail over, or refund.
 */
final class ProviderStatus
{
    /** The provider fulfilled the order. */
    public const SUCCESS = 'SUCCESS';

    /** Accepted, not yet fulfilled. Terminal only via reconciliation or webhook. */
    public const PENDING = 'PENDING';

    /** Accepted and actively being worked. Same handling as PENDING. */
    public const PROCESSING = 'PROCESSING';

    /** The provider stated it did not fulfil the request. Final. */
    public const FAILED = 'FAILED';

    /**
     * The provider stated it did not fulfil the request, and the request never
     * reached the customer — so another provider may safely be tried.
     */
    public const RETRYABLE = 'RETRYABLE';

    /** We do not know. Never retry, never fail over, never refund. Reconcile. */
    public const UNKNOWN = 'UNKNOWN';

    /** Explicitly cancelled. Final. */
    public const CANCELLED = 'CANCELLED';

    /** Some of the order was delivered. Final, but needs a human decision. */
    public const PARTIAL = 'PARTIAL';

    /** Reversed and the money returned. Final. */
    public const REFUNDED = 'REFUNDED';

    /**
     * Every status, in the order they appear in the spec.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::SUCCESS,
            self::PENDING,
            self::PROCESSING,
            self::FAILED,
            self::RETRYABLE,
            self::UNKNOWN,
            self::CANCELLED,
            self::PARTIAL,
            self::REFUNDED,
        ];
    }

    /**
     * Whether a status means the outcome is known.
     *
     * False for PENDING, PROCESSING and UNKNOWN — the states in which retrying
     * or refunding can cost money.
     */
    public static function isResolved(string $status): bool
    {
        return ! in_array($status, [self::PENDING, self::PROCESSING, self::UNKNOWN], true);
    }

    /** Whether the customer's money is still held against this order. */
    public static function holdsFunds(string $status): bool
    {
        return in_array($status, [
            self::SUCCESS,
            self::PENDING,
            self::PROCESSING,
            self::UNKNOWN,
            self::PARTIAL,
        ], true);
    }

    /**
     * Whether another provider may be tried.
     *
     * Only RETRYABLE. Notably NOT FAILED: a provider that accepted the request
     * and then failed may still have delivered it, and a `failed` from one
     * upstream is not evidence that a second upstream should be asked.
     */
    public static function permitsFailover(string $status): bool
    {
        return $status === self::RETRYABLE;
    }

    /** Whether the customer is owed a refund for this state. */
    public static function warrantsRefund(string $status): bool
    {
        return in_array($status, [self::FAILED, self::CANCELLED], true);
    }

    /**
     * A label for the customer-facing status. Deliberately never says "failed"
     * for an unresolved state.
     */
    public static function customerLabel(string $status): string
    {
        return match ($status) {
            self::SUCCESS => 'Completed',
            self::PENDING, self::PROCESSING => 'Processing',
            self::UNKNOWN => 'Confirming',
            self::FAILED => 'Failed',
            self::RETRYABLE => 'Failed',
            self::CANCELLED => 'Cancelled',
            self::PARTIAL => 'Partially completed',
            self::REFUNDED => 'Refunded',
            default => 'Confirming',
        };
    }

    /**
     * Normalise an arbitrary provider string for comparison.
     *
     * Lower-cases, strips punctuation and whitespace, so `In progress`,
     * `IN_PROGRESS` and `in-progress` all reduce to the same token.
     */
    public static function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($value))) ?? '';
    }
}

<?php

namespace App\Support;

use App\Models\ServiceProduct;
use Carbon\CarbonInterface;

/**
 * The approved customer-facing copy, plus the status vocabulary that must never
 * mislead.
 *
 * ## Why status resolution lives here rather than in a view
 *
 * §51.21 and §51.43 state the rule that carries the most money:
 *
 *   * never imply success before backend confirmation;
 *   * never imply failure when the state is UNKNOWN or PENDING.
 *
 * If that rule lives in a Blade template it is a rule per template, and the
 * fortieth template is the one that gets it wrong — describing a timed-out purchase
 * as failed, which invites the customer to pay again for something they may already
 * have received. Expressing it once, as a mapping from canonical status to copy,
 * makes the mistake impossible to make in a view: the view asks for a label and
 * cannot produce one the rule forbids.
 *
 * `App\Providers\Support\ProviderStatus` already provides the safe defaults
 * (`customerLabel()` returns "Confirming" for UNKNOWN). This adds the *copy deck's*
 * wording on top, and the distinction between "we are processing" and "we are
 * reconciling" — which is the difference between a customer waiting and a customer
 * being told to stop worrying.
 */
final class UiCopy
{
    /**
     * The approved string for a dot path.
     *
     * Both placeholder styles are supported — `{token}` and `:token` — because the
     * approved copy deck writes `You are about to pay ₦{amount} for {service}` while
     * Laravel's own convention is the colon form. Normalising both here means the copy
     * can be pasted from the deck verbatim, which is what makes it reviewable against
     * the deck; requiring an edit on paste is how a deck and its implementation drift.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $value = config('copy.' . $key);

        if (! is_string($value)) {
            /*
             * A missing key returns the key rather than an empty string. An empty
             * string renders as a blank on the page, which nobody reports; a key
             * renders as `outcome.failed.heading`, which somebody does.
             */
            return $key;
        }

        foreach ($replace as $token => $replacement) {
            $value = str_replace(
                ['{' . $token . '}', ':' . $token],
                (string) $replacement,
                $value,
            );
        }

        return $value;
    }

    /**
     * Whether a copy key exists. Used by the tests that keep this deck complete.
     */
    public static function has(string $key): bool
    {
        return is_string(config('copy.' . $key));
    }

    /**
     * The greeting for the customer's local time (§51.8).
     *
     * The hour comes from the caller so the greeting follows the *customer's* clock
     * rather than the server's — a Lagos customer reading "Good evening" at 2pm
     * because the application server is in UTC is a small thing that makes a product
     * feel foreign.
     */
    public static function greeting(CarbonInterface $localTime): string
    {
        $hour = (int) $localTime->format('G');

        return match (true) {
            $hour < 12 => self::get('dashboard.greetings.morning'),
            $hour < 17 => self::get('dashboard.greetings.afternoon'),
            default => self::get('dashboard.greetings.evening'),
        };
    }

    /**
     * The customer-facing label for a transaction status.
     *
     * ## The rule, in one place
     *
     * UNKNOWN, PENDING and PROCESSING all read as "Payment Processing". None of them
     * reads as a failure. FAILED is the only status that produces the failure copy,
     * and it is reached only when a provider has explicitly stated the transaction
     * failed or the order was cancelled.
     *
     * A view cannot get this wrong, because the only way to render a status is to ask
     * this method.
     */
    public static function statusLabel(string $status): string
    {
        return match (strtolower($status)) {
            'success', 'successful', 'completed', 'delivered' => self::get('outcome.success.heading'),
            'processing', 'pending', 'initiated', 'verifying', 'unknown' => self::get('outcome.processing.heading'),
            'failed', 'reversed_failed' => self::get('outcome.failed.heading'),
            'cancelled', 'canceled' => self::get('outcome.cancelled.heading'),
            'refunded' => self::get('outcome.refund.heading'),
            default => self::get('outcome.processing.heading'),
        };
    }

    /**
     * The supporting sentence for a transaction status.
     *
     * `$refundRecorded` distinguishes "your wallet has been refunded" from "the
     * amount will be reversed once confirmed". §51.22 is explicit that a refund must
     * not be claimed before the financial system has recorded it, so the caller has to
     * pass the fact rather than the copy guessing.
     */
    public static function statusDescription(string $status, bool $refundRecorded = false): string
    {
        return match (strtolower($status)) {
            'success', 'successful', 'completed', 'delivered' => self::get('outcome.success.description'),
            'processing', 'pending', 'initiated', 'verifying', 'unknown' => self::get('outcome.processing.description'),
            'failed' => $refundRecorded
                ? self::get('outcome.failed.refunded')
                : self::get('outcome.failed.refund_pending'),
            'cancelled', 'canceled' => self::get('outcome.cancelled.description'),
            'refunded' => self::get('outcome.refund.description'),
            default => self::get('outcome.processing.description'),
        };
    }

    /**
     * The additional line shown while a transaction is being reconciled.
     *
     * Only for unresolved states. Returning null is meaningful: it tells the view
     * there is nothing to add for a resolved transaction, rather than the view having
     * to decide.
     */
    public static function reconciliationNote(string $status): ?string
    {
        return self::isUnresolved($status) ? self::get('outcome.reconciling.description') : null;
    }

    /**
     * Whether a status means the outcome is not yet known.
     *
     * ## Why this is a deny-list rather than an allow-list
     *
     * It would be natural to list the in-flight states — pending, processing,
     * unknown — and return true only for those. That inverts the safe default. A
     * status this build has never seen would then be classified as *resolved*, and
     * `reconciliationNote()` would stay silent about a transaction whose outcome
     * nobody knows. Silence reads as "nothing to worry about", which is exactly the
     * wrong signal.
     *
     * So resolution is enumerated instead: only a status that positively states the
     * transaction is finished counts as finished. Everything else — including
     * vocabulary a provider introduces next year — is unresolved, the customer is
     * told we are still checking, and the order is reconciled rather than refunded or
     * repeated.
     */
    public static function isUnresolved(string $status): bool
    {
        return ! in_array(strtolower($status), self::RESOLVED_STATUSES, true);
    }

    /**
     * Statuses that state the transaction is finished.
     *
     * Deliberately excludes `partial`: an order that delivered some of what was
     * bought is not finished, and a customer told "we are still checking" about it is
     * better served than one told it is done.
     */
    private const RESOLVED_STATUSES = [
        'success', 'successful', 'completed', 'delivered',
        'failed', 'cancelled', 'canceled',
        'refunded',
    ];

    /**
     * The success heading for a service, falling back to the generic one.
     *
     * Read from `outcome.service_success` so a new service added to the catalogue
     * without copy falls back to "Payment Successful" rather than rendering a
     * missing-key string to a customer.
     */
    public static function serviceSuccessHeading(?string $serviceType): string
    {
        return self::serviceKeyed('outcome.service_success', $serviceType)
            ?? self::get('outcome.success.heading');
    }

    /** The in-flight sentence for a service, falling back to the generic one. */
    public static function serviceProcessingDescription(?string $serviceType): string
    {
        return self::serviceKeyed('outcome.service_processing', $serviceType)
            ?? self::get('outcome.processing.description');
    }

    /** The failure sentence for a service, falling back to the generic one. */
    public static function serviceFailureDescription(?string $serviceType): string
    {
        return self::serviceKeyed('outcome.service_failed', $serviceType)
            ?? self::get('outcome.failed.description');
    }

    /**
     * The message shown when a product is withheld from sale.
     *
     * The reason decides the wording, and the wording is the whole point: a product
     * paused for pricing says so, an unpublished product says it is not ready, and
     * neither mentions cost, margin or the provider. §51.37 and §51.36.
     */
    public static function unavailableMessage(ServiceProduct $product): string
    {
        return match ($product->status) {
            ServiceProduct::STATUS_UNPROFITABLE => self::get('availability.pricing'),
            ServiceProduct::STATUS_PROVIDER_UNAVAILABLE => self::get('availability.description'),
            ServiceProduct::STATUS_DISCOVERED, ServiceProduct::STATUS_REVIEW => self::get('availability.unpublished'),
            ServiceProduct::STATUS_OUT_OF_STOCK => self::get('availability.description'),
            default => $product->availability === ServiceProduct::AVAILABILITY_UNAVAILABLE
                ? self::get('availability.description')
                : self::get('availability.unpublished'),
        };
    }

    private static function serviceKeyed(string $root, ?string $serviceType): ?string
    {
        if ($serviceType === null) {
            return null;
        }

        $value = config('copy.' . $root . '.' . strtolower($serviceType));

        return is_string($value) ? $value : null;
    }
}

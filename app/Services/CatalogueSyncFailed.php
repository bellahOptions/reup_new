<?php

namespace App\Services;

/**
 * A catalogue synchronisation that did not produce a usable catalogue.
 *
 * ## Why this is an exception rather than a null return
 *
 * The caller of a *scheduled* sync has to tell three states apart:
 *
 *   * the provider answered and the catalogue is now current;
 *   * the provider did not answer, and the previous catalogue is still in place;
 *   * the provider answered with something we could not use.
 *
 * A null or an empty array collapses all three. The command would exit zero for
 * a run that fetched nothing, the scheduler's health check would report a
 * healthy catalogue that had not been refreshed for hours, and the first symptom
 * would be a customer paying a price derived from a cost the provider stopped
 * quoting.
 *
 * The *reason* travels with the message so the status record can classify it
 * without parsing prose, and so an operator reading the dashboard sees "rate
 * limited" rather than a sentence they have to interpret.
 *
 * ## Why the customer never sees it
 *
 * The message is for an operator: it names statuses and payload shapes. The
 * customer-facing string is a separate, deliberately uninformative sentence —
 * telling a customer that our supplier's price feed is broken invites exactly
 * the wrong conversation and says nothing about whether they were charged.
 */
class CatalogueSyncFailed extends \RuntimeException
{
    public function __construct(
        /** One of `CatalogueSyncStatus::ERROR_*`. */
        public readonly string $reason,
        string $message,
        /** The provider's HTTP status, when there was one. */
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    /**
     * What a customer is told.
     *
     * States that nothing was charged, because the only thing a customer can do
     * with this message is decide whether to try again — and the answer depends
     * on whether money moved. It did not: the catalogue is read before any
     * purchase is priced or debited.
     */
    public function customerMessage(): string
    {
        return 'Live data rates are temporarily unavailable. Please try again shortly.';
    }
}

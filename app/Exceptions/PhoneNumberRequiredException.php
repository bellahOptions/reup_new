<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The payment provider needs a phone number we do not have.
 *
 * ## Why this is its own exception
 *
 * Paystack attaches a dedicated virtual account to a *customer record*, and it
 * refuses to create one for a customer with no phone number — "Customer phone
 * number is required". That is not a transient gateway fault and not a
 * configuration error on our side: it is a missing piece of **the customer's own
 * data**, and only they can supply it.
 *
 * The distinction matters because the three cases need three different
 * responses:
 *
 *   * a provider outage is retried and reported as such;
 *   * a misconfiguration is for us to fix, and the details must never reach the
 *     customer;
 *   * this one has to be *actionable*. "We could not issue your personal account
 *     number just now" is what the customer saw before — a dead end they cannot
 *     act on, for a problem they could have fixed in ten seconds by typing their
 *     phone number.
 *
 * So it is thrown with a message written for the customer, and the surfaces that
 * catch it turn it into a prompt with a link to the profile form rather than a
 * generic apology.
 */
class PhoneNumberRequiredException extends RuntimeException
{
    public function __construct(
        string $message = 'We need your phone number before we can create your personal account number.',
    ) {
        parent::__construct($message);
    }
}

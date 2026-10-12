<?php

namespace App\Lifecycle;

use App\Models\LifecycleMessage;

/**
 * Decides whether a scheduled message is *still* worth sending.
 *
 * ## Why this is separate from scheduling
 *
 * A delayed automation is scheduled when its trigger fires and sent hours or days
 * later. Invitations, tests and — most importantly — state-dependent reminders all
 * depend on a fact that can have changed in between:
 *
 *   * the trigger fired, the message waited 24 hours, and the customer funded their
 *     wallet in the meantime. A reminder saying "you have not funded your wallet yet"
 *     would now be wrong, and there is no recovering from having sent it;
 *   * the automation was paused, or its window closed, after the message was queued;
 *   * the template was withdrawn;
 *   * the address bounced on an earlier message in the batch.
 *
 * The brief is explicit — "recheck eligibility immediately before sending" — and this
 * is that check. It runs inside `LifecycleDispatcher::attempt()`, which is the only
 * place a message is ever sent, so a caller cannot route around it.
 *
 * ## Cancelled, not suppressed
 *
 * A message that no longer applies is `cancelled`. `suppressed` means the platform must
 * not mail the address at all, which is a different fact about a different thing, and
 * conflating them makes the delivery log unable to distinguish "we chose not to" from
 * "we are not allowed to".
 */
class LifecycleEligibility
{
    public function __construct(
        private readonly LifecycleConditionEvaluator $conditions,
    ) {
    }

    /**
     * Is this message still worth sending?
     *
     * @return array{eligible:bool,reason:?string}
     */
    public function stillEligible(LifecycleMessage $message): array
    {
        $automation = $message->automation;

        if (! $automation) {
            return $this->no('The automation no longer exists.');
        }

        /*
         * A test send is its own automation, created as a draft on purpose — it exists
         * so the delivery log keeps a real foreign key rather than a nullable one every
         * query has to special-case. It has no conditions and is deliberately not
         * "active", so the gates below would refuse it. It is identified by its key
         * rather than by a column, because a column for "this row is a test" is a column
         * that the next feature has to remember to set.
         */
        $isTest = str_starts_with((string) $automation->key, 'test-');

        if (! $isTest) {
            $blocking = $automation->blockingReason();

            if ($blocking !== null) {
                return $this->no($blocking);
            }

            $template = $automation->template;

            if (! $template) {
                return $this->no('The template no longer exists.');
            }

            /*
             * The conditions are re-evaluated **against the customer as they are now**,
             * which is the whole point: this is the state the reminder was about, not
             * the state it was scheduled in.
             */
            $user = $message->user;

            if (! $user) {
                return $this->no('The customer no longer exists.');
            }

            if (! $this->conditions->matches($automation, $user, $message->event?->payload ?? [])) {
                return $this->no('The conditions no longer match.');
            }
        }

        /*
         * Suppression is deliberately **not** checked here.
         *
         * It was, briefly, and it was wrong: it made a suppressed address report
         * `cancelled` rather than `suppressed`, because this method runs first and both
         * facts were true. `suppressed` means the platform must not mail the address at
         * all; `cancelled` means the message stopped being worth sending. Conflating
         * them makes the delivery log unable to distinguish "we chose not to" from "we
         * are not allowed to", which is exactly the distinction an operator needs.
         *
         * `LifecycleDispatcher::blockingReason()` owns suppression, and `attempt()`
         * checks it immediately before delivering — so it has one owner and one answer.
         */

        return ['eligible' => true, 'reason' => null];
    }

    /**
     * @return array{eligible:bool,reason:?string}
     */
    private function no(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason];
    }
}

<?php

namespace App\Http\Controllers;

use App\Mail\LifecycleMessageMail;
use App\Models\LifecycleConsent;
use App\Models\LifecycleSuppression;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Marketing unsubscribe.
 *
 * ## The one unauthenticated endpoint in this module, and why it is safe
 *
 * A customer must be able to opt out from an email client, which means without a
 * session. The link therefore carries a signature valid for 180 days and names the
 * user id — so an opt-out cannot be forged for somebody else, and cannot be triggered
 * by guessing addresses.
 *
 * `signed` is the route middleware, so an unsigned or expired request never reaches
 * this class at all.
 *
 * ## Idempotent, and honest about it
 *
 * A second visit says "already unsubscribed" rather than pretending to do something.
 * Email clients fetch unsubscribe links to preview them, so a double request is
 * normal, not an attack.
 *
 * ## Marketing only
 *
 * This records a marketing opt-out. It does not stop receipts, funding confirmations,
 * password resets or security alerts, and the page says so. A customer who believes
 * they have stopped all email and then receives a receipt is a support ticket; one who
 * believes it and misses a fraud alert is worse.
 */
class LifecycleUnsubscribeController extends Controller
{
    public function show(Request $request, User $user)
    {
        return view('lifecycle.unsubscribe', [
            'user' => $user,
            'alreadyOptedOut' => LifecycleConsent::marketingOptedOut((string) $user->email),
        ]);
    }

    public function unsubscribe(Request $request, User $user)
    {
        $already = LifecycleConsent::marketingOptedOut((string) $user->email);

        LifecycleConsent::optOut(
            (string) $user->email,
            'email_link',
            $request->ip()
        );

        /*
         * A suppression as well as a consent record.
         *
         * They answer different questions — consent is the customer's decision,
         * suppression is the platform's block list — and the dispatcher consults both.
         * Recording only consent would leave an address that opted out still eligible
         * for a transactional send, which is correct; recording only suppression would
         * lose the reason and the timestamp.
         */
        LifecycleSuppression::firstOrCreate(
            ['email' => strtolower((string) $user->email), 'reason' => LifecycleSuppression::REASON_UNSUBSCRIBE],
            ['note' => 'Opted out from an email link.']
        );

        Log::info('Customer opted out of marketing email', [
            'user_id' => $user->getKey(),
            'already' => $already,
        ]);

        return view('lifecycle.unsubscribed', [
            'user' => $user,
            'alreadyOptedOut' => $already,
        ]);
    }
}

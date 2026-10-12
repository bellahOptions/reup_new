<?php

namespace App\Listeners;

use App\Lifecycle\LifecycleRecorder;
use App\Lifecycle\LifecycleTrigger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Record `user.registered` so a welcome automation can fire.
 *
 * ## Why a listener and not a line in the controller
 *
 * Registration happens in one place today and could happen in three tomorrow — an
 * admin-created customer, an import, an API. A listener on the framework's own
 * `Registered` event covers all of them, and keeps the controller concerned with
 * registering rather than with messaging.
 *
 * ## Why the service is resolved in `handle()` rather than injected
 *
 * A queued listener is **serialised onto the queue**, and its constructor arguments
 * are serialised with it. `LifecycleRecorder` holds services that hold a database
 * connection and a cache — none of which survive `serialize()`, and the failure
 * appears at dispatch time as an obscure "Serialization of 'Closure' is not allowed"
 * rather than as anything about messaging. Resolving from the container inside
 * `handle()` sidesteps it and is what the existing `SendWelcomeEmail` listener does.
 *
 * ## The recorder never throws
 *
 * Every trigger site is a customer-facing operation. A messaging table problem must
 * not cost somebody their account, so `record()` swallows and logs.
 */
class RecordUserRegistered implements ShouldQueue
{
    public function handle(Registered $event): void
    {
        try {
            app(LifecycleRecorder::class)->record(
                LifecycleTrigger::USER_REGISTERED,
                $event->user,
                [],
                // One welcome per account, ever — not one per `Registered` dispatch.
                dedupeKey: 'registered'
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}

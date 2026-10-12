<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer lifecycle messaging
    |--------------------------------------------------------------------------
    |
    | Communications with the customer across the life of their account: a welcome,
    | funding and first-transaction nudges, a referral invitation, and a
    | re-engagement after a period of inactivity.
    |
    | Everything here is configuration. Nothing in this file sends anything by itself:
    | automations are rows in `lifecycle_automations`, they are created disabled, and
    | an operator activates them deliberately. See `LifecycleSeeder` for the five
    | examples this module is designed around.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sender identity
    |--------------------------------------------------------------------------
    |
    | The display name on lifecycle email. The *address* is never set here: it is
    | `MAIL_FROM_ADDRESS`, the one ReUp has verified with its mail provider, and an
    | unverified From is rejected by most providers and damages the sending domain that
    | every other email depends on — including password resets.
    |
    | A named human sender reads better on engagement mail than a company name, which is
    | why this is separate from `MAIL_FROM_NAME`: receipts keep the corporate name and
    | the welcome can come from a person. Falls back to `MAIL_FROM_NAME` so a deployment
    | that sets nothing still sends from something intentional.
    |
    */
    'from_name' => env('LIFECYCLE_FROM_NAME') ?: env('MAIL_FROM_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Inactivity
    |--------------------------------------------------------------------------
    |
    | After how many days without activity a customer is considered inactive. The
    | trigger is bucketed in multiples of this, so a customer who stays away for twice
    | as long fires twice rather than on every tick.
    |
    | Zero disables the sweep entirely.
    |
    */
    'inactive_after_days' => (int) env('LIFECYCLE_INACTIVE_AFTER_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Lifecycle mail runs on its own queue so it cannot sit in front of a payment
    | receipt. `lifecycle` must therefore be listed in the queue worker's `--queue`
    | argument, or these jobs are never consumed — see docs/RUNNING.md.
    |
    | `max_attempts` is the dispatcher's own retry budget across sweeps, and is
    | separate from the queue's `job_tries`. Both exist on purpose: the job retries
    | now, the sweep retries later, and a deployment with no worker at all still
    | eventually sends.
    |
    */
    'queue' => [
        'name' => env('LIFECYCLE_QUEUE', 'lifecycle'),
        'job_tries' => (int) env('LIFECYCLE_JOB_TRIES', 3),
        'backoff_seconds' => [60, 300, 900],
        'max_attempts' => (int) env('LIFECYCLE_MAX_ATTEMPTS', 3),
        'backoff_base_minutes' => (int) env('LIFECYCLE_BACKOFF_BASE_MINUTES', 5),

        /*
         * How long a message may sit at `sending` before the sweep assumes its worker
         * died and releases it. Too short releases a slow worker's row and risks one
         * duplicate; too long leaves a customer waiting. Ten minutes is well beyond any
         * plausible SMTP handoff and well inside a customer's patience.
         */
        'lease_minutes' => (int) env('LIFECYCLE_LEASE_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    |
    | Reported on the module overview, never acted on automatically. Suppressing a
    | bounced address is automatic; pausing a campaign on a threshold nobody set is a
    | surprising thing for a system to decide by itself.
    |
    */
    'bounce_rate_warning_bps' => (int) env('LIFECYCLE_BOUNCE_WARNING_BPS', 500),

];

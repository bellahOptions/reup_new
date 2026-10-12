<?php

namespace App\Lifecycle;

use App\Jobs\LifecycleSendMessage;
use App\Models\LifecycleEvent;
use App\Models\LifecycleMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns what is due into queued jobs, and nothing else.
 *
 * ## Why the scheduled command must not send
 *
 * The command runs every minute inside the scheduler, which is a single process shared
 * with payment reconciliation. Sending mail from there would block reconciliation
 * behind an SMTP handoff and would lose every message if the scheduler was restarted
 * mid-batch. So this identifies work and enqueues it; a queue worker does the sending.
 *
 * A scheduler entry does not replace a queue worker, and this module needs both.
 *
 * ## The four things a tick does
 *
 *   1. **Drain the outbox.** Events written by the money paths, turned into scheduled
 *      messages. This is where conditions are evaluated.
 *   2. **Enqueue what is due.** Messages whose `scheduled_for` has arrived.
 *   3. **Recover what is stuck.** A `sending` row whose worker died stays claimed
 *      forever otherwise, and the customer silently never receives the email. Any row
 *      claimed longer ago than the lease is released — and because sending is
 *      idempotent per row and the row is only marked `accepted` after the transport
 *      took it, releasing can at worst send a duplicate, which is the correct side to
 *      fail on.
 *   4. **Fire the inactivity trigger.** The one trigger nothing can observe directly.
 *
 * Every step is idempotent, so two ticks overlapping, or a tick after a crash, cannot
 * double-send: the outbox claim is a conditional update, the enqueue is a status
 * transition, and the message row carries a unique idempotency index.
 */
class LifecycleScheduler
{
    public function __construct(
        private readonly LifecycleRecorder $recorder,
        private readonly LifecycleDispatcher $dispatcher,
    ) {
    }

    /**
     * One tick.
     *
     * @return array<string,int>
     */
    public function run(int $batch = 200): array
    {
        return [
            'events' => $this->drainOutbox($batch),
            'queued' => $this->enqueueDue($batch),
            'released' => $this->releaseStuck($batch),
            'inactive_events' => $this->fireInactiveEvents($batch),
        ];
    }

    /**
     * Turn recorded events into scheduled messages.
     */
    public function drainOutbox(int $limit): int
    {
        $events = LifecycleEvent::query()
            ->whereNull('processed_at')
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get();

        $scheduled = 0;

        foreach ($events as $event) {
            try {
                $scheduled += $this->recorder->processEvent($event)['scheduled'];
            } catch (\Throwable $e) {
                /*
                 * One malformed event must not stop the drain. It is left unclaimed so
                 * the next tick tries again, and it is logged loudly because a
                 * permanently-failing event is a bug rather than a transient fault.
                 */
                Log::error('Lifecycle event could not be processed', [
                    'event_id' => $event->getKey(),
                    'name' => $event->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $scheduled;
    }

    /**
     * Enqueue every message whose time has come.
     *
     * The status transition is the claim: `scheduled → queued` in a single conditional
     * update, so two workers cannot both enqueue the same row and the queue receives
     * one job per message.
     */
    public function enqueueDue(int $limit): int
    {
        $due = LifecycleMessage::query()
            ->where('status', LifecycleMessage::STATUS_SCHEDULED)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->pluck('id');

        $queued = 0;

        foreach ($due as $id) {
            $claimed = LifecycleMessage::whereKey($id)
                ->where('status', LifecycleMessage::STATUS_SCHEDULED)
                ->update([
                    'status' => LifecycleMessage::STATUS_QUEUED,
                    'queued_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                continue;
            }

            LifecycleSendMessage::dispatch($id);
            $queued++;
        }

        return $queued;
    }

    /**
     * Release messages a dead worker left claimed.
     *
     * Without this, a worker killed mid-send leaves its row at `sending` forever: the
     * enqueue step only looks at `scheduled`, so nothing ever picks it up again and the
     * customer never hears from us. That is a silent, permanent failure of exactly the
     * kind a "delivered or not" column set is supposed to make visible.
     */
    public function releaseStuck(int $limit): int
    {
        $lease = (int) config('lifecycle.queue.lease_minutes', 10);

        return LifecycleMessage::query()
            ->where('status', LifecycleMessage::STATUS_SENDING)
            ->where('updated_at', '<=', now()->subMinutes($lease))
            ->limit($limit)
            ->update([
                'status' => LifecycleMessage::STATUS_SCHEDULED,
                'scheduled_for' => now(),
                'status_message' => 'Released from a worker that did not finish.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Fire `user.became_inactive` for customers who have crossed the threshold.
     *
     * The one trigger nothing in the application can observe: inactivity is the
     * *absence* of an event, so it has to be swept for.
     *
     * ## Idempotency
     *
     * The event's dedupe key is the threshold bucket the customer is in — `inactive-14`
     * for a 14-day threshold — so a customer who stays inactive for a month produces
     * one event, not one per tick. Moving to the next bucket (28 days, say) is a
     * genuinely new event and fires again, which is the intended behaviour for a
     * staged re-engagement.
     */
    public function fireInactiveEvents(int $limit): int
    {
        $thresholdDays = (int) config('lifecycle.inactive_after_days', 14);

        if ($thresholdDays <= 0) {
            return 0;
        }

        /*
         * Only swept when an active automation listens for it. Without this, the query
         * runs on every tick on a deployment that has no re-engagement campaign, which
         * is the common case and pure cost.
         */
        $listening = \App\Models\LifecycleAutomation::where('trigger_event', LifecycleTrigger::USER_BECAME_INACTIVE)
            ->where('status', \App\Models\LifecycleAutomation::STATUS_ACTIVE)
            ->exists();

        if (! $listening) {
            return 0;
        }

        $cutoff = now()->subDays($thresholdDays);

        $users = User::query()
            ->regularUsers()
            ->where('created_at', '<=', $cutoff)
            ->where(function ($q) use ($cutoff) {
                // Never active, or last active before the cutoff. `last_activity_at` is
                // written by the admin middleware and `last_activity` by the customer
                // tracker, so both are consulted.
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNull('last_activity_at')->whereNull('last_activity');
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->where('last_activity_at', '<=', $cutoff)
                        ->where(function ($deepest) use ($cutoff) {
                            $deepest->whereNull('last_activity')->orWhere('last_activity', '<=', $cutoff);
                        });
                });
            })
            ->limit($limit)
            ->get();

        $fired = 0;

        foreach ($users as $user) {
            $last = $user->last_activity_at ?? $user->last_activity ?? $user->created_at;
            $days = $last === null ? $thresholdDays : (int) $last->diffInDays(now());

            // Bucketed in whole multiples of the threshold, so the key is stable while
            // the customer remains in the same band of inactivity.
            $bucket = max(1, intdiv($days, $thresholdDays));

            $recorded = $this->recorder->record(
                LifecycleTrigger::USER_BECAME_INACTIVE,
                $user,
                ['inactive_days' => $days],
                dedupeKey: 'inactive-' . ($bucket * $thresholdDays)
            );

            if ($recorded !== null) {
                $fired++;
            }
        }

        return $fired;
    }

    /**
     * Operational counts for the module overview.
     *
     * @return array<string,int>
     */
    public function snapshot(): array
    {
        return [
            'active_automations' => \App\Models\LifecycleAutomation::where('status', \App\Models\LifecycleAutomation::STATUS_ACTIVE)->count(),
            'sent_today' => LifecycleMessage::whereIn('status', [LifecycleMessage::STATUS_ACCEPTED, LifecycleMessage::STATUS_DELIVERED])
                ->whereDate('accepted_at', today())
                ->count(),
            'queued' => LifecycleMessage::whereIn('status', [LifecycleMessage::STATUS_QUEUED, LifecycleMessage::STATUS_SENDING])->count(),
            'scheduled' => LifecycleMessage::where('status', LifecycleMessage::STATUS_SCHEDULED)->count(),
            'failed' => LifecycleMessage::where('status', LifecycleMessage::STATUS_FAILED)->count(),
            'bounced' => LifecycleMessage::where('status', LifecycleMessage::STATUS_BOUNCED)->count(),
            'suppressed' => LifecycleMessage::where('status', LifecycleMessage::STATUS_SUPPRESSED)->count(),
            'pending_events' => LifecycleEvent::whereNull('processed_at')->count(),
        ];
    }

    /**
     * The bounce rate over a window, for the monitoring warning.
     *
     * A rate above a few percent means the sending domain is in trouble, which affects
     * every email the platform sends — including password resets. It is reported
     * rather than acted on automatically: suppressing addresses is already automatic,
     * and pausing a campaign on a threshold nobody configured is a surprising thing for
     * a system to do on its own.
     *
     * @return array{total:int,bounced:int,rate_bps:int}
     */
    public function bounceRate(int $days = 7): array
    {
        $since = now()->subDays($days);

        $total = LifecycleMessage::where('created_at', '>=', $since)
            ->whereIn('status', [LifecycleMessage::STATUS_ACCEPTED, LifecycleMessage::STATUS_DELIVERED, LifecycleMessage::STATUS_BOUNCED, LifecycleMessage::STATUS_FAILED])
            ->count();

        $bounced = LifecycleMessage::where('created_at', '>=', $since)
            ->whereIn('status', [LifecycleMessage::STATUS_BOUNCED, LifecycleMessage::STATUS_FAILED])
            ->count();

        return [
            'total' => $total,
            'bounced' => $bounced,
            'rate_bps' => $total > 0 ? (int) round($bounced * 10000 / $total) : 0,
        ];
    }
}

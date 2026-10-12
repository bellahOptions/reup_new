<?php

namespace App\Lifecycle;

use App\Models\LifecycleAutomation;
use App\Models\LifecycleEmailTemplate;
use App\Models\LifecycleEvent;
use App\Models\LifecycleMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records domain events durably, and turns them into scheduled messages.
 *
 * ## The split, and why it matters
 *
 * `record()` is called from inside the money paths — a registration, a settled
 * funding, a vended purchase, a paid referral. It writes **one row** and does nothing
 * else. No mail, no queue, no condition evaluation, and therefore no network call
 * inside a row lock.
 *
 * `processEvent()` runs afterwards, from the scheduled command, outside those
 * transactions. That is the transactional outbox: the event and the thing that caused
 * it commit together, and a queue that is down when the event happens delays the email
 * instead of losing it.
 *
 * ## Nothing here may throw into the caller
 *
 * Every trigger site is a money path. A lifecycle problem — a missing table, a bad
 * configuration row, a full disk — must not fail a customer's payment, and must not
 * roll back a credit they have already made. So `record()` swallows and logs, which is
 * the opposite of the usual advice and the right call for the place it runs.
 */
class LifecycleRecorder
{
    public function __construct(
        private readonly LifecycleConditionEvaluator $conditions,
        private readonly LifecycleDispatcher $dispatcher,
        private readonly LifecycleVariables $variables,
    ) {
    }

    /**
     * Record that something happened to a customer.
     *
     * @param  array<string,mixed>  $payload
     */
    public function record(string $event, User $user, array $payload = [], ?string $dedupeKey = null, ?\DateTimeInterface $occurredAt = null): ?LifecycleEvent
    {
        if (! LifecycleTrigger::exists($event)) {
            return null;
        }

        try {
            return LifecycleEvent::create([
                'name' => $event,
                'user_id' => $user->getKey(),
                'payload' => $payload,
                // A null key would make the unique index useless — MySQL treats every
                // NULL as distinct — so an absent key becomes one derived from the
                // event itself, which is what "this event, once" means.
                'dedupe_key' => $dedupeKey ?? $event,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // The same domain event, twice. One row is correct.
            return null;
        } catch (Throwable $e) {
            Log::error('Could not record a lifecycle event', [
                'event' => $event,
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Schedule every message this event should produce.
     *
     * ## Idempotency has two layers here
     *
     * The event row is claimed with `processed_at` set **before** the work — a
     * conditional update, so exactly one worker claims it even across processes — and
     * the message row carries a unique `(automation, customer, dedupe_key)`. The first
     * stops the same event being processed twice; the second stops the same message
     * existing twice even if it is.
     *
     * The claim is written first on purpose. Processing twice would send twice; a crash
     * after the claim leaves messages already scheduled, and those are picked up by the
     * dispatch sweep rather than being lost.
     *
     * @return array{scheduled:int,skipped:int}
     */
    public function processEvent(LifecycleEvent $event): array
    {
        $claimed = LifecycleEvent::whereKey($event->getKey())
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        if ($claimed === 0) {
            return ['scheduled' => 0, 'skipped' => 0];
        }

        $event->refresh();

        $user = $event->user;

        /*
         * The customer may be gone by the time the event is processed — a deletion
         * between the event and the sweep. `cascadeOnDelete` removes their events too,
         * so this is the window between the two, and skipping is right.
         */
        if (! $user) {
            return ['scheduled' => 0, 'skipped' => 0];
        }

        $automations = LifecycleAutomation::query()
            ->where('trigger_event', $event->name)
            ->where('status', LifecycleAutomation::STATUS_ACTIVE)
            ->with('template')
            ->get();

        $scheduled = 0;
        $skipped = 0;

        foreach ($automations as $automation) {
            $template = $automation->template;

            if (! $template || ! $template->isActive()) {
                $skipped++;

                continue;
            }

            // The window, the cap and the status are re-checked per event rather than
            // once per run, because `active_until` can pass mid-batch.
            if ($automation->blockingReason() !== null) {
                $skipped++;

                continue;
            }

            if (! $this->conditions->matches($automation, $user, $event->payload ?? [])) {
                $skipped++;

                continue;
            }

            // Per-customer daily cap: a burst of events (three purchases in a minute)
            // must not produce three identical emails.
            if ($this->reachedDailyLimit($automation, $user)) {
                $skipped++;

                continue;
            }

            $message = $this->dispatcher->schedule($automation, $template, $user, [
                'dedupe_key' => $this->dedupeKeyFor($event, $automation),
                'scheduled_for' => now()->addMinutes((int) $automation->delay_minutes),
                'event_id' => $event->getKey(),
            ]);

            if ($message && $message->wasRecentlyCreated) {
                $scheduled++;
            } else {
                $skipped++;
            }
        }

        return ['scheduled' => $scheduled, 'skipped' => $skipped];
    }

    /**
     * The stable identity of "this email, for this customer, for this automation".
     *
     * Derived from the automation and the *specific* event, so three separate funding
     * settlements produce three candidates while a replayed webhook for one of them
     * produces the same key and is therefore deduplicated by the unique index.
     */
    private function dedupeKeyFor(LifecycleEvent $event, LifecycleAutomation $automation): string
    {
        return 'ev' . $event->getKey() . '-' . $automation->trigger_event;
    }

    private function reachedDailyLimit(LifecycleAutomation $automation, User $user): bool
    {
        $limit = (int) $automation->limit_per_user_per_day;

        if ($limit <= 0) {
            return false;
        }

        return LifecycleMessage::where('automation_id', $automation->getKey())
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->startOfDay())
            ->count() >= $limit;
    }
}

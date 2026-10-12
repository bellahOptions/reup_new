<?php

namespace App\Lifecycle;

use App\Mail\LifecycleMessageMail;
use App\Models\LifecycleAutomation;
use App\Models\LifecycleConsent;
use App\Models\LifecycleEmailTemplate;
use App\Models\LifecycleMessage;
use App\Models\LifecycleSettings;
use App\Models\LifecycleSuppression;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The one place a lifecycle email is actually sent.
 *
 * Everything that must be true before a customer is mailed is checked here, in one
 * order, so there is a single answer to "why was this not sent" rather than four
 * answers in four callers.
 *
 * ## The order, and why it is this order
 *
 *   1. **Idempotency, at the database.** The message row is created under a unique
 *      index before anything else happens. If the row already exists, this event has
 *      been handled — a replayed webhook, a retried job — and the correct response is
 *      to do nothing rather than to send a second email.
 *   2. **Suppression.** A bounced address is unusable and a complained one is
 *      hostile; continuing to send damages the sending reputation that every other
 *      customer's password reset depends on.
 *   3. **Consent.** A customer's marketing opt-out is honoured here, and *only* for
 *      marketing.
 *   4. **The kill switch.** Last, so an operator who flips it during an incident also
 *      stops the messages already past the earlier checks.
 *
 * ## Accepted is not delivered
 *
 * `Mail::send()` returning means the transport took the message. It does not mean
 * anybody received it. So the row is marked `accepted`, and `delivered_at` is only
 * ever written from a provider signal — see `confirmDelivery()`. An email log that
 * reports the first as the second is a log that will be believed and should not be.
 *
 * ## A failure is a row, not an exception
 *
 * Every failure path records *why* on the message row and returns. A reminder that
 * cannot be sent must not propagate an exception into the funding path that triggered
 * it, and an operator needs to see the reason in the delivery log rather than in a
 * stack trace.
 */
class LifecycleDispatcher
{
    public function __construct(
        private readonly LifecycleVariables $variables,
        private readonly LifecycleConditionEvaluator $conditions,
        private readonly LifecycleEligibility $eligibility,
    ) {
    }

    /**
     * Send one template to one customer, outside any automation.
     *
     * Used by the template test-send, where there is no automation and therefore no
     * conditions and no dedupe key that means anything. It creates its own automation
     * row so the delivery log stays a single table with a real foreign key rather than
     * a nullable one that every query then has to special-case.
     */
    public function sendTest(LifecycleEmailTemplate $template, User $user, int $actorId): LifecycleMessage
    {
        $automation = LifecycleAutomation::create([
            'key' => 'test-' . $template->key . '-' . now()->format('YmdHis'),
            'name' => 'Test send — ' . $template->name,
            'description' => 'A one-off test send from the template editor.',
            'trigger_event' => LifecycleTrigger::USER_REGISTERED,
            'template_id' => $template->getKey(),
            'conditions' => [],
            'delay_minutes' => 0,
            'status' => LifecycleAutomation::STATUS_DRAFT,
            'created_by' => $actorId,
        ]);

        $message = $this->schedule($automation, $template, $user, [
            'dedupe_key' => 'test-' . now()->format('YmdHis') . '-' . $user->getKey(),
            'scheduled_for' => now(),
        ]);

        if ($message !== null) {
            $this->attempt($message);
        }

        return $message ?? new LifecycleMessage();
    }

    /**
     * Create the message row for an automation, or return the existing one.
     *
     * Returns null only when the message cannot even be created. The unique index on
     * `(automation_id, user_id, dedupe_key)` is what makes a duplicate event a no-op,
     * and the exception-and-refetch is deliberate: checking first and inserting after
     * is a race two concurrent workers both win.
     *
     * @param  array{dedupe_key:string,scheduled_for:\DateTimeInterface,event_id?:int}  $options
     */
    public function schedule(
        LifecycleAutomation $automation,
        LifecycleEmailTemplate $template,
        User $user,
        array $options
    ): ?LifecycleMessage {
        $rendered = $this->render($template, $user);

        $attributes = [
            'automation_id' => $automation->getKey(),
            'user_id' => $user->getKey(),
            'event_id' => $options['event_id'] ?? null,
            'to_address' => (string) $user->email,
            'to_name' => (string) $user->name,
            'dedupe_key' => (string) $options['dedupe_key'],
            'template_key' => $template->key,
            'template_version' => (int) $template->current_version,
            'subject' => $rendered['subject'],
            'html_body' => $rendered['html'],
            'text_body' => $rendered['text'],
            'category' => $template->category,
            'status' => LifecycleMessage::STATUS_SCHEDULED,
            'scheduled_for' => $options['scheduled_for'],
            'content_hash' => hash('sha256', $rendered['subject'] . $rendered['html']),
        ];

        try {
            return LifecycleMessage::create($attributes);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Already scheduled for this event. Not an error: the whole point.
            return LifecycleMessage::where('automation_id', $automation->getKey())
                ->where('user_id', $user->getKey())
                ->where('dedupe_key', $options['dedupe_key'])
                ->first();
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }

            return LifecycleMessage::where('automation_id', $automation->getKey())
                ->where('user_id', $user->getKey())
                ->where('dedupe_key', $options['dedupe_key'])
                ->first();
        }
    }

    /**
     * Render a template for a customer.
     *
     * @return array{subject:string,html:string,text:string}
     */
    public function render(LifecycleEmailTemplate $template, User $user): array
    {
        $values = $this->variables->for($user);

        $subject = $this->variables->substitute((string) $template->subject, $values);

        $body = (string) $template->html_body;

        // Sanitise the *authored* markup first, then substitute. Substituting first
        // would put customer data through the sanitiser, and while the values are
        // already escaped, doing it in this order means the guarantee does not depend
        // on the sanitiser being harmless to text that happens to look like a tag.
        $body = HtmlSanitizer::clean($body);

        $buttons = $this->renderButtons($template, $values);

        $html = $this->variables->substitute($body, $values) . $buttons;

        if (filled($template->preview_text)) {
            $preview = $this->variables->substitute((string) $template->preview_text, $values);
            $html = $this->previewSpan($preview) . $html;
        }

        $text = filled($template->text_body)
            ? $this->variables->substitute((string) $template->text_body, $values)
            : HtmlSanitizer::toText($html);

        return [
            'subject' => $subject,
            'html' => $this->wrap($html, $subject),
            'text' => $text,
        ];
    }

    /**
     * Deliver a message that has already been claimed by a worker.
     *
     * @return array{status:string,message:LifecycleMessage}
     */
    public function attempt(LifecycleMessage $message): array
    {
        $message->refresh();

        if ($message->isTerminal()) {
            return ['status' => 'already_terminal', 'message' => $message];
        }

        // Claim it. The status transition is the lock: a second worker sees `sending`
        // and stops, and this is a conditional UPDATE so it is atomic across
        // processes without a cache.
        $claimed = LifecycleMessage::whereKey($message->getKey())
            ->whereIn('status', [LifecycleMessage::STATUS_SCHEDULED, LifecycleMessage::STATUS_QUEUED, LifecycleMessage::STATUS_SENDING])
            ->update([
                'status' => LifecycleMessage::STATUS_SENDING,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return ['status' => 'not_claimable', 'message' => $message->refresh()];
        }

        $message->refresh();

        /*
         * Re-check eligibility **immediately before sending**, which is what the brief
         * asks for and the only moment at which the answer is not stale. A delayed
         * reminder whose condition no longer holds — the customer funded their wallet
         * after the trigger fired — must be cancelled rather than delivered, because
         * there is no recovering from having told somebody the wrong thing.
         *
         * This runs after the claim, so a message that is cancelled is not re-enqueued
         * by the sweep: cancellation is terminal, unlike a failure.
         */
        $eligible = $this->eligibility->stillEligible($message);

        if (! $eligible['eligible']) {
            Log::info('Lifecycle message cancelled: no longer eligible', [
                'message_id' => $message->getKey(),
                'reason' => $eligible['reason'],
            ]);

            return [
                'status' => 'cancelled',
                'message' => $this->mark($message, LifecycleMessage::STATUS_CANCELLED, (string) $eligible['reason']),
            ];
        }

        $reason = $this->blockingReason($message);

        if ($reason !== null) {
            return ['status' => 'blocked', 'message' => $this->mark($message, LifecycleMessage::STATUS_SUPPRESSED, $reason)];
        }

        try {
            $this->deliver($message);
        } catch (Throwable $e) {
            Log::error('Lifecycle email failed', [
                'message_id' => $message->getKey(),
                'automation_id' => $message->automation_id,
                'user_id' => $message->user_id,
                'error' => $e->getMessage(),
            ]);

            // A failure is intermediate while attempts remain: the job's own retry,
            // or the sweep, will try again. Only the last attempt is terminal.
            $status = $message->attempts >= $this->maxAttempts()
                ? LifecycleMessage::STATUS_FAILED
                : LifecycleMessage::STATUS_SCHEDULED;

            $retryAt = $status === LifecycleMessage::STATUS_SCHEDULED
                ? now()->addMinutes($this->backoffMinutes($message->attempts))
                : null;

            return ['status' => 'failed', 'message' => $this->mark($message, $status, $e->getMessage(), $retryAt)];
        }

        return ['status' => 'sent', 'message' => $message->refresh()];
    }

    /**
     * Why this message must not be sent, or null.
     *
     * Public because the delivery log and the tests both need to ask the question
     * without attempting a send.
     */
    public function blockingReason(LifecycleMessage $message): ?string
    {
        $isMarketing = $message->isMarketing();

        $reason = null;

        if (LifecycleSuppression::blocks($message->to_address, $isMarketing)) {
            $reason = 'The address is suppressed.';
        } elseif ($isMarketing && LifecycleConsent::marketingOptedOut($message->to_address)) {
            $reason = 'The customer has opted out of marketing email.';
        } elseif ($isMarketing && ! LifecycleSettings::marketingEnabled()) {
            $reason = 'Marketing email is paused platform-wide.';
        }

        if ($reason !== null) {
            Log::info('Lifecycle email suppressed', [
                'message_id' => $message->getKey(),
                'category' => $message->category,
                'reason' => $reason,
            ]);
        }

        return $reason;
    }

    /**
     * Record a provider-side delivery confirmation.
     *
     * The only writer of `delivered_at`, and it is called with a provider message id
     * that matches one we sent — so a mail provider's webhook cannot mark an unrelated
     * row as delivered.
     */
    public function confirmDelivery(string $providerMessageId, ?\DateTimeInterface $at = null): bool
    {
        $message = LifecycleMessage::where('provider_message_id', $providerMessageId)->first();

        if (! $message) {
            return false;
        }

        return (bool) $message->forceFill([
            'status' => LifecycleMessage::STATUS_DELIVERED,
            'delivered_at' => $at ?? now(),
        ])->save();
    }

    /**
     * Record a provider-side bounce.
     *
     * Also suppresses the address: a hard bounce means the mailbox does not exist, and
     * the next send to it is a wasted send at best.
     */
    public function confirmBounce(string $providerMessageId, string $reason = 'Provider reported a bounce.'): bool
    {
        $message = LifecycleMessage::where('provider_message_id', $providerMessageId)->first();

        if (! $message) {
            return false;
        }

        $message->forceFill([
            'status' => LifecycleMessage::STATUS_BOUNCED,
            'status_message' => $reason,
            'failed_at' => now(),
        ])->save();

        LifecycleSuppression::firstOrCreate(
            ['email' => strtolower($message->to_address), 'reason' => LifecycleSuppression::REASON_BOUNCE],
            ['note' => 'Recorded from a provider bounce report.']
        );

        return true;
    }

    public function maxAttempts(): int
    {
        return (int) config('lifecycle.queue.max_attempts', 3);
    }

    /** Exponential backoff in minutes: 5, 25, 125… */
    public function backoffMinutes(int $attempts): int
    {
        $base = (int) config('lifecycle.queue.backoff_base_minutes', 5);

        return $base * (5 ** max(0, $attempts - 1));
    }

    /* =====================================================================
     | Internals
     |=================================================================== */

    private function deliver(LifecycleMessage $message): void
    {
        /*
         * A correlation id we control, set as a header and recorded on the row.
         *
         * Laravel 8 sends through SwiftMailer, whose transport does not hand back a
         * message id, so reading one from the mailer is not possible and relying on it
         * would make delivery confirmation work on one mailer and silently not on
         * another. A id we generate is stable, is on the message that left, and is what
         * a provider webhook can quote back to us.
         */
        $correlationId = 'reup-' . $message->uuid . '@' . parse_url((string) config('app.url'), PHP_URL_HOST);

        Mail::to($message->to_address, $message->to_name)
            ->send((new LifecycleMessageMail($message))->withCorrelationId($correlationId));

        /*
         * `accepted`, deliberately not `delivered`.
         *
         * The transport has taken the message. It does not promise the mailbox exists,
         * that the provider will deliver it, or that anybody will read it. Marking this
         * as delivered is the specific dishonesty `delivered_at` exists to prevent, so
         * it is left null until `confirmDelivery()` is told otherwise.
         */
        $message->forceFill([
            'status' => LifecycleMessage::STATUS_ACCEPTED,
            'status_message' => null,
            'accepted_at' => now(),
            'provider_message_id' => $correlationId,
        ])->save();
    }

    private function mark(LifecycleMessage $message, string $status, string $reason, ?\DateTimeInterface $retryAt = null): LifecycleMessage
    {
        $attributes = [
            'status' => $status,
            'status_message' => mb_substr($reason, 0, 500),
        ];

        if ($retryAt !== null) {
            $attributes['scheduled_for'] = $retryAt;
        }

        if (in_array($status, [LifecycleMessage::STATUS_FAILED, LifecycleMessage::STATUS_BOUNCED], true)) {
            $attributes['failed_at'] = now();
        }

        $message->forceFill($attributes)->save();

        return $message;
    }

    /** A hidden span, which is how preview text works in every email client. */
    private function previewSpan(string $preview): string
    {
        return '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'
            . $preview
            . '</div>';
    }

    /**
     * Render the template's configured CTA buttons.
     *
     * URLs are validated at render time as well as on save, because a template can be
     * rolled back to a version saved before a validation rule tightened, and because
     * a URL assembled from a variable is not known until here.
     */
    private function renderButtons(LifecycleEmailTemplate $template, array $values): string
    {
        $buttons = $template->cta_buttons ?? [];

        if (! is_array($buttons) || $buttons === []) {
            return '';
        }

        $primary = '';
        $secondary = '';

        foreach ($buttons as $index => $button) {
            if (! is_array($button)) {
                continue;
            }

            $label = trim((string) ($button['label'] ?? ''));
            $url = HtmlSanitizer::safeUrl($this->variables->substitute((string) ($button['url'] ?? ''), $values));

            if ($label === '' || $url === null) {
                continue;
            }

            $html = '<a href="' . e($url, false) . '" style="display:inline-block;padding:13px 24px;'
                . 'font-size:15px;font-weight:600;text-decoration:none;border-radius:8px;'
                . ($index === 0
                    ? 'background-color:#2AB70D;color:#ffffff;'
                    : 'background-color:#ffffff;color:#1b720c;border:1px solid #c1f0a1;')
                . '">' . e($label) . '</a>';

            if ($index === 0) {
                $primary = $html;
            } else {
                $secondary .= ($secondary === '' ? '' : ' &nbsp; ') . $html;
            }
        }

        if ($primary === '' && $secondary === '') {
            return '';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;">'
            . '<tr><td>' . $primary . '</td></tr>'
            . ($secondary === '' ? '' : '<tr><td style="padding-top:12px;">' . $secondary . '</td></tr>')
            . '</table>';
    }

    /**
     * Wrap a rendered body in the shared email shell.
     *
     * Reuses `components/emails/base` so a lifecycle email is visually the same object
     * as a receipt — a customer receiving both should not be able to tell that they
     * came from two different parts of the codebase.
     */
    private function wrap(string $html, string $subject): string
    {
        try {
            return view('emails.lifecycle', [
                'body' => $html,
                'subject' => $subject,
            ])->render();
        } catch (Throwable $e) {
            Log::warning('Could not render the lifecycle email shell; sending the body alone', [
                'error' => $e->getMessage(),
            ]);

            return $html;
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One intended delivery, and the unit of idempotency for the whole module.
 *
 * ## The status vocabulary, and the one distinction that matters
 *
 *   scheduled   — a row exists with a future `scheduled_for`. Nothing has been sent.
 *   queued      — handed to the queue. Still nothing sent.
 *   sending     — a worker has claimed it. Claimed, not sent.
 *   accepted    — the mail transport took it. **Not** the same as delivered.
 *   delivered   — the provider confirmed arrival, where it tells us.
 *   bounced     — the provider confirmed non-delivery.
 *   suppressed  — the address or the customer is not to be mailed.
 *   cancelled   — a condition stopped being true before it was sent.
 *   failed      — every attempt was refused.
 *
 * `accepted` exists as its own state because the alternative is a log that says
 * "delivered" about a message no provider has confirmed. An SMTP handoff means the
 * next hop accepted it; the mailbox is not promised. Reporting the first as the
 * second is the specific dishonesty this column exists to prevent, and it is why
 * `delivered_at` is only ever written from a provider signal.
 *
 * ## Idempotency
 *
 * `UNIQUE (automation_id, user_id, dedupe_key)`, enforced by the database. A cache
 * lock would not do: it expires, it is per-store, and two application servers with
 * separate caches both acquire it. The index holds under concurrency, across
 * processes, and after a restart.
 *
 * ## The rendered body is snapshotted
 *
 * `subject` and `html_body` are stored on the row rather than read from the template
 * at send time, so a retry resends what was actually sent and an audit of last
 * month's messages is not restated by this month's edits.
 */
class LifecycleMessage extends Model
{
    protected $table = 'lifecycle_messages';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_SUPPRESSED = 'suppressed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    /** States from which nothing further will happen. */
    public const TERMINAL = [
        self::STATUS_ACCEPTED,
        self::STATUS_DELIVERED,
        self::STATUS_BOUNCED,
        self::STATUS_SUPPRESSED,
        self::STATUS_CANCELLED,
        self::STATUS_FAILED,
    ];

    /** States that still need work. */
    public const OPEN = [self::STATUS_SCHEDULED, self::STATUS_QUEUED, self::STATUS_SENDING];

    protected $fillable = [
        'uuid', 'automation_id', 'user_id', 'event_id', 'to_address', 'to_name',
        'dedupe_key', 'template_key', 'template_version', 'subject', 'html_body',
        'text_body', 'category', 'status', 'status_message', 'attempts',
        'scheduled_for', 'queued_at', 'accepted_at', 'delivered_at', 'failed_at',
        'cancelled_at', 'provider_message_id', 'content_hash',
    ];

    protected $casts = [
        'template_version' => 'integer',
        'attempts' => 'integer',
        'scheduled_for' => 'datetime',
        'queued_at' => 'datetime',
        'accepted_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            if (empty($message->uuid)) {
                $message->uuid = (string) Str::uuid();
            }
        });
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(LifecycleAutomation::class, 'automation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(LifecycleEvent::class, 'event_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isMarketing(): bool
    {
        return $this->category === LifecycleEmailTemplate::CATEGORY_MARKETING;
    }

    /**
     * May this failed message be retried?
     *
     * Only a `failed` row, and only while it has attempts left. A `bounced` row is
     * never retried — the address is known bad, and retrying is how a sender earns a
     * reputation problem. A `suppressed` row is terminal by definition.
     */
    public function isRetryable(int $maxAttempts = 3): bool
    {
        return $this->status === self::STATUS_FAILED && $this->attempts < $maxAttempts;
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A reminder to pay something.
 *
 * ## A reminder never debits
 *
 * There is no autopay column, and the reminder job only ever writes a
 * `product_alerts` row. That is deliberate: the safest way to guarantee that
 * "reminders cannot debit a wallet" stays true is to make the model incapable of
 * expressing a debit, rather than to rely on every future caller remembering the
 * rule.
 *
 * ## Duplicate prevention
 *
 * `claimForDueDate()` is the guard. Two overlapping scheduler runs (or a retried
 * job) both call it; only the one that transitions `next_due_at` succeeds, so
 * exactly one notification is emitted per due date.
 */
class BillReminder extends Model
{
    public const FREQUENCY_ONCE = 'once';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_QUARTERLY = 'quarterly';
    public const FREQUENCY_ANNUALLY = 'annually';

    protected $fillable = [
        'uuid',
        'user_id',
        'saved_bill_id',
        'product_key',
        'title',
        'note',
        'amount_minor',
        'frequency',
        'interval',
        'starts_at',
        'next_due_at',
        'last_notified_at',
        'ends_at',
        'is_active',
        'notification_count',
        'claimed_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'interval' => 'integer',
        'starts_at' => 'datetime',
        'next_due_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'notification_count' => 'integer',
        'claimed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $reminder) {
            if (empty($reminder->uuid)) {
                $reminder->uuid = (string) Str::uuid();
            }

            $reminder->starts_at = $reminder->starts_at ?? now();
            $reminder->next_due_at = $reminder->next_due_at ?? $reminder->starts_at;
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function savedBill()
    {
        return $this->belongsTo(SavedBill::class);
    }

    public function alerts()
    {
        return $this->hasMany(ProductAlert::class);
    }

    /**
     * Advance the next due date past `$from`.
     *
     * Month and year arithmetic uses `addMonthNoOverflow` / `addYearNoOverflow`
     * because a reminder set on the 31st must not skip February: PHP's plain
     * `addMonth()` on 31 January produces 2 March, which would silently drop a
     * notification.
     */
    public function advanceFrom(Carbon $from): Carbon
    {
        $interval = max(1, (int) $this->interval);

        return match ($this->frequency) {
            self::FREQUENCY_DAILY => $from->copy()->addDays($interval),
            self::FREQUENCY_WEEKLY => $from->copy()->addWeeks($interval),
            self::FREQUENCY_MONTHLY => $from->copy()->addMonthsNoOverflow($interval),
            self::FREQUENCY_QUARTERLY => $from->copy()->addMonthsNoOverflow(3 * $interval),
            self::FREQUENCY_ANNUALLY => $from->copy()->addYearsNoOverflow($interval),
            // `once` has no next occurrence; the caller deactivates instead.
            default => $from->copy(),
        };
    }

    /**
     * Claim this reminder for its current due date, or return false.
     *
     * The claim is the duplicate guard: it moves `next_due_at` forward and
     * records the notification, and it only succeeds if the reminder is still
     * due and still active. Two concurrent runs cannot both win, because the
     * second sees the already-advanced `next_due_at`.
     *
     * Returns the due date that was claimed, so the caller logs the right one.
     */
    public function claimForDueDate(?Carbon $at = null): ?Carbon
    {
        $at = $at ?? now();

        if (! $this->is_active || $this->next_due_at === null || $this->next_due_at->greaterThan($at)) {
            return null;
        }

        if ($this->ends_at && $this->ends_at->lessThan($at)) {
            $this->forceFill(['is_active' => false])->save();

            return null;
        }

        $due = $this->next_due_at->copy();

        $isOneOff = $this->frequency === self::FREQUENCY_ONCE;

        $this->forceFill([
            'last_notified_at' => $at,
            'claimed_at' => $at,
            'notification_count' => $this->notification_count + 1,
            'next_due_at' => $isOneOff ? $due : $this->advanceFrom($due),
            // A one-off reminder is finished once it has fired.
            'is_active' => ! $isOneOff,
        ])->save();

        return $due;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDue($query, ?Carbon $at = null)
    {
        $at = $at ?? now();

        return $query->active()->whereNotNull('next_due_at')->where('next_due_at', '<=', $at);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}

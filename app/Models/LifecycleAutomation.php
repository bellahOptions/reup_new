<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * When a lifecycle email is sent.
 *
 * A trigger event, a condition tree, a delay, limits, an active window and a status.
 * Nothing executable: the conditions are data, interpreted by
 * `App\Lifecycle\LifecycleConditionEvaluator` against an allowlist of fields and
 * operators, and the template is referenced by key rather than embedded so one edit
 * does not have to be repeated per automation.
 */
class LifecycleAutomation extends Model
{
    protected $table = 'lifecycle_automations';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'key', 'name', 'description', 'trigger_event', 'template_id',
        'conditions', 'condition_logic', 'delay_minutes',
        'limit_per_run', 'limit_per_user_per_day', 'max_sends',
        'active_from', 'active_until', 'status', 'sent_count', 'created_by',
    ];

    protected $casts = [
        'conditions' => 'array',
        'delay_minutes' => 'integer',
        'limit_per_run' => 'integer',
        'limit_per_user_per_day' => 'integer',
        'max_sends' => 'integer',
        'sent_count' => 'integer',
        'active_from' => 'datetime',
        'active_until' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(LifecycleEmailTemplate::class, 'template_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LifecycleMessage::class, 'automation_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Is this automation allowed to run at this instant?
     *
     * The window and the cap are checked here rather than only at configuration time,
     * because both can pass while an automation is live: `active_until` arrives, and
     * `max_sends` is reached by traffic.
     *
     * @return string|null the reason it may not run, or null when it may
     */
    public function blockingReason(): ?string
    {
        if (! $this->isActive()) {
            return 'The automation is not active.';
        }

        if ($this->active_from && $this->active_from->isFuture()) {
            return 'The automation has not opened yet.';
        }

        if ($this->active_until && $this->active_until->isPast()) {
            return 'The automation has closed.';
        }

        if ($this->max_sends !== null && $this->sent_count >= $this->max_sends) {
            return 'The automation has reached its send cap.';
        }

        return null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain event, recorded durably before anything is scheduled from it.
 *
 * ## Why an outbox rather than a direct dispatch
 *
 * The trigger sites are inside the money paths — a registration, a settled funding, a
 * vended purchase, a paid referral. Those run inside database transactions and, for
 * funding, inside a row lock. Enqueueing a mail job directly from there means the job
 * can be picked up by a worker **before the transaction commits**, so the worker reads
 * a customer with no wallet, or a transaction that does not exist yet, and the email
 * that goes out is wrong or the job fails and the customer is never told.
 *
 * So the event is written *inside* the same transaction, in the same database, and a
 * separate process reads it afterwards. That is a transactional outbox: the event and
 * the thing that caused it either both commit or neither does, and a queue that is
 * down when the event happens delays the email instead of losing it.
 *
 * `(name, user_id, dedupe_key)` is unique, so the same domain event delivered twice —
 * a replayed webhook, a retried job — is one row.
 */
class LifecycleEvent extends Model
{
    protected $table = 'lifecycle_events';

    protected $fillable = ['name', 'user_id', 'payload', 'dedupe_key', 'occurred_at', 'processed_at'];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}

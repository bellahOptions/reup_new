<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reserved idempotency key.
 *
 * The UNIQUE index on `key` is the authority that stops a repeated financial
 * request from being processed twice. Application code inserts a row *before*
 * doing the work; a duplicate-key violation on that insert means somebody else
 * is already doing it (or has done it), and the request is answered from the
 * stored outcome instead of being executed again.
 *
 * A cache entry may sit in front of this as a fast path. It must never be
 * trusted as the final word: see `App\Services\SecurityService`.
 */
class IdempotencyKey extends Model
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'key',
        'scope',
        'provider',
        'request_hash',
        'user_id',
        'transaction_id',
        'status',
        'response_code',
        'response',
        'expires_at',
    ];

    protected $casts = [
        'response' => 'array',
        'expires_at' => 'datetime',
        'response_code' => 'integer',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transactions::class, 'transaction_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One provider API interaction.
 *
 * An order can have many of these: the initial purchase attempt, a
 * reconciliation poll, a retry after a confirmed non-fulfilment, a refund. That
 * history is what proves a retry was safe, so it is stored per interaction
 * rather than as a single "last response" on the order.
 *
 * ## What is never stored here
 *
 * Credentials, and sensitive delivery tokens. Sogo is explicit that a
 * `transaction.completed` webhook carries no gift card code, PIN or eSIM
 * activation data, and that those must be fetched separately — which is a
 * fortunate constraint, because it means the webhook payload logged here is
 * safe by construction. The payloads written by this model are passed through
 * `App\Providers\Support\PayloadRedactor` first, so a response that *does* carry
 * a code (a direct purchase response, or the follow-up fetch) is stored with the
 * token stripped.
 */
class ProviderTransaction extends Model
{
    public const OP_PURCHASE = 'purchase';
    public const OP_STATUS = 'status';
    public const OP_REFILL = 'refill';
    public const OP_CANCEL = 'cancel';
    public const OP_VERIFY = 'verify';
    public const OP_CATALOGUE = 'catalogue';
    public const OP_BALANCE = 'balance';

    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid',
        'service_order_id',
        'provider_id',
        'operation',
        'idempotency_key',
        'provider_reference',
        'status',
        'provider_status',
        'request_payload',
        'response_payload',
        'http_status',
        'duration_ms',
        'attempt',
        'rate_limited',
        'error_message',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
        'attempt' => 'integer',
        'rate_limited' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transaction) {
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }
        });
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function wasRateLimited(): bool
    {
        return $this->rate_limited || $this->http_status === 429;
    }
}

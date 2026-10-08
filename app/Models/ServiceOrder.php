<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One provider-backed order, and its canonical status.
 *
 * ## The status vocabulary
 *
 * Every provider maps its own statuses INTO this set. The distinctions that
 * carry money are:
 *
 *   * **UNKNOWN** — we sent the request and do not know the outcome. The order
 *     may have been fulfilled. It must never be refunded, retried or failed
 *     automatically; it must be reconciled first.
 *   * **PROCESSING / PENDING** — the provider accepted it and is working. Never
 *     retried either, for the same reason.
 *   * **RETRYABLE** — the provider stated, explicitly, that it did not fulfil
 *     the request. Only this state permits sending the order to another provider.
 *   * **PARTIAL** — some of the order was delivered. Refunding it in full would
 *     give away the delivered part; refunding nothing would keep money for goods
 *     not supplied. It is surfaced for a decision rather than auto-resolved.
 *
 * ## The idempotency key
 *
 * `provider_idempotency_key` is generated once, persisted, and reused verbatim
 * for every subsequent attempt at the *same* provider operation. Sogo binds a
 * key permanently to its transaction, so regenerating it while the outcome is
 * unknown is precisely how a customer is charged twice for one order.
 */
class ServiceOrder extends Model
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_RETRYABLE = 'RETRYABLE';
    public const STATUS_UNKNOWN = 'UNKNOWN';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_REFUNDED = 'REFUNDED';

    /** States that are settled and will not change on their own. */
    public const FINAL_STATUSES = [
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    /**
     * States where the provider may have fulfilled the order.
     *
     * An order in one of these must be reconciled before any retry, failover or
     * refund. This constant is the single place that rule is expressed.
     */
    public const UNRESOLVED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_UNKNOWN,
    ];

    /** States where the customer's money is still held against the order. */
    public const HOLDS_FUNDS_STATUSES = [
        self::STATUS_SUCCESS,
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_UNKNOWN,
        self::STATUS_PARTIAL,
    ];

    protected $fillable = [
        'uuid',
        'user_id',
        'transaction_id',
        'provider_id',
        'service_product_id',
        'provider_product_id',
        'product_key',
        'provider_name',
        'recipient',
        'quantity',
        'order_payload',
        'status',
        'provider_status',
        'provider_reference',
        'provider_idempotency_key',
        'status_message',
        'failure_reason',
        'reconcile_attempts',
        'last_reconciled_at',
        'next_reconcile_at',
        'submitted_at',
        'completed_at',
        'failed_at',
        'refunded_at',
    ];

    protected $casts = [
        'order_payload' => 'array',
        'quantity' => 'integer',
        'reconcile_attempts' => 'integer',
        'last_reconciled_at' => 'datetime',
        'next_reconcile_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }

            if (empty($order->provider_idempotency_key)) {
                $order->provider_idempotency_key = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transactions::class, 'transaction_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function serviceProduct()
    {
        return $this->belongsTo(ServiceProduct::class);
    }

    public function providerProduct()
    {
        return $this->belongsTo(ProviderProduct::class);
    }

    public function pricingSnapshot()
    {
        return $this->hasOne(PricingSnapshot::class);
    }

    public function profitRecord()
    {
        return $this->hasOne(ProfitRecord::class);
    }

    public function providerTransactions()
    {
        return $this->hasMany(ProviderTransaction::class)->orderBy('id');
    }

    /* =====================================================================
     | State predicates — the money-critical ones
     |=================================================================== */

    /**
     * Whether the outcome is known.
     *
     * False means: do not retry, do not fail over, do not refund. Reconcile.
     */
    public function outcomeIsKnown(): bool
    {
        return ! in_array($this->status, self::UNRESOLVED_STATUSES, true);
    }

    public function isUnknown(): bool
    {
        return $this->status === self::STATUS_UNKNOWN;
    }

    /**
     * Whether the provider explicitly said it did not fulfil the order.
     *
     * The ONLY state that permits another provider to be tried.
     */
    public function isSafelyRetryable(): bool
    {
        return $this->status === self::STATUS_RETRYABLE;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    public function holdsCustomerFunds(): bool
    {
        return in_array($this->status, self::HOLDS_FUNDS_STATUSES, true);
    }

    /** Whether this order is due to be reconciled now. */
    public function isDueForReconciliation(?\Illuminate\Support\Carbon $at = null): bool
    {
        $at = $at ?? now();

        if (! in_array($this->status, self::UNRESOLVED_STATUSES, true)) {
            return false;
        }

        return $this->next_reconcile_at === null || $this->next_reconcile_at->lessThanOrEqualTo($at);
    }

    /**
     * Schedule the next reconciliation with exponential backoff.
     *
     * A provider that is briefly slow should be re-asked quickly; one that has
     * been silent for an hour does not need polling every minute. The cap keeps
     * a genuinely stuck order on a slow but non-zero retry schedule rather than
     * backing off past the point anyone would notice.
     */
    public function scheduleReconciliation(int $baseSeconds = 60, int $capSeconds = 3600): void
    {
        $delay = min($capSeconds, $baseSeconds * (2 ** min($this->reconcile_attempts, 6)));

        $this->forceFill([
            'reconcile_attempts' => $this->reconcile_attempts + 1,
            'last_reconciled_at' => now(),
            'next_reconcile_at' => now()->addSeconds($delay),
        ])->save();
    }

    public function markProcessing(?string $providerStatus = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'provider_status' => $providerStatus,
            'submitted_at' => $this->submitted_at ?? now(),
            'next_reconcile_at' => now()->addMinute(),
        ])->save();
    }

    public function markSuccess(?string $providerStatus = null, ?string $providerReference = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCESS,
            'provider_status' => $providerStatus ?? $this->provider_status,
            'provider_reference' => $providerReference ?? $this->provider_reference,
            'completed_at' => now(),
            'next_reconcile_at' => null,
        ])->save();
    }

    public function markFailed(string $reason, ?string $providerStatus = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'provider_status' => $providerStatus ?? $this->provider_status,
            'failure_reason' => $reason,
            'failed_at' => now(),
            'next_reconcile_at' => null,
        ])->save();
    }

    public function markUnknown(string $reason, ?string $providerStatus = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_UNKNOWN,
            'provider_status' => $providerStatus ?? $this->provider_status,
            'status_message' => $reason,
            'next_reconcile_at' => now()->addMinute(),
        ])->save();
    }

    public function markRetryable(string $reason, ?string $providerStatus = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_RETRYABLE,
            'provider_status' => $providerStatus ?? $this->provider_status,
            'failure_reason' => $reason,
        ])->save();
    }

    public function money(): Money
    {
        return $this->pricingSnapshot
            ? Money::fromMinor((int) $this->pricingSnapshot->customer_price_minor)
            : Money::fromDatabase($this->transaction->total_amount ?? 0);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', self::UNRESOLVED_STATUSES);
    }

    public function scopeDueForReconciliation($query)
    {
        return $query->unresolved()
            ->where(function ($q) {
                $q->whereNull('next_reconcile_at')->orWhere('next_reconcile_at', '<=', now());
            });
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}

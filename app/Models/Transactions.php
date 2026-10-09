<?php

namespace App\Models;

use App\Models\Concerns\HasPricingSnapshot;
use App\Services\NetworkResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Transactions extends Model
{
    use HasFactory;
    use HasPricingSnapshot;

    protected $table = 'transactions';

    protected $fillable = [
        'user_id',
        'reference',
        'uuid',
        'type',
        'service_type',
        'description',
        'amount',
        'service_fee',
        'total_amount',
        'balance_before',
        'balance_after',
        'recipient',
        'provider',
        'network_code',
        'network_name',
        'plan_name',
        'plan_type',
        'payment_method',
        'payment_reference',
        'payment_status',
        'status',
        'status_message',
        'api_reference',
        'api_response',
        'meta',
        'paid_at',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'service_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'api_response' => 'array',
        'meta' => 'array',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $appends = [
        'formatted_amount',
        'formatted_total_amount',
        'service_type_badge',
        'status_badge',
    ];

    /**
     * Every transaction gets a UUID, and a display reference if the caller did
     * not supply one.
     *
     * Doing this in a `creating` hook rather than at each of the four call sites
     * means a transaction created by any future code path — including a console
     * command or a test — is still addressable by the reconciler. A null uuid
     * would silently drop that row out of every Paystack poll.
     */
    protected static function booted(): void
    {
        static::creating(function (self $transaction) {
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }

            if (empty($transaction->reference)) {
                $transaction->reference = self::generateReference();
            }
        });
    }

    /**
     * The identifier handed to the payment gateway.
     *
     * Falls back to `reference` for rows created before `uuid` existed, so the
     * callback path keeps working for any in-flight transaction across the
     * deploy.
     */
    public function gatewayReference(): string
    {
        return (string) ($this->uuid ?: $this->reference);
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Accessors
    public function getFormattedAmountAttribute()
    {
        return '₦' . number_format($this->amount, 2);
    }

    public function getFormattedTotalAmountAttribute()
    {
        return '₦' . number_format($this->total_amount, 2);
    }

    /**
     * Badge classes for the service type.
     *
     * Returns design-system badge variants rather than raw Tailwind shades, so
     * the badges follow the brand palette and stay consistent with every other
     * status chip in the product. Views render these as `badge {{ ... }}`.
     */
    public function getServiceTypeBadgeAttribute(): string
    {
        return match ($this->service_type) {
            'airtime' => 'badge-primary',
            'data' => 'badge-info',
            'funding' => 'badge-success',
            'cable-tv' => 'badge-warning',
            'electricity' => 'badge-warning',
            'exam' => 'badge-neutral',
            'refund' => 'badge-success',
            'transfer' => 'badge-neutral',
            'manual_adjustment' => 'badge-neutral',
            default => 'badge-neutral',
        };
    }

    /**
     * Badge classes for the transaction status.
     */
    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'success' => 'badge-success',
            'processing' => 'badge-info',
            'pending' => 'badge-warning',
            'verifying' => 'badge-warning',
            'failed' => 'badge-destructive',
            'cancelled' => 'badge-neutral',
            default => 'badge-neutral',
        };
    }

    // Methods - Make this STATIC
    /**
     * The mobile network shown to a customer for this transaction.
     *
     * ## Why this is not `provider`
     *
     * `provider` is the upstream API that served the request (ClubKonnect,
     * Pairgate). It is *not* the customer's mobile operator, and a receipt that
     * printed it under "Network" told the customer nothing true about where their
     * airtime went. This accessor is the single place the customer-facing network
     * is decided, so no template has to reason about it.
     *
     * Resolution order:
     *
     *   1. `network_name`, written when the purchase was made — authoritative and
     *      stable, so a historical receipt never changes when configuration does;
     *   2. the legacy `meta.network_name`, then `meta.network_code`, for rows
     *      created before the column existed;
     *   3. the neutral fallback.
     *
     * The provider name is never an acceptable answer at any step. If a legacy row
     * has a provider label sitting in a network-shaped place, `NetworkResolver`
     * recognises it and the fallback is used instead.
     */
    public function getNetworkDisplayAttribute(): string
    {
        $resolved = $this->resolveNetworkName();

        return $resolved ?? NetworkResolver::unavailableLabel();
    }

    /**
     * The canonical network key for this transaction, or null when unresolved.
     *
     * Used by pricing context, per-network reporting and the admin console.
     */
    public function getNetworkKeyAttribute(): ?string
    {
        if (filled($this->network_code)) {
            return (string) $this->network_code;
        }

        $meta = $this->meta ?? [];

        return NetworkResolver::key(
            $meta['network_code'] ?? $meta['network'] ?? null,
            $meta['requested_provider'] ?? null,
        );
    }

    /**
     * Whether the network shown for this transaction is a real resolution rather
     * than the neutral fallback.
     *
     * Lets a view omit the row entirely instead of printing "Network unavailable"
     * on a product that never had a network (a refund, a wallet top-up).
     */
    public function hasResolvedNetwork(): bool
    {
        return $this->resolveNetworkName() !== null;
    }

    private function resolveNetworkName(): ?string
    {
        /*
         * 1. The persisted label. Checked for a provider name first, because the
         *    defect being fixed here was a provider name reaching this position and
         *    a stored value is exactly what a future migration or manual fix might
         *    have written.
         */
        if (filled($this->network_name) && ! NetworkResolver::isProviderName($this->network_name)) {
            return (string) $this->network_name;
        }

        $meta = $this->meta ?? [];

        // 2a. A legacy row that recorded the resolved name in meta.
        $metaName = $meta['network_name'] ?? null;

        if (filled($metaName) && ! NetworkResolver::isProviderName($metaName)) {
            return (string) $metaName;
        }

        // 2b. A legacy row that recorded only the provider's code.
        $key = NetworkResolver::key(
            $meta['network_code'] ?? $meta['network'] ?? null,
            $meta['requested_provider'] ?? null,
        );

        if ($key !== null) {
            return NetworkResolver::labelFor($key);
        }

        /*
         * 3. Nothing resolvable. Note that `provider` is never consulted — that is
         *    the fix, not an oversight.
         */
        return null;
    }

    /**
     * The provider cost, customer price and gross profit for this transaction,
     * for administrative interfaces only.
     *
     * Read from the immutable pricing snapshot rather than recomputed, so a later
     * pricing change cannot restate a historical margin. Returns null when no
     * snapshot exists (a wallet top-up, a refund, or a purchase made before this
     * pricing path existed) — which is honest, rather than reporting zero profit
     * as though it were measured.
     *
     * @return array<string,mixed>|null
     */
    public function getPricingBreakdownAttribute(): ?array
    {
        return $this->pricingAnalysis();
    }

    // Instance methods
    public function markAsProcessing()
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsSuccess($apiReference = null, $apiResponse = null)
    {
        $this->update([
            'status' => 'success',
            'api_reference' => $apiReference,
            'api_response' => $apiResponse,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed($message = null)
    {
        $this->update([
            'status' => 'failed',
            'status_message' => $message,
        ]);
    }

    public function updatePaymentStatus($status, $reference = null)
    {
        $this->update([
            'payment_status' => $status,
            'payment_reference' => $reference ?? $this->payment_reference,
            'paid_at' => $status === 'success' ? now() : $this->paid_at,
        ]);
    }

    /**
     * Unique display reference.
     *
     * Kept on the model so the `creating` hook has no dependency on
     * WalletService, which would create a circular service dependency for any
     * caller that builds a transaction before a wallet service is available.
     */
    public static function generateReference($prefix = 'TXN')
    {
        do {
            $reference = $prefix . '-' . now()->format('ymd') . '-' . strtoupper(Str::random(12));
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }

    // Scope queries
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Match a transaction by any of its identifiers.
     *
     * Three columns can legitimately hold "the reference" depending on where the
     * value came from: `reference` (what the customer sees), `uuid` (what the
     * gateway was given), and `api_reference` (what the gateway echoed back).
     * Callbacks, webhooks and status polls all arrive holding one of the three,
     * and which one depends on the code path that produced it.
     *
     * Centralised here because scattering the three-way OR across a dozen call
     * sites is exactly how one of them ends up missing a column — which is what
     * happened when `uuid` was introduced, and would have silently broken the
     * funding callback.
     */
    public function scopeWhereReference($query, ?string $reference)
    {
        if (blank($reference)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($reference) {
            $q->where('reference', $reference)
                ->orWhere('uuid', $reference)
                ->orWhere('api_reference', $reference);
        });
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecent($query, $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
}
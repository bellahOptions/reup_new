<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One immutable movement of wallet value.
 *
 * Rows are written by `App\Services\WalletService` inside the same database
 * transaction as the balance change, so a balance can never move without a
 * ledger row recording it.
 *
 * ## Immutability
 *
 * This is an append-only table. The model makes that structural rather than
 * advisory:
 *
 *   * `UPDATED_AT` is disabled — there is no `updated_at` column;
 *   * `save()` refuses to update an existing row;
 *   * `delete()` refuses outright;
 *   * there is no route, controller or command anywhere that edits a ledger row.
 *
 * A correction is a new, compensating row — never an edit. Anything else
 * destroys the audit trail the ledger exists to provide.
 */
class WalletLedger extends Model
{
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    public const ENTRY_CREDIT = 'CREDIT';
    public const ENTRY_DEBIT = 'DEBIT';
    public const ENTRY_REFUND = 'REFUND';
    public const ENTRY_REVERSAL = 'REVERSAL';
    public const ENTRY_ADMIN_ADJUSTMENT = 'ADMIN_ADJUSTMENT';

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    protected $table = 'wallet_ledger';

    protected $fillable = [
        'uuid',
        'wallet_id',
        'user_id',
        'transaction_id',
        'amount',
        'currency',
        'direction',
        'entry_type',
        'balance_before',
        'balance_after',
        'reference',
        'description',
        'metadata',
        'actor_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (empty($entry->uuid)) {
                $entry->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * Refuse updates. An append-only table with an update path is not
     * append-only.
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new RuntimeException(
                'Wallet ledger entries are immutable. Record a compensating entry instead of editing entry '
                . $this->getKey() . '.'
            );
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new RuntimeException(
            'Wallet ledger entries are immutable and cannot be deleted (entry ' . ($this->getKey() ?? 'new') . ').'
        );
    }

    /* =====================================================================
     | Relationships
     |=================================================================== */

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transactions::class, 'transaction_id');
    }

    /* =====================================================================
     | Helpers
     |=================================================================== */

    public function amountMoney(): Money
    {
        return Money::fromDatabase($this->amount);
    }

    public function balanceBeforeMoney(): Money
    {
        return Money::fromDatabase($this->balance_before);
    }

    public function balanceAfterMoney(): Money
    {
        return Money::fromDatabase($this->balance_after);
    }

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    /**
     * The signed movement this entry represents.
     *
     * Credits are positive, debits negative — the form a statement wants, and
     * the form the invariant check uses.
     */
    public function signedAmount(): Money
    {
        $amount = $this->amountMoney();

        return $this->isCredit() ? $amount : $amount->negated();
    }

    public function getFormattedAmountAttribute(): string
    {
        return ($this->isCredit() ? '+' : '-') . $this->amountMoney()->format();
    }
}

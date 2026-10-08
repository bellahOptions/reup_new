<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * `balance` and the aggregate columns are listed because the `User::created`
     * hook and `WalletService::forUser()` construct a wallet with them.
     *
     * That makes `Wallet::create($request->all())` — or any `fill()` from
     * unvalidated input — a balance-forging primitive, so it must never be done.
     * Every mutation goes through `App\Services\WalletService`, which is the only
     * code that assigns these columns after creation; there is no controller that
     * mass-assigns a wallet.
     */
    protected $fillable = [
        'user_id',
        'balance',
        'pending_balance',
        'total_funded',
        'total_spent',  // Changed from total_withdrawn
        'transaction_count'
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'pending_balance' => 'decimal:2',
        'total_funded' => 'decimal:2',
        'total_spent' => 'decimal:2',
        'transaction_count' => 'integer',
    ];

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship with Transactions (FIXED - was Transaction, should be Transactions)
    public function transactions()
    {
        return $this->hasMany(Transactions::class, 'user_id', 'user_id');
    }

    // Helper methods
    public function getFormattedBalanceAttribute()
    {
        return '₦' . number_format($this->balance, 2);
    }

    public function getFormattedTotalFundedAttribute()
    {
        return '₦' . number_format($this->total_funded, 2);
    }

    public function getFormattedTotalSpentAttribute()
    {
        return '₦' . number_format($this->total_spent, 2);
    }

    public function getTotalAvailableAttribute()
    {
        return $this->balance + $this->pending_balance;
    }

    /**
     * The current balance as an exact value object.
     *
     * Prefer this over reading `$wallet->balance` when the value is going to be
     * compared, added or sent to a gateway. The raw attribute is a string from
     * the decimal column and hand-rolling `(float)` around it is how the
     * rounding bugs started.
     */
    public function balanceMoney(): \App\Support\Money
    {
        return \App\Support\Money::fromDatabase($this->balance);
    }

    /* =====================================================================
     | Ledger
     |=================================================================== */

    /** The immutable record of every movement against this wallet. */
    public function ledger()
    {
        return $this->hasMany(WalletLedger::class)->orderBy('id');
    }

    /* =====================================================================
     | Mutations are not available here
     |====================================================================
     | These methods previously incremented the balance directly, with no lock,
     | no transaction, no balance check worth the name (a read-then-write race)
     | and no ledger row. Four controllers and an admin screen called them, and
     | balances ended up disagreeing with the transactions that produced them.
     |
     | `App\Services\WalletService` is the only approved mutation path: it locks
     | the row, validates the balance, writes the matching immutable ledger entry
     | and runs inside a transaction. These methods are kept as named, throwing
     | stubs rather than deleted, so that any call site still using them fails
     | loudly at the point of the mistake instead of silently corrupting a
     | balance. Delete them once no caller remains.
     */

    public function credit($amount, $description = null)
    {
        throw new \RuntimeException(
            'Wallet::credit() is not an approved mutation path — it bypasses the row lock, the balance check '
            . 'and the wallet ledger. Use App\Services\WalletService::credit() instead.'
        );
    }

    public function debit($amount, $description = null)
    {
        throw new \RuntimeException(
            'Wallet::debit() is not an approved mutation path — it bypasses the row lock, the balance check '
            . 'and the wallet ledger. Use App\Services\WalletService::debit() instead.'
        );
    }

    public function hasBalance($amount)
    {
        return \App\Support\Money::fromDatabase($this->balance)
            ->greaterThanOrEqual(\App\Support\Money::fromNaira($amount));
    }
}
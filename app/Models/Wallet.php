<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

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

    // Wallet operations
    public function credit($amount, $description = null)
    {
        $this->increment('balance', $amount);
        $this->increment('total_funded', $amount);
        $this->increment('transaction_count');
        
        return $this->fresh();
    }

    public function debit($amount, $description = null)
    {
        if ($this->balance < $amount) {
            throw new \Exception('Insufficient balance');
        }
        
        $this->decrement('balance', $amount);
        $this->increment('total_spent', $amount);
        $this->increment('transaction_count');
        
        return $this->fresh();
    }

    public function hasBalance($amount)
    {
        return $this->balance >= $amount;
    }
}
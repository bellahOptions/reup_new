<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid referral reward.
 *
 * @property int    $referrer_id
 * @property int    $referred_id
 * @property int    $transaction_id
 * @property string $reward_amount
 * @property string $qualifying_amount
 */
class Referral extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'transaction_id',
        'reward_amount',
        'qualifying_amount',
        'reward_reference',
        'paid_at',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'qualifying_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transactions::class, 'transaction_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A bill account a customer has saved.
 *
 * ## Ownership
 *
 * Every access path must scope to the owner. `maskedIdentifier()` exists so the
 * list view can render something useful without the full account number, but the
 * masking is a *display* convenience and not a security control: the row is
 * protected by the `user_id` scope, not by hiding the identifier.
 */
class SavedBill extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'product_key',
        'service_product_id',
        'label',
        'identifier',
        'masked_identifier',
        'attributes',
        'verified_name',
        'verified_at',
        'use_count',
        'last_used_at',
        'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'use_count' => 'integer',
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * The identifier is never serialised by default.
     *
     * A saved meter number is not a credential, but it is personal data and it is
     * the thing an attacker would want from another user's list. It is exposed
     * only by an explicit owner-scoped action that needs it to pre-fill a form.
     */
    protected $hidden = [
        'identifier',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $bill) {
            if (empty($bill->uuid)) {
                $bill->uuid = (string) Str::uuid();
            }

            if (empty($bill->masked_identifier) && ! empty($bill->identifier)) {
                $bill->masked_identifier = self::mask($bill->identifier);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceProduct()
    {
        return $this->belongsTo(ServiceProduct::class);
    }

    public function reminders()
    {
        return $this->hasMany(BillReminder::class);
    }

    /**
     * Mask an account identifier, keeping only the last four characters.
     *
     * A short identifier is masked entirely rather than shown, because showing
     * four of six characters is barely a mask at all.
     */
    public static function mask(string $identifier): string
    {
        $length = strlen($identifier);

        if ($length <= 6) {
            return str_repeat('*', max(1, $length));
        }

        return str_repeat('*', $length - 4) . substr($identifier, -4);
    }

    public function recordUse(): void
    {
        $this->forceFill([
            'use_count' => $this->use_count + 1,
            'last_used_at' => now(),
        ])->save();
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}

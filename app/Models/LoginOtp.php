<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use sign-in code.
 *
 * @property int         $user_id
 * @property string      $code_hash
 * @property string      $purpose
 * @property \Carbon\Carbon $expires_at
 * @property int         $attempts
 * @property \Carbon\Carbon|null $consumed_at
 */
class LoginOtp extends Model
{
    use HasFactory;

    /**
     * Eloquent would pluralise the class name to `login_otps`, which reads
     * badly and does not match the migration's intent. Set explicitly.
     */
    protected $table = 'auth_one_time_codes';

    /**
     * Note the absence of `code_hash` from any serialisation: like the
     * transaction PIN on User, a hash must never reach a JSON response.
     */
    protected $hidden = [
        'code_hash',
    ];

    protected $fillable = [
        'user_id',
        'code_hash',
        'purpose',
        'expires_at',
        'attempts',
        'consumed_at',
        'requested_ip',
        'requested_user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Usable = not yet consumed and not past its expiry. */
    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}

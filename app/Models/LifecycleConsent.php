<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer's marketing consent, keyed by email.
 *
 * ## Why email and not `user_id`
 *
 * Consent is attached to the *person*, and the person is identified to a mail
 * provider by their address. A customer who changes their email address has, in every
 * deliverability sense, become a new recipient — carrying an opt-out across would be
 * recording a decision about a mailbox that is no longer in use. Keying on the address
 * also means an unsubscribe can be honoured for an address that no longer belongs to
 * any account, which is the state a bounce or a complaint usually arrives in.
 *
 * ## Marketing only
 *
 * Nothing here gates a receipt, a password reset, a security alert or a funding
 * confirmation. Those are not consent-based: the customer needs them to use the
 * account they paid for, and pausing marketing must never silence them. That
 * separation is enforced at the point of sending
 * ({@see \App\Lifecycle\LifecycleDispatcher}), not by convention here.
 */
class LifecycleConsent extends Model
{
    protected $table = 'lifecycle_consents';

    protected $fillable = ['email', 'marketing_opt_in', 'opted_out_at', 'source', 'ip_address'];

    protected $casts = [
        'marketing_opt_in' => 'boolean',
        'opted_out_at' => 'datetime',
    ];

    /** Has this address opted out of marketing? Absence of a row means no decision. */
    public static function marketingOptedOut(string $email): bool
    {
        $consent = static::where('email', strtolower(trim($email)))->first();

        return $consent !== null && ! $consent->marketing_opt_in;
    }

    public static function optOut(string $email, ?string $source = null, ?string $ip = null): self
    {
        return static::updateOrCreate(
            ['email' => strtolower(trim($email))],
            [
                'marketing_opt_in' => false,
                'opted_out_at' => now(),
                'source' => $source,
                'ip_address' => $ip,
            ]
        );
    }

    public static function optIn(string $email, ?string $source = null): self
    {
        return static::updateOrCreate(
            ['email' => strtolower(trim($email))],
            [
                'marketing_opt_in' => true,
                'opted_out_at' => null,
                'source' => $source,
            ]
        );
    }
}

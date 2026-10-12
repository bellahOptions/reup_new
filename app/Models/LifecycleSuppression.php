<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An address that must not be mailed, and why.
 *
 * Distinct from consent, which is the *customer's* choice. This is the platform's own
 * block, usually learned from a provider: a hard bounce means the mailbox does not
 * exist, and continuing to send to it is how a sending domain acquires a reputation
 * problem that then affects every other customer's password reset.
 *
 * `(email, reason)` is unique so the same bounce twice is one row, and lookups are by
 * email because that is the only identifier a bounce report carries.
 */
class LifecycleSuppression extends Model
{
    protected $table = 'lifecycle_suppressions';

    public const REASON_UNSUBSCRIBE = 'unsubscribe';
    public const REASON_BOUNCE = 'bounce';
    public const REASON_COMPLAINT = 'complaint';
    public const REASON_MANUAL = 'manual';

    /** Reasons that block marketing only. */
    public const MARKETING_ONLY = [self::REASON_UNSUBSCRIBE];

    /** Reasons that block everything, because the address is unusable or hostile. */
    public const BLOCK_ALL = [self::REASON_BOUNCE, self::REASON_COMPLAINT, self::REASON_MANUAL];

    protected $fillable = ['email', 'reason', 'note', 'created_by'];

    /**
     * Should a message to this address be blocked?
     *
     * A marketing message is blocked by any reason. A transactional message is
     * blocked only by the reasons that mean the address itself is unusable — an
     * unsubscribe must never stop a password reset or a receipt.
     */
    public static function blocks(string $email, bool $isMarketing): bool
    {
        $email = strtolower(trim($email));

        $reasons = static::where('email', $email)->pluck('reason')->all();

        if ($reasons === []) {
            return false;
        }

        if ($isMarketing) {
            return true;
        }

        return array_intersect($reasons, self::BLOCK_ALL) !== [];
    }
}

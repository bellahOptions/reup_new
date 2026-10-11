<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\CustomVerifyEmail;
use App\Notifications\CustomResetPassword;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    // In User.php model, add to $fillable array:
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'name_review',
        'name_review_reason',
        'email',
        'password',
        'phone',
        'whatsapp',
        'birthday',
        'gender',
        'profile_picture',
        'avatar_color',
        'avatar_icon',
        'theme_preference',
        'referral_code',
        'referred_by_user_id',
        'tips_seen_at',
        'address',
        'state',
        'city',
        'profile_completed',
        'notification_preferences',
        'status',
        'is_admin',
        'is_super_admin',
        'admin_role',
        'admin_permissions',
        'last_login_at',
        'last_login_ip',
        'last_activity',
        'last_activity_at',
        'last_assigned_at',
        'is_online',
        'is_blocked',
        'requires_phone_update',
        'phone_verified_at',
        'transaction_pin',
        'transaction_pin_set_at',
        'failed_pin_attempts',
        'pin_locked_until',
        'dva_account_id',
        'dva_bank_slug',
        'paystack_details_synced_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'birthday' => 'date',
        'profile_completed' => 'boolean',
        'notification_preferences' => 'array',
        'is_admin' => 'boolean',
        'is_super_admin' => 'boolean',
        'admin_permissions' => 'array',
        'is_online' => 'boolean',
        'is_blocked' => 'boolean',
        'requires_phone_update' => 'boolean',
        'last_login_at' => 'datetime',
        'last_activity' => 'datetime',
        'last_activity_at' => 'datetime',
        'last_assigned_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'transaction_pin_set_at' => 'datetime',
        'pin_locked_until' => 'datetime',
        'dva_created_at' => 'datetime',
        'paystack_details_synced_at' => 'datetime',
        'tips_seen_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Never serialise the PIN hash. Without this it would appear in any
        // JSON response that returns a user record.
        'transaction_pin',
        'failed_pin_attempts',
        'pin_locked_until',
    ];

    protected $appends = [
        'age',
        'profile_completion_percentage',
    ];

    protected static function booted()
    {
        /*
         * Keep the two halves and the whole name in step, in that direction.
         *
         * `first_name` and `last_name` are what the payment provider is given —
         * Paystack makes both compulsory for a customer who is to be assigned a
         * dedicated virtual account — and `name` is what the console, the
         * dashboard greeting and the chat widgets read. Having both means one of
         * them can silently go stale, so the whole is always rebuilt from the
         * parts when the parts change.
         *
         * Guarded by `isDirty` on the parts, which is what makes this safe for
         * the many places that still write `name` alone (factories, seeders, the
         * admin console): those leave the parts untouched, so nothing is
         * overwritten, and the accessors below derive the parts from `name` on
         * read. The one thing that must never happen is a name being *blanked*
         * by a save that never mentioned it.
         */
        static::saving(function ($user) {
            if ($user->isDirty('first_name') || $user->isDirty('last_name')) {
                $user->name = $user->fullNameFor();
            }

            /*
             * An account created without a whole name at all — `first_name` and
             * `last_name` are the only name inputs now, so `name` is empty until
             * this runs.
             *
             * The two parts are normalised to strings first (empty rather than
             * null), which has two effects: it marks them dirty so the rebuild
             * above can see them, and it stops the accessors falling back to
             * splitting an empty `name`. That fallback is exactly right for an
             * account whose name was written by something else, and useless here.
             *
             * The value stored is the same one the gateway is given, so the
             * receipt, the console and Paystack's own customer record cannot
             * disagree about who the customer is.
             */
            if (blank($user->name)) {
                $user->first_name = filled($user->first_name) ? trim((string) $user->first_name) : '';
                $user->last_name = filled($user->last_name) ? trim((string) $user->last_name) : '';

                [$first, $last] = $user->nameParts();

                $user->name = trim($first . ' ' . $last);
            }
        });

        static::created(function ($user) {
            $user->wallet()->create([
                'balance' => 0,
                'pending_balance' => 0,
                'total_funded' => 0,
                'total_spent' => 0,
                'transaction_count' => 0,
            ]);
        });
    }

    /**
     * What a name may contain.
     *
     * Re-exported from {@see \App\Support\Name}, which is where the rule lives now.
     * It moved because there turned out to be four entry points rather than two —
     * sign-up, the profile form, the admin customer editor, and the legacy
     * whole-name shape each of those still accepts — and four copies of one rule is
     * the shape that drifts: one accepts a hyphen, one strips it, and the account
     * name on the customer's bank account stops matching the name they typed.
     *
     * The constant stays on the model because it is part of this model's contract —
     * `first_name` and `last_name` are the columns it governs — and because a
     * reference to `User::NAME_PATTERN` reads better at a call site than
     * `Name::PART_PATTERN` does. There is one definition, in `Name`; this points at
     * it.
     *
     * `\p{L}` is "a letter in any script" and `\p{M}` is "a combining mark" — both
     * needed, and the second is not obvious. `Ọ̀ṣun` is spelled with a combining
     * grave accent that sits in neither the ASCII class nor `\p{L}`; without `\p{M}`
     * the regex rejects a real Nigerian name while accepting "John". Names are not
     * required to be English, and the account name Paystack prints on a customer's
     * personal account number is what a payer reads before sending money.
     */
    public const NAME_PATTERN = \App\Support\Name::PART_PATTERN;

    /**
     * Validate a name against the shared rule.
     *
     * A thin delegation rather than a second implementation: the previous version
     * of this method had its own `trim()` and its own emptiness check, which is
     * exactly how a rule acquires two behaviours.
     */
    public static function isValidNamePart(?string $name): bool
    {
        return \App\Support\Name::isValid($name);
    }

    /**
     * The stored first name, or the first word of `name` when the column is
     * empty.
     *
     * The fallback covers rows written by anything that sets `name` alone —
     * factories, seeders, the admin console's user form — and every account that
     * existed before the columns were added. Without it, those accounts would
     * present an empty `first_name`, which is exactly the value Paystack refuses,
     * and the dedicated-account call would fail for a customer whose name the
     * application can plainly see.
     */
    public function getFirstNameAttribute($value): string
    {
        if (filled($value)) {
            return (string) $value;
        }

        return $this->splitName((string) $this->attributes['name'] ?? '')[0] ?? '';
    }

    /** The stored last name, or everything after the first word of `name`. */
    public function getLastNameAttribute($value): string
    {
        if (filled($value)) {
            return (string) $value;
        }

        return $this->splitName((string) $this->attributes['name'] ?? '')[1] ?? '';
    }

    /**
     * A full name rebuilt from the two halves, in display order.
     *
     * Deliberately returns `name` untouched when neither half has a value, so
     * this can be assigned unconditionally: a save that mentions no name at all
     * cannot blank the column.
     */
    public function fullNameFor(): string
    {
        $parts = array_filter([
            trim((string) $this->attributes['first_name'] ?? ''),
            trim((string) $this->attributes['last_name'] ?? ''),
        ], fn ($part) => $part !== '');

        return $parts === [] ? (string) ($this->attributes['name'] ?? '') : implode(' ', $parts);
    }

    /**
     * The first and last name to send to the payment provider.
     *
     * Never empty, because the gateway rejects an empty `first_name`/
     * `last_name` outright and a customer whose name somehow went missing should
     * still be able to hold a virtual account. The placeholder is applied *here*,
     * at the boundary, rather than being written to the database as somebody's
     * surname.
     *
     * @return array{0:string,1:string}
     */
    public function nameParts(): array
    {
        return [
            trim((string) $this->first_name) ?: 'ReUp',
            trim((string) $this->last_name) ?: 'Customer',
        ];
    }

    /* =====================================================================
     | Name review
     |====================================================================
     | A heuristic flags names that *might* be placeholders — "asdf", "Test
     | Test", "aaaa" — for an administrator to glance at. It never blocks
     | anything: `name_review` is an opinion stored beside the account, and
     | every action that follows from it is taken with the account controls
     | under the same audit as everything else.
     */

    /** Nobody has looked, and a heuristic thought it worth a look. */
    public const NAME_REVIEW_PENDING = 'pending';

    /** An administrator looked: the name is genuine. */
    public const NAME_REVIEW_APPROVED = 'approved';

    /** An administrator looked: the name is not genuine. */
    public const NAME_REVIEW_REJECTED = 'rejected';

    /**
     * Apply the heuristic to this account's name, without saving.
     *
     * Called at sign-up and whenever a name changes, and only ever *adds* a flag.
     * An account an administrator has already cleared is not re-flagged by a later
     * save that does not touch the name: being asked the same question twice is how
     * a review queue stops being read.
     *
     * Returns whether the flag was raised, so the caller can log it without asking
     * the same question again.
     */
    public function flagNameIfSuspicious(): bool
    {
        if ($this->name_review === self::NAME_REVIEW_APPROVED) {
            return false;
        }

        /*
         * Already flagged: nothing to do, and *not* re-writing it matters.
         *
         * A flag that is already stored must not be re-saved on every later write to
         * the row. The profile form re-saves the model for unrelated reasons
         * (profile completion), and each save persists the whole in-memory
         * attribute set — so a model holding a stale `name_review` would write that
         * staleness back over a value another code path had just set. Setting the
         * flag only when it is not already set keeps this method a one-way action
         * and keeps it idempotent, which is what makes the order of the saves in
         * the caller stop mattering.
         */
        if ($this->name_review === self::NAME_REVIEW_PENDING) {
            return false;
        }

        $reasons = \App\Support\Name::reviewReasons((string) $this->name);

        if ($reasons === []) {
            return false;
        }

        $this->forceFill([
            'name_review' => self::NAME_REVIEW_PENDING,
            'name_review_reason' => \App\Support\Name::explainReview($reasons),
        ])->saveQuietly();

        return true;
    }

    /** Record an administrator's decision. */
    public function reviewName(string $decision, ?int $reviewerId = null): void
    {
        if (! in_array($decision, [self::NAME_REVIEW_APPROVED, self::NAME_REVIEW_REJECTED], true)) {
            throw new \InvalidArgumentException("Unknown name review decision [{$decision}].");
        }

        $this->forceFill([
            'name_review' => $decision,
            'name_review_reason' => $reviewerId === null
                ? $this->name_review_reason
                : trim(($this->name_review_reason ?? '') . ' — reviewed by #' . $reviewerId),
        ])->save();
    }

    /**
     * Is this account waiting for somebody to look at its name?
     */
    public function nameReviewPending(): bool
    {
        return $this->name_review === self::NAME_REVIEW_PENDING;
    }

    /** The accounts a name review is waiting on. */
    public function scopeAwaitingNameReview($query)
    {
        return $query->where('name_review', self::NAME_REVIEW_PENDING);
    }

    /**
     * Split a full name into first and last, for accounts that only have `name`.
     *
     * The last name is everything after the first word, so "Ada Obi Nwosu"
     * round-trips exactly. A single-word name yields an empty last name: the
     * caller decides whether to substitute a placeholder or leave it null, and
     * those two callers genuinely want different things.
     *
     * @return array{0:string,1:string}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = (string) ($parts[0] ?? '');
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        return [$first, $last];
    }

    // ... rest of your existing methods
    
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transactions::class);
    }

    public function getAgeAttribute()
    {
        if (!$this->birthday) {
            return null;
        }
        return now()->diffInYears($this->birthday);
    }

    public function getProfileCompletionPercentageAttribute()
    {
        $fields = [
            'name' => 10,
            'email' => 15,
            'phone' => 20,
            'whatsapp' => 10,
            'birthday' => 10,
            'gender' => 5,
            'address' => 10,
            'state' => 10,
            'city' => 10,
        ];

        $total = 0;
        $completed = 0;

        foreach ($fields as $field => $weight) {
            $total += $weight;
            if (!empty($this->$field)) {
                $completed += $weight;
            }
        }

        return round(($completed / $total) * 100);
    }

    public function getFormattedPhoneAttribute()
    {
        if (!$this->phone) {
            return null;
        }

        $phone = preg_replace('/\D/', '', $this->phone);
        
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '0') {
            return '234' . substr($phone, 1);
        }
        
        if (strlen($phone) === 10) {
            return '234' . $phone;
        }

        return $phone;
    }

    /**
     * Authoritative wallet balance.
     *
     * The `users.wallet_balance` column exists but nothing writes to it (it is
     * not fillable, so `$user->update(['wallet_balance' => ...])` was silently
     * discarded) — yet plenty of code read it, producing stale or zero
     * balances. The wallets table is the single source of truth; the column is
     * kept only as a legacy mirror and is never used for decisions.
     */
    public function getWalletBalanceAttribute(): float
    {
        $wallet = $this->relationLoaded('wallet') ? $this->wallet : $this->wallet()->first();

        return (float) ($wallet->balance ?? 0);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended' || $this->is_blocked;
    }

    public function markProfileAsCompleted()
    {
        $this->update(['profile_completed' => true]);
    }

    public function getDefaultNotificationPreferences()
    {
        return [
            'email' => [
                'transactions' => true,
                'promotions' => true,
                'security' => true,
            ],
            'sms' => [
                'transactions' => false,
                'promotions' => false,
                'security' => true,
            ],
            'push' => [
                'transactions' => true,
                'promotions' => true,
                'security' => true,
            ]
        ];
    }

    public function getNotificationPreferences()
    {
        return $this->notification_preferences ?? $this->getDefaultNotificationPreferences();
    }

    /**
     * The appearance this account has chosen, or the site default when it has
     * chosen nothing.
     *
     * Kept on the model rather than in the view so partials/theme.blade.php,
     * the profile page and the admin user table all resolve it the same way —
     * and so a value that is no longer in config('theme.modes') (a slug removed
     * in a later release) degrades to the default instead of emitting an
     * unrecognised `data-theme` the CSS has no rules for.
     */
    public function themePreference(): string
    {
        return \App\Support\Theme::normalise($this->theme_preference);
    }

        /**
     * Send the email verification notification.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new CustomVerifyEmail($this->verificationUrl()));
    }

    /**
     * Get the verification URL for the given notifiable.
     *
     * @return string
     */
    public function verificationUrl()
    {
        return \URL::temporarySignedRoute(
            'verification.verify',
            \Carbon\Carbon::now()->addMinutes(config('auth.verification.expire', 60)),
            [
                'id' => $this->getKey(),
                'hash' => sha1($this->getEmailForVerification()),
            ]
        );
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPassword($token));
    }

      // Check if user is admin
    public function isAdmin()
    {
        return $this->is_admin || $this->is_super_admin;
    }
        // Check if user is super admin
    public function isSuperAdmin()
    {
        return $this->is_super_admin;
    }
     // Get admin role
    public function getAdminRoleAttribute($value)
    {
        if ($this->is_super_admin) {
            return 'Super Admin';
        }
        return $value ?: ($this->is_admin ? 'Admin' : 'User');
    }

    // Check permission
    public function hasPermission($permission)
    {
        if ($this->is_super_admin) {
            return true;
        }

        if (!$this->is_admin) {
            return false;
        }

        $permissions = $this->admin_permissions ?? [];
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }

    /**
     * Scope to administrators.
     *
     * Grouped deliberately: the previous `where(...)->orWhere(...)` form leaked
     * an OR across the parent query, so `$query->admins()->where('status', ...)`
     * returned every super admin regardless of status.
     */
    public function scopeAdmins($query)
    {
        return $query->where(function ($q) {
            $q->where('is_admin', true)->orWhere('is_super_admin', true);
        });
    }

    // Scope for regular users
    public function scopeRegularUsers($query)
    {
        return $query->where('is_admin', false)->where('is_super_admin', false);
    }

    public function chatSessions()
{
    return $this->hasMany(ChatSession::class, 'user_id');
}

public function assignedChats()
{
    return $this->hasMany(ChatSession::class, 'admin_id');
}

public function chatMessages()
{
    return $this->hasMany(ChatMessage::class, 'sender_id')
        ->where('sender_type', $this->isAdmin() ? 'admin' : 'user');
}


    /**
     * Check if user is online
     */
    public function isOnline()
    {
        if ($this->is_online && $this->last_activity && $this->last_activity->gt(now()->subMinutes(5))) {
            return true;
        }

        return $this->last_activity !== null
            && $this->last_activity->diffInMinutes(now()) <= 5;
    }


    /**
     * Get user's contact messages
     */
    public function contactMessages()
    {
        return $this->hasMany(ContactMessage::class);
    }


    /**
     * Scope for online users
     */
    public function scopeOnline($query)
    {
        return $query->where(function($q) {
            $q->where('is_online', true)
              ->orWhere('last_activity', '>=', now()->subMinutes(5));
        });
    }

   
    /**
     * Get unread chat messages count for user
     */
    public function getUnreadChatsCountAttribute()
    {
        if ($this->isAdmin()) {
            return ChatMessage::whereHas('session', function($query) {
                $query->where('admin_id', $this->id)
                      ->orWhereNull('admin_id');
            })
            ->where('sender_type', 'user')
            ->where('is_read', false)
            ->count();
        }

        return ChatMessage::whereHas('session', function($query) {
            $query->where('user_id', $this->id);
        })
        ->where('sender_type', 'admin')
        ->where('is_read', false)
        ->count();
    }

    /**
     * Mark user as offline
     */
    public function markOffline()
    {
        $this->update(['is_online' => false]);
    }

    /**
     * Update user activity
     */
    public function updateActivity()
    {
        $this->update([
            'last_activity' => now(),
            'is_online' => true
        ]);
    }

    // Add to your Users model or create a notification tracking table
public function markTermsNotificationSent($termsId)
{
    $this->notifications()->create([
        'type' => 'terms_updated',
        'data' => ['terms_id' => $termsId],
        'read_at' => null,
    ]);
}

}
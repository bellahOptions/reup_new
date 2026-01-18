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
    'email',
    'password',
    'phone',
    'whatsapp',
    'birthday',
    'gender',
    'profile_picture',
    'address',
    'state',
    'city',
    'profile_completed',
    'notification_preferences',
    'is_admin',
    'is_super_admin',
    'admin_role', // Add this line
    'admin_permissions', // Add this line
    'last_login_at', // Add this line
    'is_online', // Add this line
];

// Add to $casts array:
protected $casts = [
    'email_verified_at' => 'datetime',
    'birthday' => 'date',
    'profile_completed' => 'boolean',
    'notification_preferences' => 'array',
    'is_admin' => 'boolean', // Add this line
    'is_super_admin' => 'boolean', // Add this line
    'admin_permissions' => 'array', // Add this line
    'is_online' => 'boolean', // Add this line
    'last_login_at' => 'datetime', // Add this line
];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'age',
        'profile_completion_percentage',
    ];

    protected static function booted()
    {
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

    public function getWalletBalanceAttribute()
    {
        return $this->wallet ? $this->wallet->balance : 0;
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

    // Scope for admins
    public function scopeAdmins($query)
    {
        return $query->where('is_admin', true)->orWhere('is_super_admin', true);
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
        return $this->is_online || 
               ($this->last_activity && $this->last_activity->diffInMinutes(now()) <= 5);
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
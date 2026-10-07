<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'action', 'ip_address', 'user_agent', 'details'
    ];

    protected $casts = [
        'details' => 'array'
    ];

    /**
     * The administrator who performed the action.
     *
     * This pointed at a non-existent `admin_id` column, so every attempt to
     * eager-load `with('user')` on the activity feed threw. The table's
     * foreign key is `user_id`.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get formatted details as string
     */
    public function getDetailsAsStringAttribute()
    {
        if (!$this->details) {
            return '';
        }

        if (is_string($this->details)) {
            $details = json_decode($this->details, true);
        } else {
            $details = $this->details;
        }

        if (is_array($details)) {
            return implode(', ', array_map(function($key, $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                return ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
            }, array_keys($details), $details));
        }

        return (string) $details;
    }

    /**
     * Get action label
     */
    public function getActionLabelAttribute()
    {
        $labels = [
            'admin_created' => 'Admin Created',
            'admin_updated' => 'Admin Updated',
            'admin_deleted' => 'Admin Deleted',
            'admin_status_toggled' => 'Status Changed',
            'update_transaction_status' => 'Transaction Updated',
            // Add more mappings as needed
        ];

        return $labels[$this->action] ?? ucwords(str_replace('_', ' ', $this->action));
    }

    // Helper to log admin actions
    public static function log($userId, $action, $details = null)
    {
        return self::create([
            'user_id' => $userId,
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'details' => $details
        ]);
    }
}
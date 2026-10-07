<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    protected $fillable = [
        'user_id',
        'admin_id',
        'status',
        'subject',
        'last_message_at'
    ];

    protected $casts = [
        'last_message_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * Messages awaiting the current viewer's attention.
     *
     * Guarded because `auth()->user()` is null whenever this is touched from a
     * queued job, an artisan command or an unauthenticated context — the
     * previous form dereferenced null and threw.
     */
    public function unreadMessages()
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return $this->messages()->whereRaw('1 = 0');
        }

        return $this->messages()
            ->where('is_read', false)
            ->where('sender_type', $viewer->isAdmin()
                ? ChatMessage::SENDER_USER
                : ChatMessage::SENDER_ADMIN);
    }

    /** Messages from the customer that no agent has read yet. */
    public function unreadCustomerMessages()
    {
        return $this->messages()
            ->where('is_read', false)
            ->where('sender_type', ChatMessage::SENDER_USER);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
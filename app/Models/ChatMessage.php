<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $fillable = [
        'chat_session_id',
        'sender_id',
        'sender_type',
        'message',
        'is_read',
        'read_at'
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime'
    ];

    public function session()
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function sender()
    {
        return $this->morphTo();
    }

    public function markAsRead()
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now()
            ]);
        }
    }
     public static function boot()
    {
        parent::boot();
        
        static::creating(function ($message) {
            // Use full class path for sender_type
            if ($message->sender_type === 'user' || $message->sender_type === 'admin') {
                $message->sender_type = 'App\Models\User';
            }
        });
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeForSession($query, $sessionId)
    {
        return $query->where('chat_session_id', $sessionId);
    }
}
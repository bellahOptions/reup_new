<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    /**
     * Sender kinds. These are the values the schema documents and the values
     * every query filters on.
     *
     * A `creating` hook in this model used to rewrite both of these to
     * 'App\Models\User'. Because the columns are plain strings, the rewrite
     * actually persisted — so every `where('sender_type', 'user')` and
     * `where('sender_type', 'admin')` in the codebase matched nothing. Among
     * other things that silently broke: unread counts, the admin notification
     * bell, the "mark as read" flow, and the dashboard's unread-message stat.
     */
    public const SENDER_USER = 'user';
    public const SENDER_ADMIN = 'admin';

    protected $fillable = [
        'chat_session_id',
        'sender_id',
        'sender_type',
        'message',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    /**
     * The user who wrote the message.
     *
     * This was a `morphTo()`, but `sender_type` is a role label ('user' /
     * 'admin'), not a morph class — so the relation could never resolve.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function markAsRead(): void
    {
        if (! $this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeForSession($query, $sessionId)
    {
        return $query->where('chat_session_id', $sessionId);
    }

    public function isFromCustomer(): bool
    {
        return $this->sender_type === self::SENDER_USER;
    }
}

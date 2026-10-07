<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalise `chat_messages.sender_type`.
 *
 * A `creating` hook on the ChatMessage model rewrote the documented values
 * 'user' and 'admin' into the fully-qualified class name 'App\Models\User'.
 * The column is a plain string, so the rewritten value was persisted, and
 * every query filtering on 'user' or 'admin' then matched nothing — which
 * silently disabled unread counts, the admin notification bell and the
 * mark-as-read flow.
 *
 * The hook is gone. This repairs the rows it produced: a message belongs to a
 * customer when its sender is the session's user, and to an admin otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Messages whose sender is the session owner are customer messages.
        DB::statement("
            UPDATE chat_messages m
            INNER JOIN chat_sessions s ON s.id = m.chat_session_id
            SET m.sender_type = 'user'
            WHERE m.sender_type NOT IN ('user', 'admin')
              AND m.sender_id = s.user_id
        ");

        // Everything else that is not already normalised is an agent reply.
        DB::statement("
            UPDATE chat_messages
            SET sender_type = 'admin'
            WHERE sender_type NOT IN ('user', 'admin')
        ");
    }

    public function down(): void
    {
        // Irreversible by design: the previous values carried no information.
    }
};

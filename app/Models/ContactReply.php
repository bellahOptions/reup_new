<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_message_id',
        'admin_id',
        'subject',
        'message',
        'sent_at'
    ];

    protected $casts = [
        'sent_at' => 'datetime'
    ];

    public function contactMessage()
    {
        return $this->belongsTo(ContactMessage::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
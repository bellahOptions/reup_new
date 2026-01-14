<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'subject',
        'message',
        'is_read',
        'is_responded',
        'responded_at'
    ];
    
    protected $casts = [
        'is_read' => 'boolean',
        'is_responded' => 'boolean',
        'responded_at' => 'datetime'
    ];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function markAsRead()
    {
        $this->update(['is_read' => true]);
    }
    
    public function markAsResponded()
    {
        $this->update([
            'is_responded' => true,
            'responded_at' => now()
        ]);
    }
    
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
    public function replies()
{
    return $this->hasMany(ContactReply::class);
}

public function repliedBy()
{
    return $this->belongsTo(User::class, 'replied_by');
}
}
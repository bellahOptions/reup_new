<?php

// App/Models/PromotionNotification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'title', 'content', 'badge', 'badge_color', 'text_color', 'icon', 'is_active', 'starts_at', 'ends_at'
    ];

    // Scope for promotions
    public function scopePromotions($query)
    {
        return $query->where('type', 'promotion')->where('is_active', true);
    }

    // Scope for notifications (for modal announcements)
    public function scopeAnnouncements($query)
    {
        return $query->where('type', 'notification')->where('is_active', true);
    }

    // Scope for news
    public function scopeNews($query)
    {
        return $query->where('type', 'news')->where('is_active', true);
    }
}

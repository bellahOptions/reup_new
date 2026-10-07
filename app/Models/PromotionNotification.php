<?php

// App/Models/PromotionNotification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionNotification extends Model
{
    use HasFactory;

    /**
     * Explicit table name.
     *
     * The migration is named `create_promotions_notifications_table` but the
     * table it actually creates is `promotion_notifications`. Laravel's
     * pluraliser derives `promotion_notifications` from the class name (it does
     * not singularise the trailing word), so the convention points at a table
     * that does not exist and every page rendering an announcement threw
     * "Base table or view not found".
     */
    protected $table = 'promotion_notifications';

    protected $fillable = [
        'type', 'title', 'content', 'badge', 'badge_color', 'text_color', 'icon', 'is_active', 'starts_at', 'ends_at'
    ];

    /**
     * Without these casts the timestamp columns come back as raw strings, and
     * both admin screens call `->format()` on them:
     *
     *   admin/announcements/index → "Call to a member function format() on string"
     *   admin/announcements/form  → the same, on the datetime-local input value
     *
     * `created_at`/`updated_at` were unaffected because Eloquent treats those as
     * dates by default; custom timestamp columns need to be declared.
     *
     * `is_active` is cast for the same class of reason: the query builder and
     * the `where('is_active', true)` filters elsewhere in the app compare
     * against a boolean, while the column returns an int from MySQL.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    // Scope for promotions
    public function scopePromotions($query)
    {
        return $query->where('type', 'promotion')->where('is_active', true);
    }

    /**
     * Announcements that are live right now.
     *
     * The "active and inside its schedule window" predicate was duplicated in
     * four places — HomeController, DashboardController, the admin API method
     * and the global view composer — each written slightly differently. One of
     * them drifted into returning inactive rows, and there was no way to tell
     * which was authoritative. This is that predicate, once.
     *
     * A NULL start means "already started"; a NULL end means "never expires".
     */
    public function scopeLive($query)
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
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

<?php

namespace App\Http\Controllers;

use App\Models\PromotionNotification;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HomeController extends Controller
{
    public function index()
    {
        /*
         * The view renders an announcement marquee when records are present;
         * previously nothing supplied the variable, so `layouts.marquee`
         * silently fell back to three hardcoded, expired-looking offers.
         *
         * The query is guarded because this is the public landing page. The
         * `promotion_notifications` table is absent from the migration history
         * (it exists only in environments where it was created out of band), so
         * on a fresh database the unguarded query took the entire home page down
         * with a 500. An optional announcement strip is not worth that: if the
         * table is missing, render the page without announcements.
         */
        $announcements = collect();

        if (Schema::hasTable('promotion_notifications')) {
            try {
                $announcements = PromotionNotification::query()
                    ->live()
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get();
            } catch (Throwable $e) {
                // A malformed schema should not be fatal either.
                report($e);
            }
        }

        return view('home', compact('announcements'));
    }
}

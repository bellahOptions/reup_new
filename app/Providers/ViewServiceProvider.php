<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Global view data.
 *
 * ## Removed: the announcement composer
 *
 * This provider used to run two queries on **every view render** to populate
 * `$globalPromotions` and `$globalAnnouncements`, then map them into an array
 * shape (`badgeColor`, `text`, `icon`) that nothing consumed:
 *
 *   - no view referenced either variable;
 *   - `resources/views/components/announcement-modal.blade.php` was never
 *     included anywhere, so the announcements half had no renderer at all;
 *   - the promotions half was shadowed by the same query in HomeController and
 *     DashboardController, which supply their marquees directly.
 *
 * So the cost was two database round trips per page — including every admin
 * screen and every error page — for data that reached no template. Announcements
 * are now read where they are rendered, through `PromotionNotification::live()`.
 *
 * If global announcement data is wanted again, add it back with a reader and a
 * test; do not reinstate a `View::composer('*')` that queries unconditionally.
 */
class ViewServiceProvider extends ServiceProvider
{
    public function boot()
    {
        /*
         * Appearance, shared with every layout.
         *
         * This cannot be a partial the layout includes. A Blade `@include` renders
         * the child in its own scope and does **not** leak variables back out, so
         * a layout could not read the value from a partial included a line
         * earlier — and the layout needs it in its own `<html>` tag, before any
         * output. It therefore has to arrive as view data.
         *
         * Scoped to the layouts and the standalone pages rather than `*`: an email
         * template, a pagination partial, and a Blade string rendered by a test
         * have no `<html>` tag and no use for this, and each would otherwise pay
         * for a `site_settings` lookup. The list mirrors the files that actually
         * open with `<!doctype html>`.
         */
        View::composer([
            'layouts.*',
            'errors.*',
            'admin.layouts.*',
            'admin.auth.*',
        ], function ($view) {
            $view->with('themeState', \App\Support\ThemeState::forCurrentRequest());
        });

        // Legal pages. These read through the model so the cached pointer is
        // shared with the rest of the app (the previous inline queries bypassed
        // the cache and re-queried on every render).
        View::composer(['terms-of-service', 'privacy-policy'], function ($view) {
            $view->with([
                'terms' => \App\Models\TermsPrivacy::getTerms(),
                'privacy' => \App\Models\TermsPrivacy::getPrivacy(),
            ]);
        });

        // Sidebar badges for every admin screen. The layout always expected
        // `$pendingTransfers`, but no controller ever passed it.
        View::composer('admin.*', function ($view) {
            $view->with(\App\Http\Controllers\Admin\DashboardController::sidebarCounts());
        });
    }
}

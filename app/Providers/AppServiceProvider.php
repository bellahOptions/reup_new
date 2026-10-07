<?php

namespace App\Providers;

use App\Http\Controllers\Admin\DashboardController;
use App\Models\TermsPrivacy;
use App\Services\BillPaymentService;
use App\Services\ClubKonnectCatalogue;
use App\Services\ClubKonnectService;
use App\Services\ProviderHealthService;
use App\Services\ProviderManager;
use App\Services\Providers\ClubKonnectProvider;
use App\Services\Providers\PairgateProvider;
use App\Support\Vite;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        /*
        |------------------------------------------------------------------
        | Bill-payment providers
        |------------------------------------------------------------------
        | ProviderManager holds both upstreams and decides which one serves a
        | request. Binding them as singletons means the balance cache and the
        | HTTP client are shared within a request rather than rebuilt per
        | resolution.
        */
        $this->app->singleton(ClubKonnectService::class);
        $this->app->singleton(ClubKonnectCatalogue::class);
        $this->app->singleton(ClubKonnectProvider::class);
        $this->app->singleton(PairgateProvider::class);
        $this->app->singleton(ProviderHealthService::class);
        $this->app->singleton(ProviderManager::class);
        $this->app->singleton(BillPaymentService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /*
        |------------------------------------------------------------------
        | Indexed string length: 191 characters, not 255
        |------------------------------------------------------------------
        | On MySQL/MariaDB builds whose InnoDB index key limit is 1000 bytes
        | (rather than 3072 with DYNAMIC rows and a large prefix), a default
        | `string()` column cannot be indexed: 255 × 4 bytes for utf8mb4 is
        | 1020 bytes on its own. The first migration run then dies at
        |
        |   1071 Specified key was too long; max key length is 1000 bytes
        |   (alter table `personal_access_tokens` add index
        |    `..._tokenable_type_tokenable_id_index` (`tokenable_type`, `tokenable_id`))
        |
        | because `morphs('tokenable')` is a VARCHAR(255) plus a bigint. 191 is
        | the largest length that fits: 191 × 4 = 764 bytes, leaving room for the
        | bigint in that composite index, and it keeps `users.email`,
        | `transactions.reference`, `site_settings.key` and `failed_jobs.uuid`
        | indexable too.
        |
        | This is Laravel's documented remedy for exactly this error. It only
        | affects `string()` columns created without an explicit length;
        | `string('token', 64)`, `string('purpose', 32)` and the like are
        | unaffected. Removing it re-breaks `php artisan migrate` on those
        | servers — see docs/RUNNING.md.
        */
        Schema::defaultStringLength(191);

        /*
        |------------------------------------------------------------------
        | WhatsApp click-to-chat URL
        |------------------------------------------------------------------
        | Built once here rather than in each view. `wa.me` needs the number in
        | international format with no `+` and no leading zero, and a wrong
        | format does not error — it produces a dead link that a customer only
        | discovers when tapping it. Deriving it centrally means that format
        | lives in exactly one place.
        */
        config([
            'services.support.whatsapp_url' => (function () {
                $number = preg_replace('/\D+/', '', (string) config('services.support.whatsapp'));

                if ($number === '') {
                    return null;
                }

                /*
                 * Normalise to international format. A Nigerian number may be
                 * written either way by whoever maintains .env, and only one of
                 * them is valid for wa.me:
                 *
                 *   07074217206   -> 2347074217206   (local, drop trunk zero)
                 *   +234 707 421 7206 -> 2347074217206
                 *
                 * Getting this wrong does not raise an error — it produces a
                 * wa.me link that opens WhatsApp with an invalid number, which
                 * the customer only discovers after tapping it. Hence the
                 * explicit normalisation rather than trusting the format.
                 */
                if (str_starts_with($number, '0')) {
                    $number = '234' . substr($number, 1);
                }

                if (! str_starts_with($number, '234')) {
                    $number = '234' . $number;
                }

                return 'https://wa.me/' . $number . '?text=' . rawurlencode(
                    (string) config('services.support.whatsapp_message')
                );
            })(),
        ]);

        /*
        |------------------------------------------------------------------
        | @vite
        |------------------------------------------------------------------
        | Laravel 8 has no `@vite` directive — it arrived in 9.19 — so the
        | directive previously passed through to the browser as literal text
        | and no stylesheet or script was ever loaded. App\Support\Vite does
        | the resolution; this just wires it into Blade.
        |
        | Accepts an array (`@vite(['a', 'b'])`) or a comma-separated list
        | (`@vite('a', 'b')`), which is what `laravel-vite-plugin` documents.
        */
        Blade::directive('vite', function ($expression) {
            return "<?php echo \\App\\Support\\Vite::tags([{$expression}]); ?>";
        });

        /*
        |------------------------------------------------------------------
        | @checked / @selected / @disabled / @required / @readonly
        |------------------------------------------------------------------
        | These arrived in Laravel 9.21 and this application is on 8.x, so they
        | were not compiled. Blade silently passes an unknown @directive through
        | as literal text, which meant:
        |
        |   * profile/index.blade.php printed the avatar picker's own source
        |     code onto the page, and none of the radio buttons worked;
        |   * register.blade.php stopped restoring the terms checkbox after a
        |     validation error;
        |   * every filter dropdown on the dashboard, wallet history and contact
        |     pages stopped re-selecting the submitted value.
        |
        | None of those raised an error, which is why they went unnoticed.
        | Backfilling the directives rather than rewriting a dozen call sites
        | keeps the templates idiomatic, and the generated markup is byte-for-
        | byte what Laravel 9 produces.
        |
        | Blade's directive parser tolerates an argument list spread over several
        | lines, so this covers the multi-line calls in the avatar picker too.
        */
        foreach (['checked', 'selected', 'disabled', 'required', 'readonly'] as $attribute) {
            Blade::directive($attribute, function ($condition) use ($attribute) {
                /*
                 * $condition arrives WITHOUT its surrounding parentheses:
                 * Blade's custom-directive path strips them for us. So they have
                 * to be put back, or the output is the parse error
                 * `if$x: ... endif;` rather than `if($x): ... endif;`.
                 */
                return "<?php if({$condition}): echo '{$attribute}'; endif; ?>";
            });
        }

        // Legal pages. These read through the model so the cached pointer is
        // shared with the rest of the app (the previous inline queries bypassed
        // the cache and re-queried on every render).
        View::composer(['terms-of-service', 'privacy-policy'], function ($view) {
            $view->with([
                'terms' => TermsPrivacy::getTerms(),
                'privacy' => TermsPrivacy::getPrivacy(),
            ]);
        });

        // Sidebar badges for every admin screen. The layout always expected
        // `$pendingTransfers`, but no controller ever passed it.
        View::composer('admin.*', function ($view) {
            $view->with(DashboardController::sidebarCounts());
        });

        // <x-icon name="..." /> is registered automatically by Blade's
        // component discovery (resources/views/components).
        Blade::withoutDoubleEncoding();
    }
}

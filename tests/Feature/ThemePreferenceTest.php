<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Dark mode: auto-selected from the device, overridable per account and
 * per site.
 *
 * The feature has three states and two sources of truth, which is where this
 * kind of work usually goes wrong:
 *
 *   * **System is not a theme.** It is the instruction "ask the device". The
 *     server cannot answer it — `prefers-color-scheme` is not sent with the
 *     request — so the server publishes the request and the browser resolves it
 *     before the first paint. A test that only checks `data-theme` therefore
 *     proves nothing about the feature; the `data-requested-theme` attribute and
 *     the bootstrap script are the parts that carry it.
 *
 *   * **The account beats the site.** An administrator's default must never
 *     repaint the site under a member who has chosen for themselves.
 *
 * The tests below are grouped along those lines rather than by class.
 */
class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `site.settings` is a memoised singleton, so a setting written mid-test is
     * invisible to the code under test unless it is re-resolved.
     */
    private function setSiteDefault(?string $mode): void
    {
        SiteSetting::updateOrCreate(
            ['key' => 'default_theme'],
            [
                'value' => $mode,
                'type' => 'text',
                'category' => 'general',
                'description' => 'Default theme for visitors who have not chosen one',
                'is_public' => true,
            ]
        );

        Cache::flush();
        $this->app->forgetInstance('site.settings');
        $this->app->singleton('site.settings', function () {
            return \App\Models\SiteSetting::all()->pluck('value', 'key')->toArray();
        });
    }

    /* =====================================================================
     | Resolution — account, then site, then device
     | =================================================================== */

    public function test_an_account_that_has_chosen_nothing_follows_the_device(): void
    {
        // The default has to be "system", not "light": a new customer on a dark
        // phone should never be shown a white page.
        $user = User::factory()->create(['theme_preference' => null]);

        $this->assertNull($user->fresh()->theme_preference);
        $this->assertSame('system', $user->themePreference());
        $this->assertSame('system', Theme::modeFor($user));
    }

    public function test_an_explicit_choice_is_used(): void
    {
        foreach (['light', 'dark'] as $mode) {
            $user = User::factory()->create(['theme_preference' => $mode]);

            $this->assertSame($mode, $user->themePreference());
            $this->assertSame($mode, Theme::modeFor($user));
            $this->assertTrue(Theme::hasExplicitChoice($user));
        }
    }

    public function test_a_guest_follows_the_device(): void
    {
        $this->assertSame('system', Theme::modeFor(null));
    }

    public function test_an_account_beats_the_site_default(): void
    {
        // The rule that matters: an administrator setting a default must not
        // override a member who has already decided.
        $this->setSiteDefault('light');

        $user = User::factory()->create(['theme_preference' => 'dark']);

        $this->assertSame('dark', Theme::modeFor($user));
    }

    public function test_an_account_without_a_choice_inherits_the_site_default(): void
    {
        $this->setSiteDefault('dark');

        $user = User::factory()->create(['theme_preference' => null]);

        $this->assertSame('dark', Theme::modeFor($user));
        $this->assertFalse(Theme::hasExplicitChoice($user));
    }

    public function test_an_unrecognised_stored_value_degrades_to_the_default(): void
    {
        // A slug dropped in a later release must not render
        // `data-theme="oil-slick"`, which matches no rule in the stylesheet and
        // would silently render the light theme — a bug that presents as "my
        // preference did not save".
        $user = User::factory()->create(['theme_preference' => 'oil-slick']);

        $this->assertSame('system', Theme::modeFor($user));
        $this->assertFalse(Theme::hasExplicitChoice($user));
    }

    public function test_an_unrecognised_site_default_is_ignored(): void
    {
        $this->setSiteDefault('chartreuse');

        $this->assertSame('system', Theme::modeFor(null));
    }

    /* =====================================================================
     | Persistence
     | =================================================================== */

    public function test_a_choice_is_saved_to_the_account(): void
    {
        $user = User::factory()->create(['theme_preference' => null]);

        $this->actingAs($user)
            ->put(route('profile.theme'), ['theme' => 'dark'])
            ->assertSessionHasNoErrors();

        $this->assertSame('dark', $user->fresh()->theme_preference);
    }

    public function test_choosing_system_clears_the_column_rather_than_storing_it(): void
    {
        /*
         * There are three states but only two *choices*. "System" already means
         * "no opinion" — the same thing a NULL column means — so storing the word
         * would create a second representation of one state, and then "has this
         * account chosen anything?" would have two answers to check.
         *
         * It is also what lets the account fall back to the site default an
         * administrator sets *later*, instead of pinning today's value.
         */
        $user = User::factory()->create(['theme_preference' => 'dark']);

        $this->actingAs($user)
            ->put(route('profile.theme'), ['theme' => 'system'])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->theme_preference);
        $this->assertSame('system', $user->themePreference());
    }

    public function test_an_invalid_mode_is_rejected(): void
    {
        $user = User::factory()->create(['theme_preference' => 'dark']);

        $this->actingAs($user)
            ->put(route('profile.theme'), ['theme' => 'neon'])
            ->assertSessionHasErrors('theme');

        // And the stored value is untouched.
        $this->assertSame('dark', $user->fresh()->theme_preference);
    }

    public function test_the_switch_answers_a_json_request(): void
    {
        // The navbar switch posts without reloading the page, so it needs a
        // machine-readable answer rather than a redirect.
        $user = User::factory()->create(['theme_preference' => null]);

        $this->actingAs($user)
            ->putJson(route('profile.theme'), ['theme' => 'dark'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'mode' => 'dark',
                'resolved' => 'dark',
            ]);

        $this->assertSame('dark', $user->fresh()->theme_preference);
    }

    public function test_the_switch_redirects_a_form_post(): void
    {
        // The profile page's radio group is a plain form, so it must still work
        // with JavaScript disabled.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.index'))
            ->put(route('profile.theme'), ['theme' => 'light'])
            ->assertRedirect(route('profile.index'));

        $this->assertSame('light', $user->fresh()->theme_preference);
    }

    public function test_a_guest_cannot_save_a_theme(): void
    {
        $this->put(route('profile.theme'), ['theme' => 'dark'])
            ->assertRedirect(route('login'));
    }

    /* =====================================================================
     | Rendering — the attributes the CSS keys off, and the boot script
     =================================================================== */

    public function test_a_dark_choice_is_rendered_on_the_html_element(): void
    {
        $user = User::factory()->create([
            'theme_preference' => 'dark',
            'phone' => '08031234567',
            'phone_verified_at' => now(),
        ]);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-theme="dark"', $html);
        $this->assertStringContainsString('data-requested-theme="dark"', $html);
        // Signed in: the choice lives on the account, so the switch posts to the
        // server instead of writing to localStorage.
        $this->assertStringContainsString('data-theme-persist="server"', $html);
    }

    public function test_following_the_device_is_distinguishable_from_choosing_light(): void
    {
        /*
         * Both render the light theme, and that is the point: the *resolved*
         * theme is the same, but the request is not. Collapsing the two would
         * mean a visitor who wanted auto-detection got a pinned light theme the
         * moment the device preference changed.
         */
        $this->setSiteDefault('system');

        $user = User::factory()->create(['theme_preference' => null]);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        // The server assumes the configured fallback …
        $this->assertStringContainsString('data-theme="light"', $html);
        // … but records that the device is what decides.
        $this->assertStringContainsString('data-requested-theme="system"', $html);
    }

    public function test_guests_carry_their_choice_on_the_device(): void
    {
        // A guest has no account to hang a preference on, so the switch writes to
        // localStorage and must be told to.
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-theme-persist="device"', $html);
        $this->assertStringContainsString('data-requested-theme="system"', $html);
    }

    public function test_the_three_modes_are_published_for_the_switch(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        // The cycle order the switch walks. Read from the DOM by theme.js, so it
        // lives in one place — config/theme.php.
        $this->assertStringContainsString(
            'data-theme-modes="' . implode(',', array_keys(config('theme.modes'))) . '"',
            $html
        );
    }

    public function test_the_bootstrap_resolves_system_before_the_first_paint(): void
    {
        /*
         * The whole feature rests on this script running inline in <head>:
         *
         *   * it must be in the head, before the stylesheet is parsed, or the
         *     browser paints a white page first;
         *   * it must read `prefers-color-scheme`, or "auto-select by device"
         *     does not happen for anyone who has not chosen.
         */
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
        $this->assertStringContainsString('ReUpTheme', $html);

        $headStart = strpos($html, '<head');
        $headEnd = strpos($html, '</head>');
        $scriptAt = strpos($html, 'ReUpTheme');

        $this->assertNotFalse($scriptAt, 'The theme bootstrap is missing.');
        $this->assertGreaterThan($headStart, $scriptAt);
        $this->assertLessThan($headEnd, $scriptAt, 'The bootstrap must run from <head>, before first paint.');
    }

    public function test_the_browser_chrome_colour_is_published_per_scheme(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        // Media-scoped so a visitor without JavaScript still gets the right
        // address-bar colour.
        $this->assertStringContainsString('media="(prefers-color-scheme: light)"', $html);
        $this->assertStringContainsString('media="(prefers-color-scheme: dark)"', $html);
        $this->assertStringContainsString(config('theme.theme_color.dark'), $html);
        $this->assertStringContainsString('name="color-scheme"', $html);
    }

    public function test_every_layout_publishes_the_theme(): void
    {
        /*
         * Five layouts open with `<!doctype html>` and each has to carry the
         * attributes. A layout added later without them renders a page that
         * cannot be themed at all, and nothing else would catch that.
         */
        $layouts = glob(resource_path('views/layouts/*.blade.php'));
        $layouts = array_merge($layouts, glob(resource_path('views/errors/*.blade.php')));
        $layouts = array_merge($layouts, glob(resource_path('views/admin/layouts/*.blade.php')));
        $layouts = array_merge($layouts, glob(resource_path('views/admin/auth/*.blade.php')));

        $checked = 0;

        foreach ($layouts as $file) {
            if (! str_contains((string) file_get_contents($file), '<!doctype html>')) {
                continue;
            }

            $checked++;

            $this->assertStringContainsString(
                'themeState->attributes()',
                (string) file_get_contents($file),
                basename($file) . ' renders a page but does not publish the theme decision.'
            );
        }

        $this->assertGreaterThanOrEqual(7, $checked, 'Expected to find the page layouts.');
    }

    /* =====================================================================
     | Profile page
     =================================================================== */

    public function test_the_profile_offers_all_three_states(): void
    {
        $user = User::factory()->create(['theme_preference' => 'dark']);

        $html = $this->actingAs($user)->get(route('profile.index'))->assertOk()->getContent();

        foreach (array_keys(config('theme.modes')) as $mode) {
            $this->assertStringContainsString('name="theme"', $html);
            $this->assertStringContainsString('value="' . $mode . '"', $html);
        }

        // The saved choice is pre-selected, not silently reset.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="theme"[^>]*value="dark"[^>]*checked/',
            $html
        );
    }

    public function test_the_profile_distinguishes_never_chosen_from_chosen_system(): void
    {
        $user = User::factory()->create(['theme_preference' => null]);

        $neverChosen = $this->actingAs($user)->get(route('profile.index'))->getContent();
        $this->assertStringContainsString('following your device', $neverChosen);

        $user->forceFill(['theme_preference' => 'light'])->save();

        $chosen = $this->actingAs($user)->get(route('profile.index'))->getContent();
        $this->assertStringContainsString('You chose this for your account', $chosen);
    }

    public function test_the_switch_is_offered_to_guests_and_members_alike(): void
    {
        // A signed-out visitor has as much right to a dark page as a member does.
        $this->get(route('home'))->assertOk()->assertSee('data-testid="theme-switch"', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-testid="theme-switch"', false);
    }

    /* =====================================================================
     | The stylesheet contract
     =================================================================== */

    public function test_the_built_stylesheet_themes_through_runtime_variables(): void
    {
        /*
         * This is the assertion that would have caught the first version of this
         * feature. It compiled, it looked right, and it did nothing: `@theme
         * inline` had substituted the primitive values at build time, so
         * `.bg-surface` was emitted as `background-color: var(--color-ink-100)`
         * and re-pointing `--color-surface` for a theme had no effect whatsoever.
         *
         * A utility therefore has to name the *token*, not the primitive. This is
         * asserted on the emitted rule text rather than by grepping for the token
         * name, because the token name appears in the `:root` declarations
         * regardless — only the utility itself proves the wiring.
         */
        $css = $this->compiledStylesheet();

        /*
         * Normalise first: Tailwind groups selectors that share a declaration
         * (`.bg-surface,.bg-surface\/95{…}`) and leaves newlines between rules, so
         * an exact-string assertion is brittle in a way that has nothing to do
         * with whether the theme works.
         */
        $flat = preg_replace('/\s+/', '', $css);

        $this->assertStringContainsString(
            'background-color:var(--color-surface)',
            $flat,
            'Tailwind inlined a primitive into the surface utilities; .bg-surface does not read the token.'
        );

        // The build-time key must point at the runtime token, or the whole
        // semantic layer is decorative. This is the exact line whose absence made
        // the first attempt compile cleanly and do nothing.
        $this->assertStringContainsString(
            '--color-surface:var(--color-surface)',
            $flat,
            'The @theme declaration for --color-surface is not wired to the runtime token.'
        );

        // And the shadow scale is a token too, so overlays deepen with the theme
        // instead of staying at a light-mode alpha. Asserted structurally, since
        // the emitted value contains spaces that the normalisation above removes.
        $this->assertMatchesRegularExpression(
            '/--shadow-overlay:[^;]*var\(--shadow-tint-strong\)/',
            $css,
            'The overlay shadow must be built from the theme-dependent tint.'
        );
    }

    public function test_the_built_stylesheet_defines_a_dark_block(): void
    {
        $css = $this->compiledStylesheet();

        // The selector the boot script writes. Specificity is one element above
        // `:root` on purpose, so it wins on merit rather than on source order.
        $this->assertStringContainsString('html[data-theme=dark]', $css);
        $this->assertMatchesRegularExpression(
            '/html\[data-theme=dark\]\{[^}]*--color-background:\s*#[0-9a-f]{6}/i',
            $css
        );

        // The footer is the one panel that stays dark in both themes; if it ever
        // starts tracking the page plane, a bright band appears at the bottom of
        // every dark page.
        $this->assertStringContainsString('--color-footer-surface', $css);
    }

    public function test_the_dark_palette_covers_the_alert_tints(): void
    {
        /*
         * The application has ~340 raw palette utilities in its views
         * (`border-amber-200 bg-amber-50 text-amber-900` on an advisory). Those
         * are pale tints chosen against white paper, so the dark theme re-points
         * them per hue. Losing this mapping does not break a page — it produces
         * glowing pastel boxes on a black background, which is precisely the kind
         * of regression a status-code test cannot see.
         */
        $css = $this->compiledStylesheet();

        preg_match('/html\[data-theme=dark\]\{(.*?)\}/s', $css, $matches);
        $this->assertNotEmpty($matches, 'The dark theme block is missing.');

        $dark = $matches[1];

        foreach (['red', 'amber', 'green', 'sky', 'blue', 'violet'] as $hue) {
            $this->assertStringContainsString("--color-{$hue}-50:", $dark, "{$hue}-50 has no dark value.");
            $this->assertStringContainsString("--color-{$hue}-100:", $dark, "{$hue}-100 has no dark value.");
        }
    }

    /**
     * The compiled stylesheet, located through the build manifest.
     */
    private function compiledStylesheet(): string
    {
        $manifestPath = public_path('build/manifest.json');

        $this->assertFileExists(
            $manifestPath,
            'Run `npm run build` — the dark theme is asserted against the compiled stylesheet.'
        );

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $entry = $manifest['resources/css/app.css'] ?? null;

        $this->assertNotNull($entry, 'resources/css/app.css is missing from the Vite manifest.');

        $file = public_path('build/' . ltrim((string) $entry['file'], '/'));

        $this->assertFileExists($file, 'The manifest points at a stylesheet that does not exist.');

        return (string) file_get_contents($file);
    }
}

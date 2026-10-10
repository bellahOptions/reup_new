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

    public function test_the_light_page_plane_is_true_white(): void
    {
        /*
         * The light theme is drawn on a genuine white canvas, not an off-white
         * tint. This is a product decision rather than an aesthetic one: with a
         * near-white page the surfaces above it (`.bg-surface-muted` on a table
         * head, `.bg-surface-strong` on a control) read as the same colour, so
         * the whole page looks washed rather than layered.
         *
         * Asserted on the compiled stylesheet because the failure is silent: a
         * near-white page renders fine and simply looks slightly wrong, which no
         * status-code test can see.
         */
        $css = $this->compiledStylesheet();

        /*
         * Anchored on the font stack rather than `/:root\{/`, which now matches
         * the ink-ramp block (`:root:root`) first — that block is emitted before
         * the semantic one, so the naive pattern silently asserted against the
         * ramp instead of the theme.
         */
        preg_match('/:root\{--font-sans:(.*?)\}/s', $css, $matches);
        $this->assertNotEmpty($matches, 'The light theme block is missing.');

        $light = $matches[1];

        // `#fff` / `#ffffff` — Tailwind minifies the literal.
        $this->assertMatchesRegularExpression(
            '/--color-surface-page:\s*#fff(?:fff)?\b/i',
            $light,
            'The light page plane is no longer a true white.'
        );

        $this->assertMatchesRegularExpression(
            '/--color-background:\s*var\(--color-surface-page\)/',
            $light,
            'The page background must follow the surface plane token.'
        );

        // The page and the surface are the same plane here — there is no outer
        // canvas — so `.bg-surface`, which every customer page opens with, has
        // to resolve to it too. A tinted surface under a white page is the
        // off-white the design decision was meant to remove.
        $this->assertMatchesRegularExpression(
            '/--color-surface:\s*var\(--color-surface-page\)/',
            $light,
            'The card surface must sit on the same white plane as the page.'
        );

        // And the value the browser paints its own chrome with agrees.
        $this->assertSame('#ffffff', config('theme.theme_color.light'));
    }

    public function test_the_dark_block_defines_a_dark_page_plane(): void
    {
        // The inverse of the above: whatever light mode does, dark mode must not
        // inherit a white canvas.
        $css = $this->compiledStylesheet();

        preg_match('/html\[data-theme=dark\]\{(.*?)\}/s', $css, $matches);
        $this->assertNotEmpty($matches, 'The dark theme block is missing.');

        $this->assertMatchesRegularExpression(
            '/--color-surface-page:\s*#0e100e/i',
            $matches[1],
            'The dark page plane must stay dark.'
        );
    }

    public function test_the_dark_block_re_points_the_ink_ramp(): void
    {
        /*
         * This is the bug that made the dark home page render its headline in
         * near-black on near-black.
         *
         * The *semantic* layer inverted correctly, but the views also reach for
         * the **primitives** about 250 times — `text-ink-950` on the hero,
         * `bg-ink-100 text-ink-500` on every empty-state circle, `text-ink-600`
         * on menu items. A primitive reads no token, so re-pointing the
         * semantic layer moved none of them: `text-ink-950` stayed `#0d100d` in
         * both themes, which is invisible on `#0e100e`.
         *
         * No status code, markup assertion or contrast check on the *semantic*
         * tokens would catch it, so the ramp's presence in the dark block is
         * asserted directly.
         */
        $css = $this->compiledStylesheet();

        preg_match('/html\[data-theme=dark\]\{(.*?)\}/s', $css, $matches);
        $this->assertNotEmpty($matches, 'The dark theme block is missing.');

        $dark = $matches[1];

        foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950] as $step) {
            $this->assertMatchesRegularExpression(
                '/--color-ink-' . $step . ':\s*#[0-9a-f]{6}/i',
                $dark,
                "ink-{$step} has no dark value, so every text-ink-{$step} in the views is frozen at its light-mode colour."
            );
        }

        // The extremes are the ones that carry text, and they must have actually
        // swapped ends of the ramp rather than merely being present. Matched
        // inside the dark block: `--color-ink-950` is also declared in the
        // `@theme` block above it, and that is the *light* value.
        preg_match('/--color-ink-950:\s*(#[0-9a-f]{6})/i', $dark, $top);
        preg_match('/--color-ink-900:\s*(#[0-9a-f]{6})/i', $dark, $next);
        preg_match('/--color-ink-50:\s*(#[0-9a-f]{6})/i', $dark, $lightestStep);

        $this->assertNotSame('#0d100d', strtolower($top[1]), 'ink-950 is still the light-mode near-black.');
        $this->assertGreaterThan(
            0.8,
            $this->relativeLuminance($next[1]),
            'ink-900 must be light in dark mode; it is the highest-contrast text step.'
        );
        $this->assertLessThan(
            0.1,
            $this->relativeLuminance($lightestStep[1]),
            'ink-50 must be the darkest step in dark mode, mirroring its role in light mode.'
        );
    }

    public function test_the_footer_stays_readable_on_its_dark_panel(): void
    {
        /*
         * The footer keeps a dark panel in *both* themes, so it is the one place
         * the ink ramp's inversion must not reach. `--color-footer-faint` is the
         * only semantic token still pointing at an ink step, so left to the
         * mirror it would resolve to `#6f7a6f` — 3.6:1 on `#0b0e0b`.
         */
        $css = $this->compiledStylesheet();

        preg_match('/html\[data-theme=dark\]\{(.*?)\}/s', $css, $matches);
        $dark = $matches[1];

        preg_match('/--color-footer-faint:\s*(#[0-9a-f]{6})/i', $dark, $faint);
        $this->assertNotEmpty($faint, '--color-footer-faint has no dark value and would follow the mirrored ramp.');

        $this->assertGreaterThan(
            4.5,
            $this->contrastRatio($faint[1], '#0b0e0b'),
            'The faintest footer text must clear AA against the footer panel.'
        );
    }

    public function test_the_scrim_is_dark_in_both_themes(): void
    {
        /*
         * A modal overlay dims what is behind it, so it is dark by intent rather
         * than by theme. Before this token existed the views wrote
         * `bg-ink-950/50` — which, once the ink ramp inverts, becomes a
         * near-white wash over a dark page: the opposite of dimming.
         */
        $css = $this->compiledStylesheet();

        // Same anchoring as the page-plane test: `/:root\{/` matches the ink
        // ramp's `:root:root` block first and would assert against that.
        $this->assertMatchesRegularExpression(
            '/:root\{--font-sans:[^}]*--color-scrim:\s*(#[0-9a-f]{6})/i',
            $css,
            'The scrim token is missing from the light block.'
        );

        preg_match('/:root\{--font-sans:[^}]*--color-scrim:\s*(#[0-9a-f]{6})/i', $css, $scrim);
        $this->assertNotEmpty($scrim, 'The scrim token is missing from the light block.');

        $this->assertGreaterThan(
            0.9,
            $this->contrastRatio(trim($scrim[1]), '#ffffff'),
            'The scrim must be dark against a light page.'
        );

        // And no view goes back to the primitive, which is what inverts.
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), 'bg-ink-950')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These views use bg-ink-950 as an overlay. Use bg-scrim instead: ink-950 inverts for dark mode, '
            . 'so the overlay would become a bright wash over a dark page.'
        );
    }

    public function test_the_ink_ramp_is_declared_once_per_theme_and_outranks_tailwinds_copy(): void
    {
        /*
         * The cascade here is genuinely load-bearing, and each of the three
         * attempts at it failed in a way that looked correct:
         *
         *   1. values in `@theme` — Tailwind emits that as `:root, :host` in its
         *      own layer *and* re-emits the plain properties it saw in this file
         *      as an unlayered `:root` near the bottom, so the light ramp became
         *      the value for every theme;
         *   2. `html:root` for the light ramp — outranked the dark block's
         *      element+attribute selector's *specificity budget* and pinned both
         *      themes to light;
         *   3. `:root:root` alone — tied with Tailwind's generated copy and lost
         *      on source order, because that copy is emitted after the dark
         *      block.
         *
         * What works: `:root:root` for the light values, and the dark values in
         * the theme block marked `!important` so they cannot be outranked by a
         * rule Tailwind writes later.
         *
         * The observable symptom of all three failures was identical and silent:
         * `text-ink-950` — the home hero's headline — resolved to a near-black on
         * a near-black page. The primitive is not readable from the semantic
         * tokens, so nothing else caught it.
         */
        $css = $this->compiledStylesheet();

        // The light ramp, in a rule that can outrank Tailwind's generated copy.
        $this->assertMatchesRegularExpression(
            '/:root:root\{[^}]*--color-ink-50:\s*#f7f8f7/',
            $css,
            'The light ink ramp must be declared under `:root:root` so it outranks the generated `:root` copy.'
        );

        // And the dark ramp must be able to outrank *that*.
        $this->assertMatchesRegularExpression(
            '/html\[data-theme=dark\]\{[^}]*--color-ink-950:\s*#f7f8f7\s*!important/',
            $css,
            'The dark ink ramp must be `!important`, or Tailwind\'s later `:root` copy wins and the page stays light.'
        );

        // No ink *values* may sit in the theme layer, where they would apply to
        // every theme regardless of invertibility.
        preg_match('/@layer theme\{:root,:host\{(.*?)\}\}/s', $css, $themeLayer);
        $this->assertNotEmpty($themeLayer, 'The Tailwind theme layer is missing.');

        preg_match_all('/--color-ink-\d+:\s*([^;]+);/', $themeLayer[1], $inLayer);
        foreach ($inLayer[1] as $value) {
            $this->assertStringContainsString(
                'var(--color-ink-',
                $value,
                'An ink value is declared in @theme, which becomes the :root value for every theme.'
            );
        }
    }

    public function test_the_brand_fill_label_inverts_with_the_fill(): void
    {
        /*
         * `white on brand-500` is 2.67:1 and `white on brand-400` is 1.79:1 —
         * the dark theme runs the ramp from its 400 step, so a white label on a
         * primary button is unreadable there while looking correct in light mode.
         * The label has to invert with the fill.
         */
        $css = $this->compiledStylesheet();

        preg_match('/html\[data-theme=dark\]\{(.*?)\}/s', $css, $matches);
        $dark = $matches[1];

        $this->assertMatchesRegularExpression(
            '/--color-primary-foreground:\s*#0d100d/',
            $dark,
            'The primary label must be dark in dark mode: the brand fill is a light green there.'
        );

        // And it must be a literal, not a var() chain — see the note in the CSS.
        $this->assertDoesNotMatchRegularExpression(
            '/--color-primary-foreground:\s*var\(/',
            $dark,
            'A var() here resolves against the value in scope at declaration time and picks up the light ink-950.'
        );
    }

    /**
     * WCAG relative luminance, for the two contrast assertions above.
     */
    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;

            $channels[] = $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function contrastRatio(string $a, string $b): float
    {
        $la = $this->relativeLuminance($a);
        $lb = $this->relativeLuminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
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

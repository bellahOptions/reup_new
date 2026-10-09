<?php

namespace App\Support;

use App\Models\User;

/**
 * Appearance resolution.
 *
 * One question is asked in five places — the page head, the profile page, the
 * navbar switch, the admin settings form and the tests — so it is answered in
 * one place:
 *
 *   1. the account's own choice, when it has made one;
 *   2. otherwise the site-wide default, when an administrator set one;
 *   3. otherwise `system`, which hands the decision to the device.
 *
 * Everything here returns one of config('theme.modes') or the literal `system`,
 * never a raw database value. That matters because `system` is not a theme: it
 * is an instruction, and the browser turns it into `light` or `dark` before the
 * first paint. Keeping the two concepts apart is what lets a stored choice of
 * "follow my device" survive the user moving between a light laptop and a dark
 * phone.
 */
class Theme
{
    /** The mode used when nothing is stored, or what is stored is unusable. */
    public static function defaultMode(): string
    {
        $mode = config('theme.default_mode', 'system');

        return self::isValid($mode) ? $mode : 'system';
    }

    /**
     * What `system` resolves to on a client that reports no preference.
     *
     * The browser is authoritative — this only decides what a *server-rendered*
     * page assumes before the inline script has run, and what the `theme-color`
     * meta publishes to a client that never runs the script at all.
     */
    public static function fallbackMode(): string
    {
        $fallback = config('theme.fallback', 'light');

        return in_array($fallback, ['light', 'dark'], true) ? $fallback : 'light';
    }

    public static function isValid(?string $mode): bool
    {
        return $mode !== null && array_key_exists($mode, config('theme.modes', []));
    }

    /**
     * Coerce any stored value into a usable mode.
     *
     * A NULL column, an empty string, or a slug that a later release dropped all
     * land on the default. Without this, a stale row would render
     * `data-theme="oil-slick"`, which matches no rule in the stylesheet and
     * therefore silently renders the light theme — a bug that looks like the
     * preference "not saving".
     */
    public static function normalise(?string $mode): string
    {
        return self::isValid($mode) ? $mode : self::defaultMode();
    }

    /**
     * The site-wide default chosen by an administrator.
     *
     * `site_settings` is read through the cached `site.settings` array the rest
     * of the application uses, in a try/catch because this runs from the
     * head partial on every page: a deployment whose `site_settings` table has
     * not been created yet must still render, just without a site default.
     */
    public static function siteDefault(): string
    {
        try {
            $value = setting('default_theme');
        } catch (\Throwable $e) {
            return self::defaultMode();
        }

        // 'system' is a valid answer here and means "no opinion", so an
        // administrator choosing it is indistinguishable from never having
        // chosen — which is the intent.
        return self::isValid($value) ? $value : self::defaultMode();
    }

    /**
     * The mode to render for a visitor: their own choice, else the site's.
     */
    public static function modeFor(?User $user): string
    {
        if ($user !== null) {
            $preference = $user->getAttribute('theme_preference');

            if (self::isValid($preference)) {
                return $preference;
            }
        }

        return self::siteDefault();
    }

    /**
     * Whether the account has made an explicit choice.
     *
     * The profile form needs the distinction: an account that has never chosen
     * must show "System" as selected while still being able to tell the two
     * states apart in its own markup.
     */
    public static function hasExplicitChoice(?User $user): bool
    {
        return $user !== null && self::isValid($user->getAttribute('theme_preference'));
    }

    /**
     * The colour the browser paints its own chrome with, per theme.
     */
    public static function colorFor(string $resolved): string
    {
        return config('theme.theme_color.' . $resolved, '#f7f8f7');
    }
}

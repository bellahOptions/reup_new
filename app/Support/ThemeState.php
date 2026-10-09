<?php

namespace App\Support;

/**
 * One request's appearance state.
 *
 * The three values here have to agree with each other, so they are decided once
 * and carried together:
 *
 *   requested  what the account or the device asked for — 'system', 'light' or
 *              'dark'. 'system' is a real choice, not an absence.
 *   resolved   what the server renders on <html> right now. It can only be an
 *              explicit choice: 'system' depends on the device, whose preference
 *              is not sent with the request. So a 'system' request resolves to
 *              the configured fallback and the inline script in
 *              partials/theme corrects it before the first paint.
 *   persists   whether the choice belongs to the account (signed in) or to the
 *              device (a signed-out visitor). The switch needs this to know
 *              whether to post to the server or write to localStorage.
 *
 * Resolved in ViewServiceProvider and shared with every layout, because a Blade
 * `@include` does not leak variables back to the view that included it — the
 * layout needs these values in its own `<html>` tag, so a partial cannot supply
 * them.
 */
class ThemeState
{
    public function __construct(
        public readonly string $requested,
        public readonly string $resolved,
        public readonly string $persists,
        public readonly string $endpoint,
    ) {
    }

    public static function forCurrentRequest(): self
    {
        $user = auth()->user();

        $requested = Theme::modeFor($user);

        return new self(
            requested: $requested,
            resolved: $requested === 'system' ? Theme::fallbackMode() : $requested,
            persists: $user ? 'server' : 'device',
            // Guests have no endpoint: their choice is stored on the device, so
            // the switch never issues a request. An empty attribute is what the
            // Alpine component reads to decide.
            endpoint: $user ? route('profile.theme', [], false) : '',
        );
    }

    /**
     * The attributes for the opening `<html>` tag, pre-rendered so a layout can
     * drop them in with a single `{!! !!}` and not break the tag across lines.
     */
    public function attributes(): string
    {
        return implode(' ', [
            'data-theme="' . $this->resolved . '"',
            'data-requested-theme="' . $this->requested . '"',
            'data-theme-persist="' . $this->persists . '"',
            'data-theme-modes="' . implode(',', array_keys(config('theme.modes', []))) . '"',
            'data-theme-endpoint="' . $this->endpoint . '"',
        ]);
    }

    /** The background colour the browser paints its own chrome with. */
    public function themeColor(): string
    {
        return Theme::colorFor($this->resolved);
    }

    /** The light-mode chrome colour, for the media-scoped meta pair. */
    public function lightColor(): string
    {
        return Theme::colorFor('light');
    }

    public function darkColor(): string
    {
        return Theme::colorFor('dark');
    }
}

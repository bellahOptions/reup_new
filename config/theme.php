<?php

/*
|--------------------------------------------------------------------------
| Appearance
|--------------------------------------------------------------------------
| Three states, not two.
|
| "Light" and "Dark" are self-explanatory; "system" is the absence of a
| choice, and it is the default. A two-state switch has to guess what a new
| visitor wants the moment they arrive, and whatever it guesses is wrong for
| somebody — a customer on a dark phone who is shown a white page has been
| given a reason to leave before they have read anything. Following the
| device costs nothing here because the resolution happens in the browser
| before the first paint (see partials/theme.blade.php), so there is no flash
| of the wrong theme to trade against it.
|
| Values are stored as short slugs on `users.theme_preference`, so the wording
| shown in the UI can change without touching the data.
*/

return [

    /*
    | The three states, in the order they cycle. `label` is what the profile
    | radio group announces; `hint` explains the consequence, which is the part
    | people actually need ("uses your device setting", not just "System").
    */
    'modes' => [
        'system' => [
            'label' => 'System',
            'hint' => 'Follow this device’s light or dark setting.',
            'icon' => 'computer-desktop',
        ],
        'light' => [
            'label' => 'Light',
            'hint' => 'Always use the light theme.',
            'icon' => 'sun',
        ],
        'dark' => [
            'label' => 'Dark',
            'hint' => 'Always use the dark theme.',
            'icon' => 'moon',
        ],
    ],

    /*
    | The marker stored on a user row that means "no explicit choice".
    |
    | `users.theme_preference` is nullable and NULL means exactly this, but the
    | literal is also used as the fallback when the column is missing (an
    | un-migrated deployment) or holds a value that is no longer in `modes`.
    */
    'default_mode' => 'system',

    /*
    | What `system` resolves to when the browser reports no preference at all.
    |
    | `prefers-color-scheme` is supported everywhere this application is used,
    | so in practice this only applies to a client that reports neither — and
    | for those, light is the safer assumption.
    */
    'fallback' => 'light',

    /*
    | `site_settings.default_theme` — the site-wide default, chosen by an
    | administrator on /admin/settings. 'system' means "no site-wide opinion",
    | which is the shipped default.
    |
    | This is the *third* level of the chain and only applies to a signed-out
    | visitor or an account that has never chosen: user choice, then site
    | default, then this.
    */
    'site_default_mode' => 'system',

    /*
    | The `theme-color` the browser paints its own chrome with (the address bar
    | on Android Chrome, the status bar in an installed PWA). Kept in config
    | rather than hard-coded in the head partial because it has to agree with
    | `--color-background` in resources/css/app.css, and the two are read by
    | different people for different reasons.
    */
    'theme_color' => [
        /* Must match `--color-background` per theme in app.css: light is
           --color-ink-50, dark is the dark plane. */
        'light' => '#f7f8f7',
        'dark' => '#0e100e',
    ],
];

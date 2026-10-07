<?php

/*
|--------------------------------------------------------------------------
| Avatars
|--------------------------------------------------------------------------
| A customisable avatar is a background colour plus an optional glyph, rendered
| behind the user's initials. It replaced the photo-upload-only experience,
| which defaulted every new account to a grey box and had no good answer for a
| file that failed to upload, was the wrong crop, or was never set.
|
| Both lists are keyed by short slugs that are stored on the user row, so the
| palette and the glyph set can be re-themed without a data migration.
|
| Colours are named rather than "option 1..8" because the name is what the
| picker exposes to assistive technology — "Forest green" is announced,
| "#15803d" is not.
*/
return [

    /*
    | Background palettes. `from`/`to` drive a subtle two-stop gradient so the
    | avatar reads as designed rather than as a flat swatch.
    */
    'colors' => [
        'brand' => ['label' => 'Signature green', 'from' => '#34c60f', 'to' => '#1f8f0a', 'ink' => 'dark'],
        'forest' => ['label' => 'Forest', 'from' => '#16a34a', 'to' => '#065f46', 'ink' => 'dark'],
        'ocean' => ['label' => 'Ocean', 'from' => '#0ea5e9', 'to' => '#075985', 'ink' => 'dark'],
        'indigo' => ['label' => 'Indigo', 'from' => '#6366f1', 'to' => '#3730a3', 'ink' => 'dark'],
        'plum' => ['label' => 'Plum', 'from' => '#a855f7', 'to' => '#6b21a8', 'ink' => 'dark'],
        'rose' => ['label' => 'Rose', 'from' => '#f43f5e', 'to' => '#9f1239', 'ink' => 'dark'],
        'amber' => ['label' => 'Amber', 'from' => '#f59e0b', 'to' => '#b45309', 'ink' => 'light'],
        'slate' => ['label' => 'Slate', 'from' => '#64748b', 'to' => '#1e293b', 'ink' => 'dark'],
    ],

    /*
    | Optional glyph. Null (absent from the row) means "initials only", which is
    | the default and the calmest option — most people should probably keep it.
    */
    'icons' => [
        'user' => 'Person',
        'sparkles' => 'Sparkle',
        'bolt' => 'Bolt',
        'star' => 'Star',
        'shield-check' => 'Shield',
        'rocket-launch' => 'Rocket',
        'globe-alt' => 'Globe',
        'heart' => 'Heart',
    ],

    'default_color' => 'brand',
];

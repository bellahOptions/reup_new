<?php

/*
|--------------------------------------------------------------------------
| Announcements
|--------------------------------------------------------------------------
| Icons are stored as icon-component names, NOT emoji.
|
| The seeder previously planted emoji ('🎉', '🔥') and one view carried a
| hand-written emoji→icon map to translate them at render time. That meant the
| icon was unusable everywhere except that single page, and the admin form had
| no way to set one at all. Storing the name directly makes the value renderable
| everywhere and validatable on save.
|
| Every key here must exist in resources/views/components/icon.blade.php — the
| admin form validates against this list, and the marquee falls back to a
| megaphone if a stored value is ever unknown.
*/
return [

    'icons' => [
        'megaphone' => 'Megaphone',
        'sparkles' => 'Sparkles',
        'fire' => 'Fire',
        'gift' => 'Gift',
        'star' => 'Star',
        'bolt' => 'Bolt',
        'bell' => 'Bell',
        'information-circle' => 'Information',
        'exclamation-triangle' => 'Warning',
        'document-text' => 'Document',
        'light-bulb' => 'Idea',
        'clock' => 'Time limit',
    ],

    /*
    | Badge shown when an announcement has no badge text of its own. Keyed by
    | type so the three types stay distinguishable in the marquee, since all
    | three now render.
    */
    'type_badges' => [
        'promotion' => 'Promo',
        'notification' => 'Notice',
        'news' => 'News',
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy emoji icons
    |--------------------------------------------------------------------------
    | Rows created before icons were stored as names hold emoji. Rather than
    | leaving every consumer to carry its own translation table (one view had a
    | hand-written copy, which is why the icon rendered on exactly one page), the
    | mapping lives here and is applied once when the value is read.
    |
    | New rows should use a name from `icons` above.
    */
    'legacy_emoji' => [
        "\u{1F389}" => 'sparkles',               // party popper
        "\u{1F525}" => 'fire',                   // fire
        "\u{26A0}\u{FE0F}" => 'exclamation-triangle',
        "\u{26A0}" => 'exclamation-triangle',    // warning sign
        "\u{1F4F0}" => 'document-text',          // newspaper
        "\u{1F4E2}" => 'megaphone',              // loudspeaker
        "\u{1F381}" => 'gift',                   // wrapped gift
        "\u{2B50}" => 'star',                    // star
        "\u{1F4A1}" => 'light-bulb',             // light bulb
    ],
];
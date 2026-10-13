<?php

/**
 * Social Boost (SMM) presentation and catalogue classification.
 *
 * ## What belongs here
 *
 * Names, icons and shopping categories. None of it affects money — the pricing
 * floor lives in `config/pricing.php` next to the other profitability controls,
 * because that is where an operator looks for it and where the tests for it
 * already are.
 *
 * ## Why the classification is configuration rather than code
 *
 * Nitro publishes a `type` per service (`Default`, `Custom Comments`, `SEO`,
 * `Package`) which describes the *shape of the order* — whether the buyer must
 * supply comments, usernames or keywords — and a `category`, which is the
 * platform. Neither is a shopping category: a customer looking for "Instagram
 * followers" does not think in terms of `Default`.
 *
 * So the customer-facing type is derived from the service name, by the keyword
 * lists below. They are configuration because a provider rewords services
 * constantly and adding a keyword should not be a deploy — and because a keyword
 * that turns out to be too broad (matching "Likes" inside "Likes Dislikes") is a
 * one-line correction rather than a hotfix.
 *
 * The order matters: the first label whose keywords match wins, so a more
 * specific list must come before a more general one.
 */
return [

    /*
    | ---------------------------------------------------------------------
    | Platforms
    | ---------------------------------------------------------------------
    | Keyed by the slug derived from Nitro's `category` (lower-cased, stripped
    | of anything that is not a letter or digit), so `TikTok`, `Tiktok` and
    | `TIK TOK` are one platform rather than three.
    |
    | `icon` is a bootstrap-icons name, which the existing `x-icon` component
    | already resolves — the whole icon set ships in `public/images/icons`, so a
    | new platform needs no asset.
    |
    | A platform absent from this list is not hidden: it renders with a
    | title-cased key and a neutral icon, so a platform that appears upstream is
    | sellable immediately and can be named properly afterwards. Falling back to
    | hiding it would mean a provider adding a platform silently removes revenue.
    */
    'platforms' => [
        'instagram' => [
            'label' => 'Instagram',
            'icon' => 'instagram',
            'blurb' => 'Followers, likes, views and story engagement.',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'icon' => 'music-note-beamed',
            'blurb' => 'Followers, likes, shares and video views.',
        ],
        'twitter' => [
            'label' => 'X (Twitter)',
            'icon' => 'twitter-x',
            'blurb' => 'Followers, retweets, likes and impressions.',
        ],
        'x' => [
            'label' => 'X (Twitter)',
            'icon' => 'twitter-x',
            'blurb' => 'Followers, retweets, likes and impressions.',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'icon' => 'facebook',
            'blurb' => 'Page likes, post likes, followers and views.',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'icon' => 'youtube',
            'blurb' => 'Subscribers, views, likes and watch time.',
        ],
        'telegram' => [
            'label' => 'Telegram',
            'icon' => 'telegram',
            'blurb' => 'Channel members, post views and reactions.',
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'icon' => 'whatsapp',
            'blurb' => 'Channel followers and message reactions.',
        ],
        'discord' => [
            'label' => 'Discord',
            'icon' => 'discord',
            'blurb' => 'Server members and online members.',
        ],
        'spotify' => [
            'label' => 'Spotify',
            'icon' => 'spotify',
            'blurb' => 'Plays, followers and playlist adds.',
        ],
        'snapchat' => [
            'label' => 'Snapchat',
            'icon' => 'snapchat',
            'blurb' => 'Followers, views and story engagement.',
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'icon' => 'linkedin',
            'blurb' => 'Connections, followers and post engagement.',
        ],
        'audiomack' => [
            'label' => 'Audiomack',
            'icon' => 'music-note-list',
            'blurb' => 'Plays, followers and favourites.',
        ],
        'boomplay' => [
            'label' => 'Boomplay',
            'icon' => 'music-note-list',
            'blurb' => 'Streams and followers.',
        ],
        'twitch' => [
            'label' => 'Twitch',
            'icon' => 'twitch',
            'blurb' => 'Followers and live viewers.',
        ],
        'pinterest' => [
            'label' => 'Pinterest',
            'icon' => 'pinterest',
            'blurb' => 'Followers, repins and saves.',
        ],
        'threads' => [
            'label' => 'Threads',
            'icon' => 'threads',
            'blurb' => 'Followers, likes and reposts.',
        ],
        'reddit' => [
            'label' => 'Reddit',
            'icon' => 'reddit',
            'blurb' => 'Upvotes, members and post engagement.',
        ],
        'tumblr' => [
            'label' => 'Tumblr',
            'icon' => 'globe',
            'blurb' => 'Followers, reblogs and likes.',
        ],
        'clubhouse' => [
            'label' => 'Clubhouse',
            'icon' => 'globe',
            'blurb' => 'Followers and room listeners.',
        ],
        'shazam' => [
            'label' => 'Shazam',
            'icon' => 'music-note-list',
            'blurb' => 'Shazams and plays.',
        ],
        'soundcloud' => [
            'label' => 'SoundCloud',
            'icon' => 'globe',
            'blurb' => 'Plays, followers, likes and reposts.',
        ],
        'quora' => [
            'label' => 'Quora',
            'icon' => 'quora',
            'blurb' => 'Followers, upvotes and answer views.',
        ],
        'google' => [
            'label' => 'Google',
            'icon' => 'google',
            'blurb' => 'Reviews and map engagement.',
        ],
        'website' => [
            'label' => 'Website traffic',
            'icon' => 'globe2',
            'blurb' => 'Visits and keyword traffic.',
        ],
        'seo' => [
            'label' => 'SEO',
            'icon' => 'graph-up-arrow',
            'blurb' => 'Search visibility and keyword ranking.',
        ],
    ],

    /*
    | ---------------------------------------------------------------------
    | Customer-facing service types
    | ---------------------------------------------------------------------
    | Derived from the service name so the filter reads the way a customer
    | shops. First match wins, so the more specific lists come first —
    | "Story Views" must be checked before "Views".
    |
    | Matching is case-insensitive substring, which is forgiving in both
    | directions: a provider adding "(Instant)" to a name does not create a new
    | category, and a genuinely new kind of service falls to `Other` rather than
    | being misfiled into whichever label happens to be checked first.
    */
    'service_types' => [
        'Story views' => ['story view', 'storyviews'],
        'Live stream' => ['live stream', 'livestream'],
        'Comments' => ['comment'],
        'Reviews' => ['review'],
        'Shares' => ['share', 'retweet', 'repost'],
        'Views' => ['view', 'play', 'impression', 'stream'],
        'Subscribers' => ['subscriber', 'member', 'join'],
        'Followers' => ['follower', 'connection'],
        'Likes' => ['like', 'reaction', 'favourite', 'favorite', 'upvote', 'save'],
        'Watch time' => ['watch time', 'watchtime', 'hours'],
        'Verification' => ['verif'],
    ],

    /*
    | ---------------------------------------------------------------------
    | Requirement detection
    | ---------------------------------------------------------------------
    | Nitro's `description` mixes selling copy with setup the buyer must complete
    | — the Discord bot invitation, a link that must not expire, a profile that
    | must be public. The order page shows the sentences containing one of these
    | keywords as "Before you order", because a customer who does not complete the
    | setup has paid for a service that cannot be delivered.
    |
    | The alternative — showing the whole description as a warning — trains people
    | to skip it, which is worse than showing nothing.
    */
    'requirement_keywords' => [
        'required setup',
        'must be',
        'must have',
        'must not',
        'set to',
        'add the bot',
        'make sure',
        'please note',
        'before order',
        'before you order',
        'need to',
        'profile must',
        'account must',
        'public',
        'private',
        'never expire',
        'invite link',
    ],

    /*
    | ---------------------------------------------------------------------
    | Quantity step
    | ---------------------------------------------------------------------
    | Nitro publishes a minimum and a maximum and no increment, so the honest
    | step is 1 — the provider accepts any integer in range. This exists so a
    | deployment that knows its services are sold in thousands can say so; it is
    | not a guess applied by default, because rounding a customer's quantity up to
    | a step would charge them for units they did not ask for.
    */
    'quantity_step' => (int) env('SMM_QUANTITY_STEP', 1),

    /*
    | ---------------------------------------------------------------------
    | Per-platform target validation
    | ---------------------------------------------------------------------
    | Each platform's target has a shape: a post URL, a profile URL, a bare
    | username, a channel invite. Validating it before the order is created turns
    | "the provider rejected your link" — which arrives minutes later, after a
    | wallet debit — into an inline error while the customer is still on the form.
    |
    | These patterns are *permissive on purpose*. A pattern that is too strict
    | refuses a legitimate target and the customer has no way to proceed; a
    | pattern that is too loose lets the provider reject it, which is the current
    | behaviour anyway. So each one accepts the documented forms plus anything
    | recognisably on the platform's own domain.
    |
    | `hint` is what the form shows as placeholder text, and it is the primary
    | mechanism — a customer who reads it usually needs no validation at all.
    */
    'targets' => [
        'instagram' => [
            'hint' => 'https://instagram.com/yourusername',
            'label' => 'Instagram profile or post link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?instagram\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'tiktok' => [
            'hint' => 'https://tiktok.com/@yourusername',
            'label' => 'TikTok profile or video link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?tiktok\.com/@?[A-Za-z0-9._/\-?=]+$~i',
        ],
        'twitter' => [
            'hint' => 'https://x.com/yourusername',
            'label' => 'X profile, post or trend link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?(x|twitter)\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'x' => [
            'hint' => 'https://x.com/yourusername',
            'label' => 'X profile, post or trend link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?(x|twitter)\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'facebook' => [
            'hint' => 'https://facebook.com/yourpage',
            'label' => 'Facebook page, profile or post link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.|m\.)?(facebook|fb)\.com/[A-Za-z0-9._/\-?=&]+$~i',
        ],
        'youtube' => [
            'hint' => 'https://youtube.com/watch?v=… or https://youtube.com/@channel',
            'label' => 'YouTube video or channel link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?(www\.|m\.)?(youtube\.com|youtu\.be)/[A-Za-z0-9._/\-?=&%@:]+$~i',
        ],
        'telegram' => [
            'hint' => 'https://t.me/yourchannel',
            'label' => 'Telegram channel or post link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?(t\.me|telegram\.me)/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'whatsapp' => [
            'hint' => 'https://whatsapp.com/channel/…',
            'label' => 'WhatsApp channel link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?(www\.)?(whatsapp\.com|wa\.me)/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'discord' => [
            'hint' => 'https://discord.gg/yourserver',
            'label' => 'Discord server invite link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?(www\.)?(discord\.gg|discord\.com|discordapp\.com)/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'spotify' => [
            'hint' => 'https://open.spotify.com/track/…',
            'label' => 'Spotify track, album or artist link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?(open\.|www\.)?spotify\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'snapchat' => [
            'hint' => 'https://snapchat.com/add/yourusername',
            'label' => 'Snapchat profile link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?snapchat\.com/[A-Za-z0-9._/\-?=@]+$~i',
        ],
        'linkedin' => [
            'hint' => 'https://linkedin.com/in/yourusername',
            'label' => 'LinkedIn profile, company or post link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?(www\.)?linkedin\.com/[A-Za-z0-9._/\-?=&]+$~i',
        ],
        'threads' => [
            'hint' => 'https://threads.net/@yourusername',
            'label' => 'Threads profile or post link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?threads\.(net|com)/[A-Za-z0-9._/\-?=@]+$~i',
        ],
        'reddit' => [
            'hint' => 'https://reddit.com/r/yoursubreddit',
            'label' => 'Reddit post or subreddit link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?(www\.|old\.)?reddit\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'pinterest' => [
            'hint' => 'https://pinterest.com/yourusername',
            'label' => 'Pinterest profile or pin link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?([a-z]{2}\.)?(www\.)?pinterest\.[a-z.]+/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'twitch' => [
            'hint' => 'https://twitch.tv/yourchannel',
            'label' => 'Twitch channel or video link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?twitch\.tv/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'audiomack' => [
            'hint' => 'https://audiomack.com/yourusername',
            'label' => 'Audiomack track or profile link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?audiomack\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'boomplay' => [
            'hint' => 'https://boomplay.com/…',
            'label' => 'Boomplay track or profile link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?(www\.)?boomplay\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'soundcloud' => [
            'hint' => 'https://soundcloud.com/yourusername',
            'label' => 'SoundCloud track or profile link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.|m\.)?soundcloud\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'shazam' => [
            'hint' => 'https://shazam.com/…',
            'label' => 'Shazam track link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?(www\.)?shazam\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'tumblr' => [
            'hint' => 'https://yourblog.tumblr.com',
            'label' => 'Tumblr blog or post link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?([A-Za-z0-9\-]+\.)?tumblr\.com/[A-Za-z0-9._/\-?=]*$~i',
        ],
        'quora' => [
            'hint' => 'https://quora.com/profile/yourname',
            'label' => 'Quora profile or answer link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?([a-z]{2}\.)?(www\.)?quora\.com/[A-Za-z0-9._/\-?=]+$~i',
        ],
        'clubhouse' => [
            'hint' => 'https://clubhouse.com/@yourusername',
            'label' => 'Clubhouse profile link',
            'accepts_username' => true,
            'pattern' => '~^(https?://)?(www\.)?(clubhouse|joinclubhouse)\.com/[A-Za-z0-9._/\-?=@]+$~i',
        ],
        'google' => [
            'hint' => 'https://maps.app.goo.gl/…',
            'label' => 'Google Maps or review link',
            'accepts_username' => false,
            'pattern' => '~^(https?://)?([a-z0-9\-]+\.)?(google\.[a-z.]+|goo\.gl|maps\.app\.goo\.gl)/[A-Za-z0-9._/\-?=&]+$~i',
        ],
    ],

    /*
    | ---------------------------------------------------------------------
    | The fallback target rule
    | ---------------------------------------------------------------------
    | Used for a platform with no entry above — a platform the provider added
    | since this file was last edited, or a `website`/`seo` service whose target
    | is an arbitrary URL.
    |
    | It accepts a bare domain, a full URL and a bare username, which is the
    | widest shape that is still recognisably a target. Refusing everything would
    | make a new platform unsellable on the day it appeared, which is a worse
    | failure than letting the provider be the judge.
    */
    'target_fallback' => [
        'hint' => 'https://example.com/your-page',
        'label' => 'Link or username',
        'accepts_username' => true,
        'pattern' => '~^(https?://)?[A-Za-z0-9][A-Za-z0-9._\-]*(\.[A-Za-z]{2,})+(/[A-Za-z0-9._/\-?=&%@:]*)?$~i',
    ],
];

<?php

namespace App\Lifecycle;

/**
 * Sanitises administrator-authored template HTML.
 *
 * ## Why this exists at all
 *
 * Template bodies are the one place in this application where a *person* supplies
 * markup that is later rendered to customers. Everything else that shows a name or a
 * number goes through `{{ }}` and is escaped. A template has to be able to say
 * `<strong>` and `<a href>`, so it cannot be escaped — which means the markup has to be
 * filtered instead.
 *
 * ## Allowlist, not blocklist
 *
 * A blocklist of "bad" tags is a losing game: `<scr<script>ipt>`, `<img onerror>`,
 * `<svg onload>`, `<iframe srcdoc>` and a decade of variants all exist. This names the
 * tags and attributes that are *permitted* and removes everything else, so an unknown
 * vector is dropped by default rather than being recognised and then forgotten.
 *
 * ## Deliberately conservative for email
 *
 * Email clients support a narrow subset of HTML anyway, and every tag beyond that
 * subset is either ignored or mangles the message. So the list is close to what
 * actually renders: structure, text emphasis, links, images, and the table attributes
 * email layout needs.
 *
 * ## What it does not do
 *
 * It does not sanitise *URLs* — that is `safeUrl()`'s job, applied where a URL is
 * accepted (CTA buttons), because a `javascript:` href is a valid-looking attribute
 * value and has to be judged on its scheme rather than stripped by name.
 */
class HtmlSanitizer
{
    /** Tags that survive sanitisation, with the attributes each may carry. */
    private const ALLOWED = [
        'a' => ['href', 'title', 'style', 'target', 'rel'],
        'b' => ['style'],
        'blockquote' => ['style'],
        'br' => [],
        'code' => ['style'],
        'div' => ['style', 'class', 'align'],
        'em' => ['style'],
        'h1' => ['style', 'align'],
        'h2' => ['style', 'align'],
        'h3' => ['style', 'align'],
        'h4' => ['style', 'align'],
        'hr' => ['style'],
        'i' => ['style'],
        'img' => ['src', 'alt', 'width', 'height', 'style', 'title'],
        'li' => ['style'],
        'ol' => ['style'],
        'p' => ['style', 'align'],
        'small' => ['style'],
        'span' => ['style'],
        'strong' => ['style'],
        'table' => ['style', 'width', 'cellpadding', 'cellspacing', 'border', 'align', 'role'],
        'tbody' => ['style'],
        'td' => ['style', 'width', 'align', 'valign', 'colspan', 'rowspan', 'height'],
        'tfoot' => ['style'],
        'th' => ['style', 'width', 'align', 'valign', 'colspan', 'rowspan'],
        'thead' => ['style'],
        'tr' => ['style', 'align', 'valign'],
        'u' => ['style'],
        'ul' => ['style'],
    ];

    /**
     * Tags whose *contents* are removed along with the tag.
     *
     * `script` and `style` are the important ones: unwrapping them would leave their
     * source as visible text, which is worse than removing it.
     */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'svg', 'math'];

    /** URL schemes permitted in `href` and `src`. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function clean(string $html): string
    {
        // Remove content-bearing dangerous tags outright.
        foreach (self::STRIP_WITH_CONTENT as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', '', $html) ?? $html;
            $html = preg_replace('#<' . $tag . '\b[^>]*/?>#is', '', $html) ?? $html;
        }

        // Comments can hide conditional markup that some clients execute.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        return preg_replace_callback(
            '#<\s*(/?)([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^>]*)?)(/?)\s*>#s',
            function ($m) {
                $closing = $m[1] === '/';
                $tag = strtolower($m[2]);
                $attributes = $m[3] ?? '';
                $selfClosing = ($m[4] ?? '') === '/';

                if (! isset(self::ALLOWED[$tag])) {
                    // Not allowed: drop the tag, keep its text. Unwrapping is right
                    // for a tag like `<font>`; the content-bearing ones are gone
                    // already.
                    return '';
                }

                if ($closing) {
                    return '</' . $tag . '>';
                }

                $kept = self::filterAttributes($attributes, self::ALLOWED[$tag]);

                return '<' . $tag . $kept . ($selfClosing ? ' /' : '') . '>';
            },
            $html
        ) ?? $html;
    }

    /**
     * Keep only permitted attributes whose values are safe.
     *
     * Event handlers are excluded by the allowlist rather than by matching `on*`,
     * which is the belt-and-braces version of the same rule.
     */
    private static function filterAttributes(string $attributes, array $allowed): string
    {
        preg_match_all(
            '#([a-zA-Z][a-zA-Z0-9:_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#',
            $attributes,
            $matches,
            PREG_SET_ORDER
        );

        $out = '';

        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            $value = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : ($match[4] ?? ''));

            if (! in_array($name, $allowed, true)) {
                continue;
            }

            if ($name === 'href' || $name === 'src') {
                $value = self::safeUrl($value);

                if ($value === null) {
                    continue;
                }
            }

            $out .= ' ' . $name . '="' . e($value, false) . '"';
        }

        return $out;
    }

    /**
     * A URL that is safe to place in a link or an image, or null.
     *
     * Rejects anything without an allowed scheme — which is what stops
     * `javascript:alert(1)` and `data:text/html;base64,…`, the two that matter. A
     * protocol-relative `//host` is resolved to https rather than dropped, because
     * that is a legitimate way to write a link.
     */
    public static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        // Control characters are used to smuggle `java\tscript:` past a naive check.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        // A relative path is same-origin by definition.
        if (str_starts_with($url, '/')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, self::SAFE_SCHEMES, true)) {
            return null;
        }

        return $url;
    }

    /**
     * A plain-text fallback derived from HTML, for a template that has none.
     *
     * Not a rendering — email clients do their own conversion and do it worse when
     * given markup. Producing a readable approximation means the plain-text part of a
     * multipart message is something a human wrote the HTML for, rather than an empty
     * alternative part that scores badly with spam filters.
     */
    public static function toText(string $html): string
    {
        $text = preg_replace('#<(br|/p|/div|/h[1-4]|/tr|/li)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}

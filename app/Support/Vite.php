<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Vite asset resolution for Laravel 8.
 *
 * Why this exists
 * ---------------
 * The `@vite()` Blade directive is provided by Laravel itself, starting in
 * 9.19. This project runs Laravel 8.83, where the directive does not exist and
 * no installed package registers it — so `@vite([...])` was emitted into the
 * rendered HTML verbatim. The browser received a literal "@vite(...)" text
 * node, no stylesheet and no JavaScript: every page rendered unstyled.
 *
 * Tailwind 4's Vite plugin requires a modern Vite, which in turn needs the
 * Laravel 9+ plugin, so the alternative — downgrading the frontend toolchain —
 * would mean giving up the design system. Resolving the tags here instead keeps
 * the build pipeline modern and works on any Laravel version.
 *
 * Behaviour
 * ---------
 *   dev server running  ->  <script> tags against the Vite dev server (HMR)
 *   otherwise           ->  <link>/<script> against public/build, from the
 *                           manifest, so production is a static file read
 */
class Vite
{
    /** Seconds to wait when checking whether the dev server is alive. */
    private const PROBE_TIMEOUT = 1;

    /**
     * Render the tags for the given entry points.
     *
     * Accepts an array (`@vite(['a','b'])`), a single string
     * (`@vite('a')`), or a comma-separated list (`@vite('a','b')`), because
     * which of these Blade hands back depends on how the directive was called.
     *
     * @param  array<int, string>|string  $entries
     */
    public static function tags($entries): string
    {
        $entries = self::normalise($entries);

        $devServer = self::devServerUrl();

        return $devServer
            ? self::devTags($entries, $devServer)
            : self::buildTags($entries);
    }

    /**
     * The Vite dev-server URL, or null when we should use the build.
     *
     * `public/hot` is written by `vite` while it runs, but it is not removed if
     * the process is killed — a stale file makes every page point at a server
     * that is no longer listening, which fails silently in the browser (the
     * stylesheet is injected by JavaScript, so there is no 404 to notice). We
     * therefore confirm the server actually answers before trusting the file.
     */
    public static function devServerUrl(): ?string
    {
        $hotFile = public_path('hot');

        if (! File::exists($hotFile)) {
            return null;
        }

        $url = trim((string) File::get($hotFile));

        if ($url === '') {
            return null;
        }

        // The hot file may name the loopback address in a form the browser
        // cannot reach (`[::1]` when only IPv4 is available, or vice versa).
        foreach (self::loopbackVariants($url) as $candidate) {
            if (self::isReachable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Rewrite a loopback dev-server URL to the form the browser will expect.
     *
     * @return array<int, string>
     */
    private static function loopbackVariants(string $url): array
    {
        $candidates = [$url];

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return $candidates;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        foreach (['127.0.0.1', 'localhost', '[::1]'] as $host) {
            $candidates[] = $scheme . '://' . $host . $port;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Cheap liveness probe. One second is enough for a loopback socket.
     */
    private static function isReachable(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return false;
        }

        $host = trim((string) $parts['host'], '[]');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));

        $socket = @fsockopen($host, $port, $errno, $errstr, self::PROBE_TIMEOUT);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Development tags: the client (for HMR) plus each entry as a module.
     *
     * @param  array<int, string>  $entries
     */
    private static function devTags(array $entries, string $devServer): string
    {
        $tags = [
            '<script type="module" crossorigin src="' . e($devServer . '/@vite/client') . '"></script>',
        ];

        foreach ($entries as $entry) {
            $tags[] = '<script type="module" crossorigin src="' . e($devServer . '/' . ltrim($entry, '/')) . '"></script>';
        }

        return implode("\n    ", $tags);
    }

    /**
     * Production tags, read from the build manifest.
     *
     * Handles both an entry whose own output is CSS, and a JS entry that pulls
     * in CSS sidecars.
     *
     * @param  array<int, string>  $entries
     */
    private static function buildTags(array $entries): string
    {
        $manifest = self::manifest();
        $tags = [];
        $seen = [];

        foreach ($entries as $entry) {
            if (! isset($manifest[$entry])) {
                // A missing entry means the build is stale, not that the page
                // should render unstyled. Fail loudly so it is obvious.
                throw new RuntimeException(
                    "Vite entry [{$entry}] is not in the build manifest. Run `npm run build`."
                );
            }

            $chunk = $manifest[$entry];

            // Emit the stylesheets a JS entry imports, before the script
            // itself, so there is no flash of unstyled content.
            foreach ($chunk['css'] ?? [] as $css) {
                self::addStylesheet($tags, $seen, $css);
            }

            $file = (string) $chunk['file'];

            // An entry that *is* a stylesheet must be a <link>. Emitting it as
            // a module script loads the CSS as JavaScript and the page renders
            // completely unstyled — which is exactly the failure this class was
            // written to fix, so it is guarded here explicitly.
            if (self::isStylesheet($file)) {
                self::addStylesheet($tags, $seen, $file);

                continue;
            }

            $tags[] = '<script type="module" crossorigin src="' . e(self::assetUrl($file)) . '"></script>';
        }

        return implode("\n    ", $tags);
    }

    /**
     * @param  array<int, string>  $tags
     * @param  array<string, bool>  $seen
     */
    private static function addStylesheet(array &$tags, array &$seen, string $file): void
    {
        $href = self::assetUrl($file);

        if (isset($seen[$href])) {
            return;
        }

        $seen[$href] = true;
        $tags[] = '<link rel="stylesheet" href="' . e($href) . '">';
    }

    private static function isStylesheet(string $file): bool
    {
        $path = parse_url($file, PHP_URL_PATH) ?: $file;

        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function manifest(): array
    {
        $path = public_path('build/manifest.json');

        if (! File::exists($path)) {
            throw new RuntimeException(
                'Vite build manifest not found at public/build/manifest.json. Run `npm run build`.'
            );
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('public/build/manifest.json is not valid JSON.');
        }

        return $decoded;
    }

    private static function assetUrl(string $file): string
    {
        return asset('build/' . ltrim($file, '/'));
    }

    /**
     * Flatten any accepted argument shape into a list of entry paths.
     *
     * @param  array<int, string>|string  $entries
     * @return array<int, string>
     */
    private static function normalise($entries): array
    {
        $flat = [];

        $walk = function ($value) use (&$walk, &$flat) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }

                return;
            }

            if (! is_string($value)) {
                return;
            }

            // A comma-separated list arrives as one string when the directive
            // was called as @vite('a', 'b') without an array.
            foreach (explode(',', $value) as $part) {
                $part = trim($part, " \t\n\r}
\x0B\"'");

                if ($part !== '') {
                    $flat[] = $part;
                }
            }
        };

        $walk($entries);

        return array_values(array_unique($flat));
    }
}
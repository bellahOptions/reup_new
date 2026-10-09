{{--
    Appearance: resolved theme, the boot script that applies it, and the
    `theme-color` the browser paints its own chrome with.

    Included from partials/head.blade.php rather than from each layout, so a new
    layout cannot forget it. Every layout that renders a <head> already includes
    that partial.

    ---------------------------------------------------------------------------
    Why the theme is resolved server-side *and* again in the browser
    ---------------------------------------------------------------------------
    The server knows what the account asked for. It does not know whether
    "system" means light or dark, because that depends on the device the request
    came from and `prefers-color-scheme` is not sent with the request.

    So the server renders the answer it can stand behind, and the inline script
    below corrects it for `system` before anything is painted. A visitor with no
    stored preference gets `system`, which the script resolves locally — which is
    what makes "auto-select by device preference" work at all for a signed-out
    visitor, with no request-cost and no flash.

    ---------------------------------------------------------------------------
    Why this is a script in <head> and not a class on <body>
    ---------------------------------------------------------------------------
    Put it on <body> and the browser has already painted a white page before the
    attribute exists. That flash is the single most visible way dark mode gets
    described as "broken", so the attribute is set on <html>, before the
    stylesheet is even parsed.

    The bootstrap is inline and synchronous on purpose. A deferred module would
    run after the first paint, and a separate file would be a second round trip
    after the CSS — both reintroduce the flash this exists to prevent.
--}}
@php
    /*
     * Supplied by the `layouts.* / errors.* / admin.*` view composer in
     * ViewServiceProvider. Guarded so the partial still renders if it is ever
     * included from a view outside those patterns (a Blade string in a test, for
     * instance), which is also what keeps this file's behaviour obvious on its
     * own rather than dependent on a provider three directories away.
     */
    $themeState = $themeState ?? \App\Support\ThemeState::forCurrentRequest();

    $themeFallback = \App\Support\Theme::fallbackMode();

    $lightColor = $themeState->lightColor();
    $darkColor = $themeState->darkColor();
@endphp

{{-- Publish the choice to the browser. `data-requested-theme` is what the user
     asked for; `data-theme` is what is actually rendered, which starts as the
     server's best answer and is corrected below when the answer is "system".
     Both are already on <html> — written by the layout from $themeState — so the
     script below reads them immediately. This partial only adds the browser-chrome
     metadata and the bootstrap. --}}
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="{{ $lightColor }}" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="{{ $darkColor }}" media="(prefers-color-scheme: dark)">

<script>
    /*
     * Resolve the theme before first paint.
     *
     * Kept deliberately tiny, dependency-free and tolerant of failure: if it
     * throws, the attributes the server already rendered stay put and the page
     * still renders correctly, just without device auto-detection.
     */
    (function () {
        var root = document.documentElement;
        var STORAGE_KEY = 'reup.theme';

        // A signed-out visitor has no account to store a choice on, so the
        // switch keeps it on the device — and therefore it has to be read back
        // here, before the paint, or their choice would be forgotten on every
        // navigation. A signed-in page ignores this entirely: the account is
        // authoritative, and letting a stale device value win is exactly the bug
        // that makes a saved preference look like it did not save.
        if (root.getAttribute('data-theme-persist') === 'device') {
            try {
                var stored = window.localStorage.getItem(STORAGE_KEY);

                if (stored === 'light' || stored === 'dark' || stored === 'system') {
                    root.setAttribute('data-requested-theme', stored);
                }
            } catch (e) {
                /* Private mode or blocked storage: fall back to the server's answer. */
            }
        }

        var requested = root.getAttribute('data-requested-theme') || 'system';

        function devicePrefersDark() {
            try {
                return window.matchMedia('(prefers-color-scheme: dark)').matches;
            } catch (e) {
                return false;
            }
        }

        function resolve() {
            if (requested === 'light' || requested === 'dark') {
                return requested;
            }

            /*
             * `system`: ask the device. `prefers-color-scheme` is the only
             * authority on the answer — the server never saw it, because the
             * browser does not send it with the request.
             */
            return devicePrefersDark() ? 'dark' : @json($themeFallback);
        }

        function apply(resolved) {
            root.setAttribute('data-theme', resolved);
            // Drives the native form controls, scrollbars and the canvas the
            // browser paints behind the page.
            root.style.colorScheme = resolved;

            // Keep the address-bar colour in step. Both metas are media-scoped
            // so a no-JavaScript visitor already gets the right one; this only
            // matters when an explicit choice overrides the device.
            var metas = document.querySelectorAll('meta[name="theme-color"]');
            for (var i = 0; i < metas.length; i++) {
                metas[i].setAttribute(
                    'content',
                    resolved === 'dark' ? @json($darkColor) : @json($lightColor)
                );
                metas[i].removeAttribute('media');
            }

            // The Alpine switch mirrors this rather than owning it, so the icons
            // follow every path into a theme change — including the operating
            // system flipping underneath an open page.
            window.dispatchEvent(new CustomEvent('reup:theme-changed', {
                detail: { requested: requested, resolved: resolved }
            }));
        }

        apply(resolve());

        /*
         * Follow the device while the page is open, but only while the user is
         * actually following it — a visitor who chose "Dark" on a light laptop
         * must not have the page flip under them at sunset.
         */
        try {
            var query = window.matchMedia('(prefers-color-scheme: dark)');
            var onChange = function () {
                if (requested === 'system') {
                    apply(resolve());
                }
            };

            if (query.addEventListener) {
                query.addEventListener('change', onChange);
            } else if (query.addListener) {
                query.addListener(onChange); // Safari < 14
            }
        } catch (e) {
            /* Auto-detection is a convenience; failing to subscribe is fine. */
        }

        /*
         * Exposed so the switch in the navbar can re-resolve immediately rather
         * than waiting for a page load, and so the resolved value is readable
         * (and testable) from the console.
         */
        window.ReUpTheme = {
            requested: function () {
                return requested;
            },
            resolved: function () {
                return root.getAttribute('data-theme');
            },
            resolve: resolve,
            set: function (mode) {
                requested = mode;
                root.setAttribute('data-requested-theme', mode);
                apply(resolve());
            }
        };
    })();
</script>

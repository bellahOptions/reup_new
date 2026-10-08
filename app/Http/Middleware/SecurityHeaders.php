<?php

namespace App\Http\Middleware;

use App\Support\Vite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser-hardening response headers.
 *
 * Registered in the global stack (see App\Http\Kernel::$middleware) so every
 * response carries them, including error pages, JSON and streamed downloads.
 * A header already set by the controller is never overwritten: the proof
 * download in Admin\BankTransferController sets its own Content-Type and
 * Content-Disposition, and the payment-proof stream relies on the `nosniff`
 * set here but must keep its own disposition.
 *
 * ## Why this Content-Security-Policy shape
 *
 * The views are not CSP-clean and cannot cheaply be made so:
 *
 *   * Alpine.js evaluates every `x-data`/`x-on` expression with `new Function`
 *     at runtime, which needs `script-src 'unsafe-eval'`. Without it the whole
 *     UI stops responding.
 *   * Layouts bootstrap state with inline `<script>` blocks and `@js(...)`
 *     payloads, and Tailwind/Paystack flows rely on `style="..."` attributes —
 *     `'unsafe-inline'` is required in both `script-src` and `style-src`.
 *
 * A nonce/hash policy is therefore not achievable without rewriting the
 * front-end, which this task explicitly forbids. What is left is still worth
 * having, and is what the policy below buys:
 *
 *   * injected scripts can only load from the enumerated, first-party origins
 *     (a compromised provider hostname or an attacker's CDN is refused);
 *   * `<base href>` cannot be hijacked (`base-uri 'self'`), so a relative
 *     script/asset URL cannot be redirected off-origin;
 *   * `object-src 'none'` kills the legacy plugin vector (including `<embed>`
 *     of a `.php`/`.swf`), which matters here because the app accepts uploads;
 *   * `form-action 'self'` stops an injected form from posting the transaction
 *     PIN off-site;
 *   * `frame-ancestors 'self'` is the modern half of the SAMEORIGIN clickjack
 *     defence.
 *
 * `form-action 'self'` has a consequence that is easy to trip over: the
 * directive is measured against the *page* origin, and a browser counts the
 * port as part of that origin. Every `<form action>` in resources/views must
 * therefore be origin-relative — `route($name, $parameters, false)` — because
 * Laravel 8 renders `route()` as an absolute URL built from APP_URL. A form
 * whose action names a different host or port than the address bar is refused
 * outright ("Sending form data to 'http://127.0.0.1:8000/wallet/fund'
 * violates ... form-action 'self'"), which is exactly what happened on the
 * fund-wallet page while APP_URL was `http://localhost` and the page was
 * being browsed at `127.0.0.1:8000`. Redirects and the Paystack
 * `callback_url` still need absolute URLs, so this applies to form actions
 * only; SecurityHardeningTest asserts the invariant across every template.
 *
 * The enumerated origins are what the views actually reference — Google Fonts
 * (partials/head), Google Tag Manager + Analytics (same file), Chart.js on
 * jsdelivr (admin dashboard) and Quill on cdn.quilljs.com (admin terms editor)
 * — plus one origin nothing in this repository references at all:
 * static.cloudflareinsights.com, the Cloudflare Web Analytics beacon that the
 * edge injects into HTML responses when the domain is proxied through
 * Cloudflare. Grepping the codebase for it finds nothing, and `curl` without
 * `Accept: text/html` does not reproduce the injection either, so it is easy
 * to leave out and then discover only in production, where it blocks and logs
 * on every page. The Vite dev server origin is added automatically while
 * `npm run dev` is running so the HMR client is not blocked in development.
 *
 * ## HSTS
 *
 * Sent only when the request already arrived over HTTPS *and* the app is in
 * production. Adding `Strict-Transport-Security` on a plain-HTTP local install
 * would pin the developer's browser to a scheme the install does not serve.
 * Note this is driven by `$request->isSecure()`, which behind a TLS-terminating
 * proxy depends on App\Http\Middleware\TrustProxies — see AUDIT/report.
 */
class SecurityHeaders
{
    /**
     * Headers applied to every response unless already present.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        // Never let a browser re-interpret a declared type. This is the header
        // that stops an uploaded file whose extension says .jpg from being run
        // as HTML.
        'X-Content-Type-Options' => 'nosniff',

        // Clickjacking. Nothing in resources/views uses <iframe>, so SAMEORIGIN
        // costs nothing; admin and customer pages are both protected.
        'X-Frame-Options' => 'SAMEORIGIN',

        // Full URLs (which here carry transaction references) stay on this
        // origin; cross-origin destinations get the bare origin.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',

        // None of these are used anywhere in views or bundles — confirmed by
        // grepping for getUserMedia/mediaDevices/geolocation/capture=. The
        // Payment Request API is unused too; the card flow is a hosted
        // redirect to Paystack.
        'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',

        // Legacy Adobe/Flash cross-domain policy vector: refuses any
        // `crossdomain.xml` / `clientaccesspolicy.xml` from authorising a
        // third-party reader to make credentialed cross-origin requests. Cheap
        // to send and nothing in the app depends on those files.
        'X-Permitted-Cross-Domain-Policies' => 'none',
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            return $response;
        }

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        // Only ever sent over an already-secure connection in production: HSTS
        // on a plain-HTTP install would break the install.
        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }

    /**
     * Build the policy, allowing the Vite dev server when it is running.
     */
    private function contentSecurityPolicy(): string
    {
        $script = [
            "'self'",
            "'unsafe-inline'",   // inline bootstraps + Alpine expression attributes
            "'unsafe-eval'",     // Alpine evaluates x-data/x-on with new Function
            'https://www.googletagmanager.com',
            'https://cdn.jsdelivr.net',
            'https://cdn.quilljs.com',
            // Cloudflare Web Analytics. Nothing in this repository references
            // it: when the domain is proxied through Cloudflare with Web
            // Analytics (or the RUM beacon) enabled, the edge *injects*
            //   <script type="module" src="https://static.cloudflareinsights.com/beacon.min.js/v...">
            // into the HTML on the way out. Grepping the codebase therefore
            // finds nothing, and the tag only exists in production — which is
            // exactly why it was missing here and every production page logged
            //   Loading the script 'https://static.cloudflareinsights.com/beacon.min.js/...'
            //   violates ... "script-src ...". The action has been blocked.
            // It is deliberately NOT added to connect-src: a proxied domain
            // receives the beacon's data at its own /cdn-cgi/rum endpoint, so
            // `connect-src 'self'` already covers it. See docs/RUNNING.md.
            // If Web Analytics is ever switched off in the Cloudflare
            // dashboard, delete this line — it stops being needed.
            'https://static.cloudflareinsights.com',
        ];

        $style = [
            "'self'",
            "'unsafe-inline'",   // Tailwind utilities applied as style="..." attributes
            'https://fonts.googleapis.com',
            'https://cdn.quilljs.com',
        ];

        $font = [
            "'self'",
            'data:',
            'https://fonts.gstatic.com',
        ];

        $connect = [
            "'self'",
            'https://www.google-analytics.com',
            'https://*.google-analytics.com',
            'https://www.googletagmanager.com',
        ];

        $dev = $this->devServerOrigins();

        if ($dev !== []) {
            $script = array_merge($script, $dev['http']);
            $style = array_merge($style, $dev['http']);
            $connect = array_merge($connect, $dev['http'], $dev['ws']);
            $font = array_merge($font, $dev['http']);
        }

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "frame-src 'self'",
            "form-action 'self'",
            "manifest-src 'self'",
            "script-src " . implode(' ', $script),
            "style-src " . implode(' ', $style),
            "font-src " . implode(' ', $font),
            // Images are a lower-value injection vector and legitimately come
            // from anywhere an administrator pastes a logo URL into settings.
            "img-src 'self' data: blob: https:",
            "connect-src " . implode(' ', $connect),
        ];

        return implode('; ', $directives) . ';';
    }

    /**
     * The Vite dev server's http and websocket origins, when one is running.
     *
     * `Vite::devServerUrl()` short-circuits on the absence of `public/hot`, so
     * a production request pays one `file_exists` and nothing more.
     *
     * @return array{http: array<int,string>, ws: array<int,string>}
     */
    private function devServerOrigins(): array
    {
        $empty = ['http' => [], 'ws' => []];

        try {
            $url = Vite::devServerUrl();
        } catch (\Throwable $e) {
            return $empty;
        }

        if (! $url) {
            return $empty;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'http';
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! $host) {
            return $empty;
        }

        $authority = $host . ($port ? ':' . $port : '');

        return [
            'http' => [$scheme . '://' . $authority],
            'ws' => ['ws://' . $authority, 'wss://' . $authority],
        ];
    }
}

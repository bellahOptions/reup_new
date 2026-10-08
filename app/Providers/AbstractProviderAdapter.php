<?php

namespace App\Providers;

use App\Models\Provider;
use App\Providers\Support\PayloadRedactor;
use App\Providers\Support\ProviderCredentials;
use App\Providers\Support\ProviderNotConfiguredException;
use App\Providers\Support\ProviderResult;
use App\Providers\Support\ProviderStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared transport behaviour for every Wave 1 adapter.
 *
 * ## What it centralises, and why each part matters
 *
 *   1. **Timeout handling that produces UNKNOWN, not FAILED.** A connection that
 *      times out may have delivered the request. `send()` catches the transport
 *      exception and returns UNKNOWN, so no caller can accidentally treat a
 *      timeout as a rejection and refund an order the customer received.
 *
 *   2. **Exponential backoff on rate limits only.** A 429 is the one response
 *      that unambiguously means "I did not process this", so it is the one
 *      response that is safe to repeat. Retrying a timeout or a 5xx would risk
 *      duplicating an operation that already happened.
 *
 *   3. **Redaction on every path.** Request bodies, response bodies, error
 *      messages and stack traces are all passed through `PayloadRedactor` before
 *      they reach a log. A credential cannot be logged by an adapter that
 *      forgets to be careful, because the adapter never logs a raw value.
 *
 *   4. **A single choke point for outbound calls.** `send()` is the only method
 *      that performs HTTP, so the set of requests an adapter can make is exactly
 *      the set of endpoints in `config/providers.php`. "No arbitrary provider API
 *      calls" is therefore checkable by reading one method rather than auditing
 *      every adapter.
 */
abstract class AbstractProviderAdapter
{
    public function __construct(
        protected readonly Provider $provider,
    ) {
    }

    /**
     * The configuration row this adapter was built from.
     *
     * Exposed read-only because the registry's ordering and the admin console
     * both need to identify which provider an adapter represents, and a
     * protected property offers no way to do that.
     */
    public function provider(): Provider
    {
        return $this->provider;
    }

    public function providerId(): ?int
    {
        return $this->provider->getKey();
    }

    /* =====================================================================
     | Identity and configuration
     |=================================================================== */

    abstract public function slug(): string;

    abstract public function label(): string;

    /** The provider's capability tokens, from config. @return array<int, string> */
    abstract public function capabilities(): array;

    /**
     * The provider's wallet balance with us.
     *
     * Declared abstract so that every adapter must answer it. Provider balance
     * monitoring is the control that stops the platform charging a customer for a
     * vend the upstream cannot fund, and a monitoring job that silently skips an
     * adapter is worse than no monitoring — it reports health it never checked.
     *
     * Implementations must return exactly this shape, because the monitor and the
     * admin dashboard read it uniformly:
     *
     * @return array{success:bool,balance_minor:?int,currency:?string,message:?string,sandbox:bool}
     */
    abstract public function balance(): array;

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function isConfigured(): bool
    {
        return ProviderCredentials::isConfigured($this->provider);
    }

    /**
     * Whether this adapter may be routed customer orders to at all.
     *
     * Configured is not the same as usable. An adapter can hold valid credentials
     * and still be unable to serve a request safely — VTUGate's international field
     * names are not published, so an order dispatched to it would be addressed with
     * guessed field names and could deliver to the wrong recipient. Such an adapter
     * reports itself non-operational, and the registry skips it and falls through to
     * the next candidate instead of dispatching.
     *
     * The default is true: an adapter is presumed usable, and the ones that are not
     * have to say so explicitly and give a reason.
     */
    public function isOperational(): bool
    {
        return true;
    }

    /**
     * Why this adapter is not operational, for the admin console.
     *
     * An operator seeing "VTUGate: unavailable" needs to know whether that is a
     * missing key, a suspended account or an unfinished integration. Returning the
     * reason here means the answer is the same in the UI as in the code.
     */
    public function operationalReason(): ?string
    {
        return null;
    }

    /* =====================================================================
     | Configuration helpers
     |=================================================================== */

    /** The provider's config block. */
    protected function config(string $key = null, $default = null)
    {
        $block = config('providers.' . $this->configKey());

        if ($key === null) {
            return $block;
        }

        return data_get($block, $key, $default);
    }

    abstract protected function configKey(): string;

    /** The base URL for the current mode. */
    protected function baseUrl(): string
    {
        return (string) $this->config('base_url');
    }

    /**
     * The base URL for a named product group, where one provider exposes more
     * than one API root.
     *
     * The default is the provider's single base URL, so an adapter for a
     * single-host provider needs no override. It exists because a provider that
     * later splits its surface across hosts should not require every adapter to
     * change.
     */
    protected function productBaseUrl(string $product): string
    {
        $specific = $this->config("products.{$product}.base_url");

        return is_string($specific) && $specific !== '' ? $specific : $this->baseUrl();
    }

    protected function isSandbox(): bool
    {
        return (bool) config('providers.sandbox', false);
    }

    /**
     * Resolve a named endpoint path.
     *
     * Adapters call endpoints by name, never by literal, so a URL change is a
     * config edit and this method can refuse a name that is not declared — which
     * is what stops a typo turning into a request to an unintended path.
     *
     * @param  array<string, string|int>  $params
     */
    protected function url(string $endpoint, array $params = [], ?string $product = null): string
    {
        $path = $product === null
            ? $this->config('endpoints.' . $endpoint)
            : $this->config("products.{$product}.endpoints.{$endpoint}");

        if (! is_string($path) || $path === '') {
            $qualified = $product === null ? $endpoint : "{$product}.{$endpoint}";

            throw new \RuntimeException(
                "Provider [{$this->slug()}] has no endpoint named [{$qualified}]. "
                . 'Add it to config/providers.php before use.'
            );
        }

        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
        }

        $base = $product === null ? $this->baseUrl() : $this->productBaseUrl($product);

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /* =====================================================================
     | Status mapping
     |=================================================================== */

    /**
     * Map a provider status string to a canonical status.
     *
     * Unmapped strings become UNKNOWN. This is the single most important line in
     * the adapter layer: a status a future provider version introduces must not
     * be read as a failure, because a failure triggers a refund and a refund on a
     * delivered order gives the goods away.
     */
    public function mapStatus(?string $providerStatus): string
    {
        if ($providerStatus === null || $providerStatus === '') {
            return ProviderStatus::UNKNOWN;
        }

        $map = (array) $this->config('status_map', []);
        $normalised = ProviderStatus::normalise($providerStatus);

        foreach ($map as $providerValue => $canonical) {
            if (ProviderStatus::normalise((string) $providerValue) === $normalised) {
                return $canonical;
            }
        }

        return ProviderStatus::UNKNOWN;
    }

    /* =====================================================================
     | Transport
     |=================================================================== */

    /**
     * Perform one HTTP request with rate-limit backoff.
     *
     * Returns a `ProviderResult` for every outcome — including transport
     * failures — so an adapter never has to handle an exception and can never
     * accidentally convert one into FAILED.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function send(
        string $method,
        string $url,
        array $payload = [],
        array $headers = [],
        ?string $scope = null,
        ?int $timeout = null,
        bool $keepRaw = false
    ): ProviderResult {
        $maxAttempts = max(1, (int) config('providers.http.rate_limit.max_attempts', 4));
        $attempt = 0;
        $lastResult = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $result = $this->attempt($method, $url, $payload, $headers, $scope, $timeout, $keepRaw);
            } catch (ProviderNotConfiguredException $e) {
                /*
                 * Thrown while building the request, so nothing was sent and
                 * nothing can have been charged. RETRYABLE, not UNKNOWN: failing
                 * over to a configured provider is provably safe here, whereas
                 * UNKNOWN would hold the customer's money against a request that
                 * was never made. `attempt()` deliberately builds the request
                 * outside its own try/catch so this distinction survives.
                 */
                Log::error('Provider is not configured', [
                    'provider' => $this->slug(),
                    'error' => PayloadRedactor::redactString($e->getMessage()),
                ]);

                return new ProviderResult(
                    status: ProviderStatus::RETRYABLE,
                    message: 'This service provider is not available right now.',
                    errorCode: 'not_configured',
                );
            }

            if (! $result->rateLimited) {
                return $result;
            }

            $lastResult = $result;

            if ($attempt >= $maxAttempts) {
                break;
            }

            $delayMs = $this->backoffDelayMs($attempt);

            Log::warning('Provider rate limited; backing off', [
                'provider' => $this->slug(),
                'attempt' => $attempt,
                'delay_ms' => $delayMs,
            ]);

            usleep($delayMs * 1000);
        }

        return $lastResult ?? ProviderResult::rateLimited();
    }

    /**
     * A read-only call whose response body a caller must parse for identifiers.
     *
     * Used by catalogue reads. The unredacted body is attached to the result as
     * `raw`, which exists because the redactor is path-blind: `code` is a gift card
     * number to Sogo and a country code to VTpass, and a redactor that protects the
     * first destroys the second. See `ProviderResult::$raw` for the rule about
     * handling it — briefly: read identifiers from it, never persist or log it.
     *
     * Kept as a separate method rather than a boolean at every call site so that
     * "this response carries an unredacted body" is visible in the adapter code that
     * makes the call, and so a purchase can never accidentally be one of them.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function sendRead(
        string $method,
        string $url,
        array $payload = [],
        array $headers = [],
        ?string $scope = null,
        ?int $timeout = null
    ): ProviderResult {
        return $this->send($method, $url, $payload, $headers, $scope, $timeout, true);
    }

    /**
     * Exponential backoff with jitter.
     *
     * Jitter matters: several workers rate-limited by the same response would
     * otherwise retry in lockstep and be rate-limited together again.
     */
    protected function backoffDelayMs(int $attempt): int
    {
        $base = (int) config('providers.http.rate_limit.base_delay_ms', 500);
        $cap = (int) config('providers.http.rate_limit.max_delay_ms', 8000);
        $jitterPercent = (int) config('providers.http.rate_limit.jitter_percent', 20);

        $delay = min($cap, $base * (2 ** ($attempt - 1)));

        if ($jitterPercent > 0) {
            $jitter = (int) ($delay * $jitterPercent / 100);
            $delay += random_int(-$jitter, $jitter);
        }

        return max(1, $delay);
    }

    /**
     * One attempt. Never throws for a transport failure.
     *
     * The request builder is invoked **outside** the try/catch on purpose. Building
     * a request can fail for exactly one reason that matters — no credential is
     * configured — and that failure happens before anything is sent. Letting it be
     * caught here would classify it as a transport failure, which is UNKNOWN, and
     * UNKNOWN on a request that was never made holds a customer's money for no
     * reason. `send()` catches the type and resolves it to RETRYABLE.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function attempt(
        string $method,
        string $url,
        array $payload,
        array $headers,
        ?string $scope,
        ?int $timeout,
        bool $keepRaw = false
    ): ProviderResult {
        $started = microtime(true);

        // Every payload is redacted before it is logged, not after. A redactor
        // applied at the log call site is one a future edit can forget.
        $safeRequest = $this->redactPayload($payload);

        // May throw ProviderNotConfiguredException; see the docblock.
        $request = $this->requestFor($scope, $timeout, $method);

        try {
            foreach ($headers as $name => $value) {
                $request = $request->withHeaders([$name => $value]);
            }

            $response = strtoupper($method) === 'GET'
                ? $request->get($url, $payload)
                : $request->post($url, $payload);

            $durationMs = (int) ((microtime(true) - $started) * 1000);

            if ($response->status() === 429) {
                Log::warning('Provider returned 429', [
                    'provider' => $this->slug(),
                    'path' => parse_url($url, PHP_URL_PATH),
                    'duration_ms' => $durationMs,
                ]);

                return ProviderResult::rateLimited();
            }

            Log::debug('Provider call completed', [
                'provider' => $this->slug(),
                'endpoint' => parse_url($url, PHP_URL_PATH),
                'http_status' => $response->status(),
                'duration_ms' => $durationMs,
                'request' => $safeRequest,
                'response' => ProviderCredentials::describe($response, 1000),
            ]);

            $interpreted = $this->interpret($response);

            /*
             * The HTTP status is authoritative and must always be carried, even
             * when the body was not the envelope the adapter expected. Sogo's
             * reconciliation depends on it: a 404 is the one answer that proves a
             * transaction was never created and therefore that a retry is safe,
             * and an adapter that lost the status would turn "safe to retry" into
             * "unknown" — or, worse, the reverse.
             */
            if ($interpreted->httpStatus === null) {
                $interpreted = $this->withHttpStatus($interpreted, $response->status());
            }

            /*
             * Attached only when the caller asked for it, which is only ever a
             * read-only catalogue call. A purchase result therefore has no unredacted
             * body at all — the guarantee is structural rather than a convention
             * somebody has to remember.
             */
            if ($keepRaw && is_array($body = $response->json())) {
                $interpreted = $interpreted->withRaw($body);
            }

            return $interpreted;
        } catch (ConnectionException $e) {
            /*
             * The request may have been received and processed before the
             * connection dropped. UNKNOWN, never FAILED — this is the case that
             * causes double vends when it is misclassified.
             */
            Log::warning('Provider connection failed; outcome unknown', [
                'provider' => $this->slug(),
                'endpoint' => parse_url($url, PHP_URL_PATH),
                'error' => PayloadRedactor::redactString($e->getMessage()),
            ]);

            return ProviderResult::fromTransportFailure(
                'The service provider did not respond in time.',
                'timeout',
            );
        } catch (Throwable $e) {
            Log::error('Provider call threw; outcome unknown', [
                'provider' => $this->slug(),
                'endpoint' => parse_url($url, PHP_URL_PATH),
                'error' => PayloadRedactor::redactString($e->getMessage()),
                'exception' => get_class($e),
            ]);

            return ProviderResult::fromTransportFailure(
                'The service provider could not be reached.',
                'connection_error',
            );
        }
    }

    /**
     * A request builder for this provider.
     *
     * ## Where the credential goes
     *
     * Declared, not inferred, by the provider's `auth` config key:
     *
     *   * `bearer` (default) — `Authorization: Bearer {key}`. Sogo and VTUGate.
     *   * `headers` — named API-key headers, supplied by `authHeaders()`.
     *     VTpass uses `api-key` with `public-key` on a GET and `secret-key` on a
     *     POST, which is why the method is passed down.
     *   * `body` — no credential in any header; the key travels as a form field
     *     that the adapter adds. Nitro works this way.
     *   * `none` — a public endpoint.
     *
     * `$scope` selects a scoped credential where the provider issues them (Sogo
     * documents per-scope keys), so a catalogue read can use a read-only key and
     * only a purchase uses the write key.
     *
     * ## Body encoding
     *
     * Most of these providers accept `application/x-www-form-urlencoded` only, and
     * a JSON body against a form endpoint fails as an unhelpful 400 — or, worse, as
     * an empty successful response. The encoding is therefore a property of the
     * provider, declared as `encoding` in its config block, rather than a parameter
     * on every call: every request to a given provider uses one encoding, so a
     * per-call flag would be a fact restated at every call site and eventually
     * restated wrongly at one of them.
     *
     * `asForm()` must be applied *after* the credential headers, which set JSON.
     *
     * `protected` so a provider with unusual authentication overrides this in one
     * place instead of bypassing the single HTTP choke point in `send()`.
     */
    protected function requestFor(?string $scope, ?int $timeout, string $method = 'POST')
    {
        $request = match ($this->authMode()) {
            'headers' => ProviderCredentials::plainRequest()->withHeaders($this->authHeaders($scope, $method)),
            // The key is a form field the adapter adds; nothing to attach here.
            'body', 'none' => ProviderCredentials::plainRequest(),
            default => ProviderCredentials::authorisedRequest($this->provider, $scope),
        };

        $request = $request->timeout($timeout ?? (int) config('providers.http.timeout', 30));

        return $this->encodesBodyAsForm() ? $request->asForm() : $request;
    }

    /** Where this provider's credential belongs. @see requestFor() */
    protected function authMode(): string
    {
        return strtolower((string) $this->config('auth', 'bearer'));
    }

    /**
     * Credential headers for a provider that does not use a bearer token.
     *
     * The values are resolved here, at request time, and never stored — and the
     * returned array is only ever handed to a request builder, so it cannot reach
     * a log through an adapter's own logging.
     *
     * @return array<string, string>
     */
    protected function authHeaders(?string $scope = null, string $method = 'POST'): array
    {
        return [];
    }

    /** Whether this provider's requests carry a form-encoded body. */
    protected function encodesBodyAsForm(): bool
    {
        return strtolower((string) $this->config('encoding', 'json')) === 'form';
    }

    /**
     * Turn an HTTP response into a `ProviderResult`.
     *
     * Subclasses override `normalise()` where the provider's envelope needs
     * decoding; the default handles a plain JSON body with a `status` field.
     */
    protected function interpret(Response $response): ProviderResult
    {
        $body = $response->json();

        if (! is_array($body)) {
            return ProviderResult::fromUnrecognised(
                'non-json',
                null,
                $response->status(),
            );
        }

        return $this->normalise($body, $response);
    }

    /**
     * `config('providers.*')` for a global key.
     *
     * @param  mixed  $default
     * @return mixed
     */
    protected function configCommon(string $key, $default = null)
    {
        return config('providers.' . $key, $default);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function redactPayload(array $payload): array
    {
        $redacted = PayloadRedactor::redact($payload);

        return is_array($redacted) ? $redacted : [];
    }

    /**
     * Normalise a decoded body. Subclasses must implement this; the base
     * implementation is intentionally absent so an adapter cannot ship without
     * one.
     *
     * @param  array<string, mixed>  $body
     */
    abstract protected function normalise(array $body, Response $response): ProviderResult;

    /* =====================================================================
     | Helpers shared by adapters
     |=================================================================== */

    /** A truncated, redacted error message for a customer-facing log line. */
    protected function safeMessage(?string $message, string $fallback): string
    {
        if ($message === null || trim($message) === '') {
            return $fallback;
        }

        return Str::limit(PayloadRedactor::redactString(trim($message)), 240, '…');
    }

    /** Whether a provider error code is one we may safely retry elsewhere. */
    protected function isRetryableError(?string $code): bool
    {
        if ($code === null) {
            return false;
        }

        $normalised = ProviderStatus::normalise($code);

        foreach ((array) $this->config('retryable_errors', []) as $candidate) {
            if (ProviderStatus::normalise((string) $candidate) === $normalised) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy a result with the HTTP status filled in.
     *
     * `ProviderResult` is immutable, so this rebuilds it. Done once, in the one
     * place that knows the status, rather than by making the status mutable and
     * hoping nothing overwrites it.
     */
    private function withHttpStatus(ProviderResult $result, int $httpStatus): ProviderResult
    {
        return new ProviderResult(
            status: $result->status,
            providerStatus: $result->providerStatus,
            providerReference: $result->providerReference,
            providerCostMinor: $result->providerCostMinor,
            providerCurrency: $result->providerCurrency,
            providerAmountMinor: $result->providerAmountMinor,
            payload: $result->payload,
            message: $result->message,
            errorCode: $result->errorCode,
            httpStatus: $httpStatus,
            rateLimited: $result->rateLimited,
            delivery: $result->delivery,
            raw: $result->raw,
        );
    }

    /** Whether a provider error leaves the outcome unknown. */
    protected function isUnknownError(?string $code): bool
    {
        if ($code === null) {
            return false;
        }

        $normalised = ProviderStatus::normalise($code);

        foreach ((array) $this->config('unknown_errors', []) as $candidate) {
            if (ProviderStatus::normalise((string) $candidate) === $normalised) {
                return true;
            }
        }

        return false;
    }
}

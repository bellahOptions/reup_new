<?php

namespace App\Providers\Support;

use App\Models\Provider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one place provider credentials are read.
 *
 * ## Why this is a class and not a config array
 *
 * Three rules have to hold for every provider, and the only way to guarantee
 * them is to make it impossible to obtain a credential any other way:
 *
 *   1. **Credentials never leave the server.** They are read from the
 *      environment at call time, are never stored in a database column, and are
 *      never returned to a caller. `Provider` rows record the *env prefix*, not
 *      the value.
 *   2. **Credentials never reach a log.** Every method here returns a value for
 *      direct use in a request header; nothing here logs, and
 *      `PayloadRedactor` strips credential-shaped keys from anything an adapter
 *      does log.
 *   3. **Scoped where the provider supports it.** Sogo issues separate keys per
 *      scope, so a catalogue read can use a read-only key and only a purchase
 *      uses the write key. `credentialFor($provider, ProviderScope::READ)` is the
 *      seam that makes least-privilege real rather than aspirational.
 *
 * ## Environment variable naming
 *
 * A provider row's `credential_env_prefix` is a prefix such as `SOGO`. The
 * resolver then looks for, in order:
 *
 *   {PREFIX}_{SCOPE}_KEY   e.g. SOGO_BILLS_READ_KEY
 *   {PREFIX}_API_KEY       the general key
 *   {PREFIX}_SECRET_KEY    Sogo's own naming
 *   {PREFIX}_KEY           the shortest accepted form
 *
 * The first one that is set wins, which gives an operator a way to introduce a
 * scoped key without removing the general one.
 */
final class ProviderCredentials
{
    /** Scope tokens, matching the vocabulary providers use. */
    public const SCOPE_READ = 'read';
    public const SCOPE_WRITE = 'write';
    public const SCOPE_VERIFY = 'verify';

    /**
     * Resolve a credential for a provider.
     *
     * @throws ProviderNotConfiguredException when no credential is configured. The
     *         exception is raised *before* any request is built, which is what lets
     *         the transport layer classify it as provably-not-sent (RETRYABLE)
     *         rather than unknown.
     */
    public static function resolve(Provider $provider, ?string $scope = null, ?string $field = null): string
    {
        $prefix = strtoupper((string) $provider->credential_env_prefix);

        if ($prefix === '') {
            throw new ProviderNotConfiguredException(
                "Provider [{$provider->slug}] has no credential environment prefix configured."
            );
        }

        $field = strtoupper($field ?? 'KEY');

        foreach (self::candidateNames($prefix, $scope, $field) as $name) {
            $value = self::env($name);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        throw new ProviderNotConfiguredException(
            "Provider [{$provider->slug}] is not configured: none of ["
            . implode(', ', self::candidateNames($prefix, $scope, $field)) . '] is set.'
        );
    }

    /**
     * Whether any credential is configured for a provider.
     *
     * Used to decide "is this provider usable at all", never to expose a value.
     */
    public static function isConfigured(Provider $provider): bool
    {
        $prefix = strtoupper((string) $provider->credential_env_prefix);

        if ($prefix === '') {
            return false;
        }

        foreach (self::candidateNames($prefix, null, 'KEY') as $name) {
            if (self::env($name) !== null && self::env($name) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Which credential slots are populated, by name only.
     *
     * This is what the admin console displays: "SOGO_BILLS_WRITE_KEY: set",
     * never a value. It is the difference between an operator being able to
     * diagnose a configuration problem and having to guess.
     *
     * @return array<string, bool>
     */
    public static function presenceMap(Provider $provider): array
    {
        $prefix = strtoupper((string) $provider->credential_env_prefix);

        if ($prefix === '') {
            return [];
        }

        $map = [];

        foreach (['KEY', 'SECRET_KEY', 'API_KEY', 'PUBLIC_KEY', 'WEBHOOK_SECRET', 'CLIENT_ID', 'CLIENT_SECRET'] as $field) {
            $name = "{$prefix}_{$field}";
            $map[$name] = self::env($name) !== null && self::env($name) !== '';
        }

        foreach ((array) $provider->credential_scopes as $scope) {
            foreach (['KEY', 'SECRET_KEY'] as $field) {
                $name = $prefix . '_' . strtoupper((string) $scope) . '_' . $field;
                $map[$name] = self::env($name) !== null && self::env($name) !== '';
            }
        }

        return $map;
    }

    /**
     * Read an environment value.
     *
     * `env()` is used rather than `config()` deliberately: a provider credential
     * must not be cached into the config repository where it could be dumped by
     * a `config:cache` artefact or a debug endpoint.
     *
     * @return string|null
     */
    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if ($value === false || $value === null) {
            return null;
        }

        return is_string($value) ? trim($value) : null;
    }

    /**
     * @return array<int, string>
     */
    private static function candidateNames(string $prefix, ?string $scope, string $field): array
    {
        $names = [];

        if ($scope !== null) {
            $names[] = $prefix . '_' . strtoupper($scope) . '_' . $field;
            // Sogo names its scopes with a colon; an operator may translate that
            // to an underscore, a hyphen or nothing at all.
            $names[] = $prefix . '_' . strtoupper(str_replace([':', '-'], '_', $scope)) . '_' . $field;
        }

        $names[] = $prefix . '_API_' . $field;
        $names[] = $prefix . '_SECRET_' . $field;
        $names[] = $prefix . '_' . $field;

        return array_values(array_unique($names));
    }

    /* =====================================================================
     | Authenticated requests
     |==================================================================== */

    /**
     * A request builder with this application's transport policy but no credential.
     *
     * The providers here do not agree on where a credential goes:
     *
     *   * Sogo and VTUGate want `Authorization: Bearer {key}` — `authorisedRequest()`;
     *   * VTpass wants named API-key headers, which the adapter adds because only it
     *     knows the header names;
     *   * Nitro wants the key as a **form field**, so its request carries no
     *     credential in a header at all.
     *
     * All three must still share the timeout, the `Cache-Control` header and —
     * crucially — the absence of `retry()`. This method is that shared part, so an
     * adapter that supplies its own credential is not thereby opting out of the
     * transport policy.
     *
     * There is deliberately no token-exchange path. A provider requiring an OAuth
     * handshake is a provider whose credential expires mid-flight, and an expiring
     * credential in a payment path needs a refresh-and-replay design — which is a
     * different integration, not a parameter.
     */
    public static function plainRequest(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout((int) config('providers.http.timeout', 30))
            /*
             * No `retry()`. Laravel's retry helper turns every non-2xx into an
             * exception once tries > 1, which discards the error body — and the
             * error body is the only thing that distinguishes "float is short"
             * (safe to fail over) from "this reference was already processed"
             * (failing over would charge twice). Backoff is handled explicitly by
             * AbstractProviderAdapter, which understands the distinction.
             */
            ->withHeaders(['Cache-Control' => 'no-cache']);
    }

    /**
     * A request builder authenticated for a provider with a bearer token.
     *
     * `$scope` selects a scoped credential when one is configured, which is what
     * makes Sogo's separate read and write keys usable rather than decorative.
     */
    public static function authorisedRequest(Provider $provider, ?string $scope = null): PendingRequest
    {
        return self::plainRequest()->withToken(self::resolve($provider, $scope));
    }

    /**
     * A safe description of a response for a log line.
     *
     * Body redacted and truncated; headers omitted entirely, because a provider
     * that echoes the request in a `X-Debug` header would otherwise write the key
     * to disk.
     *
     * @return array<string, mixed>
     */
    public static function describe(Response $response, int $limit = 2000): array
    {
        return [
            'http_status' => $response->status(),
            'body' => \Illuminate\Support\Str::limit(
                PayloadRedactor::redactString((string) $response->body()),
                $limit,
                '…'
            ),
        ];
    }
}

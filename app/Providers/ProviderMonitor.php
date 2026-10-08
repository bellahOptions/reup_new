<?php

namespace App\Providers;

use App\Models\ProductAlert;
use App\Models\Provider;
use App\Models\ProviderHealthCheck;
use App\Providers\Support\ProviderCredentials;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Balance and health for every Wave 1 provider, with the history an incident needs.
 *
 * ## Why this is separate from `ProviderBalanceService` and `ProviderHealthService`
 *
 * Those two serve the ClubKonnect/Pairgate path, which is built on `BillProvider`
 * and reads its balances over endpoints of an older shape. Rewriting them would put
 * the live vending path at risk to add monitoring — the same trade the provider
 * split already avoided. This service serves `AbstractProviderAdapter`, which is
 * what every Wave 1 integration is.
 *
 * ## The rule this exists to enforce
 *
 * A customer must never be charged for a vend the upstream cannot perform. Two
 * checks are needed and they are genuinely different:
 *
 *   * **health** — is the provider answering an authenticated read at all?
 *   * **balance** — is there enough float upstream to fund this purchase?
 *
 * Both are answered by the same call, because every adapter exposes `balance()` and
 * it is the cheapest authenticated read any of them offers. Probing anything else
 * would either cost money or be too weak to distinguish "wrong key" from
 * "temporarily slow".
 *
 * ## What gets written, and what deliberately does not
 *
 * Every probe appends a `provider_health_checks` row and updates the provider's
 * current `health_status`. Alerts are raised through `ProductAlert`, whose
 * fingerprint index means one condition produces one open alert rather than one per
 * check — a monitor that pages 288 times a day for one stale key is a monitor
 * nobody reads.
 *
 * No credential is ever written: `error_code` records the provider's own error
 * token, and messages are redacted by the adapter before they get here.
 */
class ProviderMonitor
{
    /**
     * Consecutive failed probes before a provider is treated as unavailable.
     *
     * Three rather than one. A single dropped connection is not an outage, and a
     * monitor that declares one is a monitor that gets ignored. Three probes at the
     * scheduled interval is long enough to be real and short enough to act on.
     *
     * Configurable, with this as the default: the right number depends on how often
     * the check runs, which is a deployment decision.
     */
    private function failuresBeforeDown(): int
    {
        return max(1, (int) config('providers.alerts.failures_before_down', self::FAILURES_BEFORE_DOWN));
    }
    private const FAILURES_BEFORE_DOWN = 3;

    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {
    }

    /* =====================================================================
     | Probing
     |==================================================================== */

    /**
     * Probe one provider and record the result.
     *
     * @return array<string, mixed>
     */
    public function check(Provider $provider): array
    {
        $adapter = $this->registry->adapterFor($provider);

        $started = microtime(true);

        /*
         * `balance()` is a read, so a failure here says nothing about whether a
         * purchase would have succeeded — it is a monitoring signal, not a
         * verdict. That distinction is why an unreachable balance endpoint must not
         * by itself take vending offline.
         */
        $balance = $adapter->balance();

        $latencyMs = (int) ((microtime(true) - $started) * 1000);

        $available = (bool) ($balance['success'] ?? false);
        $message = $balance['message'] ?? null;
        $errorCode = $this->classifyFailure($message, $available);

        ProviderHealthCheck::record($provider->getKey(), $available, [
            'latency_ms' => $latencyMs,
            'balance_minor' => $balance['balance_minor'] ?? null,
            'currency' => $balance['currency'] ?? null,
            'error_code' => $errorCode,
            'message' => $message === null ? null : mb_substr((string) $message, 0, 500),
        ]);

        $failures = ProviderHealthCheck::consecutiveFailures($provider->getKey());

        $status = $this->statusFor($provider, $available, $failures);

        $provider->forceFill([
            'health_status' => $status,
            'health_checked_at' => now(),
            'health_message' => $message === null ? null : mb_substr((string) $message, 0, 255),
        ])->save();

        $this->raiseProviderAlerts($provider, $status, $errorCode, $balance, $failures);

        return [
            'provider' => $provider->slug,
            'available' => $available,
            'status' => $status,
            'balance_minor' => $balance['balance_minor'] ?? null,
            'currency' => $balance['currency'] ?? null,
            'latency_ms' => $latencyMs,
            'consecutive_failures' => $failures,
            'message' => $message,
        ];
    }

    /**
     * Probe every provider the admin console can see.
     *
     * Includes inactive and unconfigured rows so the dashboard can show *why*
     * something is not being routed to, rather than silently omitting it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function checkAll(): array
    {
        $results = [];

        foreach ($this->providers() as $provider) {
            $results[] = $this->probe($provider);
        }

        return $results;
    }

    /**
     * Probe one provider, deciding first whether a probe is even meaningful.
     *
     * The single place that decides what "not configured" means, so the dashboard,
     * the scheduled sweep and the command all agree. A provider with no credential is
     * *not* probed: doing so would produce a guaranteed authentication error every
     * interval, which would look like an outage and bury the real one — a missing
     * environment variable is a deployment state, not a fault.
     *
     * Never throws: a probe that throws is reported as degraded rather than allowed
     * to abort a sweep.
     *
     * @return array<string, mixed>
     */
    public function probe(Provider $provider): array
    {
        if (! $this->registry->hasAdapter($provider->driver)) {
            return [
                'provider' => $provider->slug,
                'available' => false,
                'status' => Provider::HEALTH_NOT_CONFIGURED,
                'balance_minor' => null,
                'currency' => null,
                'latency_ms' => null,
                'consecutive_failures' => 0,
                'message' => "No adapter ships for driver [{$provider->driver}].",
            ];
        }

        if (! ProviderCredentials::isConfigured($provider)) {
            $provider->forceFill([
                'health_status' => Provider::HEALTH_NOT_CONFIGURED,
                'health_checked_at' => now(),
                'health_message' => 'No credential is configured for this provider.',
            ])->save();

            return [
                'provider' => $provider->slug,
                'available' => false,
                'status' => Provider::HEALTH_NOT_CONFIGURED,
                'balance_minor' => null,
                'currency' => null,
                'latency_ms' => null,
                'consecutive_failures' => 0,
                'message' => 'No credential is configured for this provider.',
            ];
        }

        try {
            return $this->check($provider);
        } catch (\Throwable $e) {
            /*
             * A probe must never take the monitor down: one provider's unexpected
             * response would otherwise stop every other provider from being checked,
             * which is exactly backwards during an incident.
             */
            Log::error('Provider probe threw', [
                'provider' => $provider->slug,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return [
                'provider' => $provider->slug,
                'available' => false,
                'status' => Provider::HEALTH_DEGRADED,
                'balance_minor' => null,
                'currency' => null,
                'latency_ms' => null,
                'consecutive_failures' => 0,
                'message' => 'The probe itself failed.',
            ];
        }
    }

    /* =====================================================================
     | Reading
     |=================================================================== */

    /**
     * The current picture, for the admin dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(): array
    {
        return $this->providers()->map(function (Provider $provider) {
            $adapter = $this->registry->hasAdapter($provider->driver)
                ? $this->registry->adapterFor($provider)
                : null;

            $latest = ProviderHealthCheck::where('provider_id', $provider->getKey())
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->first();

            return [
                'provider' => $provider,
                'slug' => $provider->slug,
                'label' => $adapter?->label() ?? $provider->name,
                'driver' => $provider->driver,
                'capabilities' => (array) $provider->capabilities,
                'is_active' => (bool) $provider->is_active,
                'is_primary' => (bool) $provider->is_primary,
                'priority' => (int) $provider->priority,
                'has_adapter' => $adapter !== null,
                'is_configured' => ProviderCredentials::isConfigured($provider),
                'is_operational' => $adapter?->isOperational() ?? false,
                'operational_reason' => $adapter?->operationalReason(),
                'health_status' => $provider->health_status,
                'health_checked_at' => $provider->health_checked_at,
                'health_message' => $provider->health_message,
                'balance_minor' => $latest?->balance_minor,
                'currency' => $latest?->currency,
                'latency_ms' => $latest?->latency_ms,
                'is_sandbox' => (bool) ($latest?->is_sandbox ?? config('providers.sandbox', false)),
                'low_balance' => $this->isBelowThreshold($provider, $latest?->balance_minor),
            ];
        })->all();
    }

    /**
     * Recent probes for one provider, oldest first so a chart reads left to right.
     *
     * @return Collection<int, ProviderHealthCheck>
     */
    public function history(Provider $provider, int $limit = 50): Collection
    {
        return ProviderHealthCheck::where('provider_id', $provider->getKey())
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /** Whether a balance is below the provider's configured floor. */
    public function isBelowThreshold(Provider $provider, ?int $balanceMinor): bool
    {
        if ($balanceMinor === null || ! $provider->hasBalanceThreshold()) {
            return false;
        }

        return $balanceMinor < (int) $provider->low_balance_threshold_minor;
    }

    /**
     * Open alerts, newest first — what the dashboard puts at the top.
     *
     * @return Collection<int, ProductAlert>
     */
    public function openAlerts(int $limit = 50): Collection
    {
        return ProductAlert::query()
            ->alerts()
            ->open()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /* =====================================================================
     | Alerts
     |=================================================================== */

    /**
     * Raise the alerts a probe implies.
     *
     * @param  array<string, mixed>  $balance
     */
    private function raiseProviderAlerts(
        Provider $provider,
        string $status,
        ?string $errorCode,
        array $balance,
        int $failures
    ): void {
        if ($this->isBelowThreshold($provider, $balance['balance_minor'] ?? null)) {
            $this->alert(
                $provider,
                ProductAlert::TYPE_PROVIDER_LOW_BALANCE,
                ProductAlert::SEVERITY_CRITICAL,
                "{$provider->name} float is below its threshold",
                'Top up the provider wallet. Purchases will start failing once the float '
                . 'cannot cover them, and the customer is charged before the provider is asked.',
                [
                    'balance_minor' => $balance['balance_minor'] ?? null,
                    'threshold_minor' => $provider->low_balance_threshold_minor,
                ],
            );
        }

        if ($failures >= $this->failuresBeforeDown()) {
            $this->alert(
                $provider,
                ProductAlert::TYPE_PROVIDER_UNAVAILABLE,
                ProductAlert::SEVERITY_CRITICAL,
                "{$provider->name} has failed {$failures} consecutive checks",
                'Orders are being routed to the next provider in the chain. If this is the '
                . 'only provider for a capability, that capability is unavailable.',
                [
                    'consecutive_failures' => $failures,
                    'status' => $status,
                    'last_error' => $errorCode,
                ],
            );
        }

        if ($errorCode === 'invalid_api_key' || $errorCode === 'authentication_failed') {
            $this->alert(
                $provider,
                ProductAlert::TYPE_PROVIDER_AUTH_FAILURE,
                ProductAlert::SEVERITY_CRITICAL,
                "{$provider->name} rejected our credential",
                'Rotate the credential in the environment. Note that a rejected credential '
                . 'is classified as retryable, so orders are failing over rather than being '
                . 'held — which hides the problem behind a more expensive route.',
                ['error_code' => $errorCode],
            );
        }

        if ($errorCode === 'account_suspended') {
            $this->alert(
                $provider,
                ProductAlert::TYPE_PROVIDER_SUSPENDED,
                ProductAlert::SEVERITY_CRITICAL,
                "{$provider->name} has suspended our account",
                'This needs the vendor contacted. No configuration change fixes it.',
                ['error_code' => $errorCode],
            );
        }
    }

    /**
     * Alert that a catalogue sync could not read a provider's products.
     *
     * Called by the sync command rather than by a probe, because a provider can
     * answer a balance enquiry and still fail to serve a catalogue — and an empty
     * catalogue means products silently disappear from the storefront.
     */
    public function alertCatalogueUnavailable(Provider $provider, string $reason): void
    {
        $this->alert(
            $provider,
            ProductAlert::TYPE_CATALOGUE_UNAVAILABLE,
            ProductAlert::SEVERITY_WARNING,
            "{$provider->name} catalogue could not be read",
            'Products that depend on this catalogue will be flagged unavailable at the next '
            . 'sync rather than at the moment a customer tries to buy one.',
            ['reason' => $reason],
        );
    }

    /**
     * Alert that a provider's cost moved far enough to threaten the margin.
     *
     * The threshold is a percentage rather than an amount because a ₦10 rise means
     * something different on a ₦100 product and a ₦100,000 one.
     */
    public function alertUnusualCostChange(Provider $provider, int $previousMinor, int $currentMinor): void
    {
        $thresholdBps = (int) config('providers.alerts.cost_change_bps', 1000);

        if ($previousMinor <= 0) {
            return;
        }

        $changeBps = (int) round(abs($currentMinor - $previousMinor) * 10000 / $previousMinor);

        if ($changeBps < $thresholdBps) {
            return;
        }

        $direction = $currentMinor > $previousMinor ? 'rose' : 'fell';

        $this->alert(
            $provider,
            ProductAlert::TYPE_UNUSUAL_PRICE_CHANGE,
            ProductAlert::SEVERITY_WARNING,
            "{$provider->name} cost {$direction} by " . number_format($changeBps / 100, 2) . '%',
            'Check the affected products. A cost rise that is not followed by a repricing '
            . 'turns a profitable product into a loss-making one, and it does so silently.',
            [
                'previous_cost_minor' => $previousMinor,
                'current_cost_minor' => $currentMinor,
                'change_bps' => $changeBps,
            ],
        );
    }

    /**
     * Alert that a provider's failure or refund rate has crossed its threshold.
     *
     * Read from recorded provider transactions rather than guessed at, and measured
     * over a window so that a single refund does not trip it.
     */
    public function alertHighFailureRate(Provider $provider, int $failureBps, int $sampleSize): void
    {
        $thresholdBps = (int) config('providers.alerts.failure_rate_bps', 1000);

        if ($failureBps < $thresholdBps) {
            return;
        }

        $this->alert(
            $provider,
            ProductAlert::TYPE_HIGH_FAILURE_RATE,
            ProductAlert::SEVERITY_WARNING,
            "{$provider->name} failure rate is " . number_format($failureBps / 100, 2) . '%',
            'Review recent orders before routing more traffic to this provider.',
            [
                'failure_rate_bps' => $failureBps,
                'sample_size' => $sampleSize,
                'threshold_bps' => $thresholdBps,
            ],
        );
    }

    /**
     * Raise an alert, or refresh the one already open for this condition.
     *
     * The lookup is what makes this idempotent: a condition that persists updates its
     * existing open row instead of creating a new one every interval. An alert that
     * has been acknowledged is deliberately *not* re-opened by a repeat —
     * acknowledging means "I have seen this", and re-raising it would punish the
     * operator for having looked.
     *
     * A *resolved* alert does not block a new one. The database enforces "at most one
     * open alert per condition" through a unique index on a generated column that is
     * NULL once resolved (see the migration), so resolution genuinely re-arms the
     * monitor rather than permanently silencing the condition.
     *
     * @param  array<string, mixed>  $context
     */
    public function alert(
        Provider $provider,
        string $type,
        string $severity,
        string $title,
        string $message,
        array $context = []
    ): ProductAlert {
        $fingerprint = ProductAlert::fingerprintFor($type, null, $provider->getKey());

        $existing = ProductAlert::where('fingerprint', $fingerprint)->whereNull('resolved_at')->first();

        if ($existing !== null) {
            $existing->forceFill([
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'context' => $context,
            ])->save();

            return $existing;
        }

        try {
            return ProductAlert::create([
                'kind' => ProductAlert::KIND_ALERT,
                'provider_id' => $provider->getKey(),
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'context' => $context,
                'fingerprint' => $fingerprint,
            ]);
        } catch (QueryException $e) {
            /*
             * The unique index on the generated `open_fingerprint` column rejected
             * this insert, which means another worker opened the same alert between
             * the lookup above and this create. That is the index doing its job: two
             * alerts for one condition is exactly what it exists to prevent, so the
             * correct response is to adopt the winner rather than to fail the probe.
             */
            $existing = ProductAlert::where('fingerprint', $fingerprint)->whereNull('resolved_at')->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Resolve every open alert of a type for a provider.
     *
     * Called when a condition clears, so the dashboard reflects reality instead of
     * accumulating alerts that somebody has to close by hand — which is how a real
     * alert gets lost among stale ones.
     */
    public function resolveAlerts(Provider $provider, string $type): int
    {
        return ProductAlert::where('fingerprint', ProductAlert::fingerprintFor($type, null, $provider->getKey()))
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    /* =====================================================================
     | Helpers
     |=================================================================== */

    /** @return Collection<int, Provider> */
    private function providers(): Collection
    {
        return Provider::query()->orderBy('priority')->orderBy('id')->get();
    }

    /**
     * Turn a provider's failure message into a stable token.
     *
     * Adapters already normalise their own messages, so this only needs to
     * recognise the two that imply an operator action and leave the rest alone.
     * Matching is substring-based on a lower-cased message because the message is
     * documentation rather than a constant.
     */
    private function classifyFailure(?string $message, bool $available): ?string
    {
        if ($available || $message === null) {
            return null;
        }

        $normalised = strtolower($message);

        return match (true) {
            str_contains($normalised, 'not configured') => 'invalid_api_key',
            str_contains($normalised, 'credential'), str_contains($normalised, 'unauthor') => 'invalid_api_key',
            str_contains($normalised, 'suspend') => 'account_suspended',
            default => null,
        };
    }

    private function statusFor(Provider $provider, bool $available, int $failures): string
    {
        if ($available) {
            return Provider::HEALTH_HEALTHY;
        }

        if ($failures >= $this->failuresBeforeDown()) {
            return Provider::HEALTH_DOWN;
        }

        // Some failures but not yet a pattern: visible, and not yet acted upon.
        return $failures > 0 ? Provider::HEALTH_DEGRADED : Provider::HEALTH_UNKNOWN;
    }
}

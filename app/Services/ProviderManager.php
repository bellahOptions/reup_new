<?php

namespace App\Services;

use App\Services\Providers\BillProvider;
use App\Services\Providers\ClubKonnectProvider;
use App\Services\Providers\PairgateProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Chooses an upstream for a product, and fails over between them.
 *
 * Selection order for a product:
 *   1. providers that are configured and declare support, in configured order;
 *   2. of those, only ones that answer a liveness probe (ProviderHealthService) —
 *      an empty list here means the sale is refused before the customer is
 *      debited, which is the point of checking;
 *   3. of those, skip any whose float cannot cover the charge (see
 *      ProviderBalanceService) — this is the "confirm the admin balance first"
 *      rule, and it prevents the most common real-world failure, which is a
 *      successful customer debit followed by an upstream INSUFFICIENT_BALANCE;
 *   4. of those, the cheapest for the exact item being bought, when every
 *      candidate can be priced (ProviderManager::cheapestFirst). The configured
 *      order is the tie-breaker and the fallback;
 *   5. the first provider that actually accepts the request wins.
 *
 * Failure classification matters: only *provider-side* failures are retried
 * elsewhere. A validation error (bad meter number) or a duplicate reference is
 * returned to the caller immediately, because retrying it on a second provider
 * would either fail identically or double-charge.
 */
class ProviderManager
{
    /** @var array<string, BillProvider> */
    private array $providers;

    public function __construct(
        ClubKonnectProvider $clubKonnect,
        PairgateProvider $pairgate,
        private readonly ProviderBalanceService $balances,
        private readonly ProviderHealthService $health,
    ) {
        $this->providers = [
            $clubKonnect->name() => $clubKonnect,
            $pairgate->name() => $pairgate,
        ];
    }

    /**
     * Providers eligible for a product, best first.
     *
     * @return array<int, BillProvider>
     */
    public function candidates(string $product): array
    {
        $order = (array) config('bills.provider_order', array_keys($this->providers));

        $eligible = [];
        $unknown = [];

        foreach ($order as $name) {
            $provider = $this->providers[$name] ?? null;

            if (! $provider) {
                /*
                 * A name in the order that no provider implements is how a
                 * decommissioned vendor silently disables failover: the entry
                 * matches nothing, so it is skipped without error and whatever
                 * replaced it never runs unless it is listed too. That is
                 * exactly what a stale BILL_PROVIDER_ORDER does — dotenv does
                 * not override a variable already present in the process
                 * environment — so say so in the log instead of quietly vending
                 * without a second route.
                 */
                $unknown[] = $name;

                continue;
            }

            if ($provider->isConfigured() && $provider->supports($product)) {
                $eligible[] = $provider;
            }
        }

        if ($unknown) {
            Log::warning('providers named in BILL_PROVIDER_ORDER do not exist', [
                'unknown' => $unknown,
                'known' => array_keys($this->providers),
            ]);
        }

        if (! $eligible) {
            throw new RuntimeException(
                "No configured provider supports [{$product}]. Check PAIRGATE_API_KEY / CLUBKONNECT_* in .env."
            );
        }

        return $eligible;
    }

    /**
     * Read-only lookups (verifying a meter, a smartcard, a betting account)
     * should not be refused for float reasons, so they use the first configured
     * provider and do not consult balances.
     *
     * They do prefer a provider that is answering, though: verification is free,
     * and showing a customer an error because the first-choice upstream is down
     * when the other one is up costs a sale for nothing.
     */
    public function verifier(string $product): BillProvider
    {
        $candidates = $this->candidates($product);

        return ($this->reachable($candidates) ?: $candidates)[0];
    }

    /**
     * Providers that can serve this purchase right now, cheapest first.
     *
     * The order of the three filters is the order of the questions worth asking
     * before money moves:
     *
     *   1. is the upstream answering at all (ProviderHealthService) — an empty
     *      list here means the sale is refused and the customer keeps their
     *      balance;
     *   2. is there float to vend with (ProviderBalanceService);
     *   3. of those, who is cheapest for *this* purchase.
     *
     * @param  array<string,mixed>  $params  The parameters purchase() will be
     *                                       called with, needed to price it.
     * @return array<int, BillProvider>
     */
    public function affordable(string $product, float $amount, array $params = []): array
    {
        $candidates = $this->reachable($this->candidates($product));

        if (! $candidates) {
            return [];
        }

        return $this->cheapestFirst($this->withFloat($candidates, $product, $amount), $product, $params);
    }

    /**
     * Drop providers that are not answering.
     *
     * An empty result is not an error to swallow: it means "do not sell right
     * now", which is the cheapest possible outcome for everyone.
     *
     * @param  array<int, BillProvider>  $candidates
     * @return array<int, BillProvider>
     */
    private function reachable(array $candidates): array
    {
        if (! (bool) config('bills.check_provider_health', true)) {
            return $candidates;
        }

        $reachable = array_values(array_filter(
            $candidates,
            fn (BillProvider $provider) => $this->health->isAvailable($provider),
        ));

        if (! $reachable) {
            Log::warning('No provider passed its availability check', [
                'providers' => array_map(fn (BillProvider $p) => $p->name(), $candidates),
            ]);
        }

        return $reachable;
    }

    /**
     * Keep the providers whose float covers the charge.
     *
     * @param  array<int, BillProvider>  $candidates
     * @return array<int, BillProvider>
     */
    private function withFloat(array $candidates, string $product, float $amount): array
    {
        if (! (bool) config('bills.check_provider_balance', true)) {
            return $candidates;
        }

        $affordable = array_values(array_filter(
            $candidates,
            fn (BillProvider $provider) => $this->balances->canCover($provider, $amount),
        ));

        // If balances cannot be read at all, do not block the sale — the
        // upstream will reject it and the pipeline refunds. Blocking here would
        // take the platform down whenever a balance endpoint hiccups.
        if (! $affordable) {
            Log::warning('No provider reported sufficient float; attempting primary anyway', [
                'product' => $product,
                'amount' => $amount,
                'candidates' => array_map(fn (BillProvider $p) => $p->name(), $candidates),
            ]);

            return $candidates;
        }

        return $affordable;
    }

    /**
     * Cheapest first, but only when every candidate has a price for this exact
     * purchase.
     *
     * One unknown price and the comparison is off: the configured order stands.
     * That rule is what keeps a gap in the pricing data — an unmapped plan, an
     * unpublished airtime discount, a catalogue that is cold — from quietly
     * redirecting traffic (and margin) somewhere the operator never chose.
     *
     * @param  array<int, BillProvider>  $candidates
     * @return array<int, BillProvider>
     */
    private function cheapestFirst(array $candidates, string $product, array $params): array
    {
        if (count($candidates) < 2 || ! (bool) config('bills.optimise_cost', true)) {
            return $candidates;
        }

        $costs = [];

        foreach ($candidates as $provider) {
            $cost = $provider->cost($product, $params);

            if ($cost === null) {
                Log::debug('Provider prices are incomplete; keeping the configured order', [
                    'product' => $product,
                    'unknown' => $provider->name(),
                ]);

                return $candidates;
            }

            $costs[$provider->name()] = $cost;
        }

        // The incoming order is the operator's preference, so it breaks ties.
        $preference = array_flip(array_map(fn (BillProvider $p) => $p->name(), $candidates));

        usort($candidates, function (BillProvider $a, BillProvider $b) use ($costs, $preference) {
            return ($costs[$a->name()] <=> $costs[$b->name()])
                ?: ($preference[$a->name()] <=> $preference[$b->name()]);
        });

        Log::info('Provider chosen by price', [
            'product' => $product,
            'costs' => $costs,
            'chosen' => $candidates[0]->name(),
        ]);

        return $candidates;
    }

    /**
     * Forget a provider's cached availability, so the next purchase re-probes.
     *
     * Called after a real request failed: whatever the probe said a minute ago,
     * the request itself is better evidence.
     */
    public function invalidateHealth(BillProvider $provider): void
    {
        $this->health->invalidate($provider);
    }

    public function byName(string $name): ?BillProvider
    {
        return $this->providers[$name] ?? null;
    }

    /** @return array<int, BillProvider> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * Classify a provider response.
     *
     * ## The four outcomes
     *
     *   * **success**   — the provider vended. Money and goods both moved.
     *   * **retryable** — the request provably did not reach the customer's
     *     account, and the provider itself told us so. Safe to send to another
     *     provider, because that provider has never seen this order.
     *   * **unknown**   — we sent the request and never learned the outcome
     *     (a transport timeout, a connection reset, a 502 from an intermediary,
     *     an unparseable body). The order may have been fulfilled. Retrying it
     *     elsewhere can vend twice and charge the customer once.
     *   * **fatal**     — the provider rejected it outright and deterministically
     *     (bad meter number, invalid phone, duplicate reference, business rule).
     *     Another provider rejects it identically, so retrying only wastes time.
     *
     * **UNKNOWN is not FAILED and is not RETRYABLE.** Treating a timeout as
     * retryable is the single most expensive mistake available in this pipeline:
     * a customer in a vending outage gets two electricity tokens for one
     * payment, and the platform cannot tell which one to claw back.
     *
     * @return string one of: success | retryable | unknown | fatal
     */
    public function classify(BillProvider $provider, ?array $response): string
    {
        if ($provider->isSuccess($response)) {
            return 'success';
        }

        /*
         * A null response means the adapter could not get an answer at all.
         * That is the definition of unknown: it is not a rejection.
         */
        if ($response === null) {
            return 'unknown';
        }

        $status = strtoupper((string) ($response['status'] ?? ''));

        if (in_array($status, self::UNKNOWN_STATUSES, true)) {
            return 'unknown';
        }

        // Conditions another provider may not share. Everything here means the
        // request never reached the customer's account — rejected for float,
        // unreachable, rate limited, refused before any charge — so passing it
        // to the next provider cannot double-vend.
        //
        // Deliberately absent:
        //   * DUPLICATE_REFERENCE — a second vendor has not seen that reference,
        //     so retrying would vend twice;
        //   * the validation errors, which another provider rejects identically;
        //   * anything in UNKNOWN_STATUSES above.
        return in_array($status, self::RETRYABLE_STATUSES, true) ? 'retryable' : 'fatal';
    }

    /**
     * Provider conditions that mean "we do not know whether this vended".
     *
     * A transport timeout is the canonical case: the request left this process,
     * the provider may have processed it, and the answer was lost. Auth errors
     * and unparseable bodies are included because both leave the request's fate
     * undetermined — an unparseable body in particular may be a successful vend
     * whose response we could not decode.
     */
    private const UNKNOWN_STATUSES = [
        'TIMEOUT',
        'GATEWAY_TIMEOUT',
        'CONNECTION_RESET',
        'INVALID_RESPONSE',
        'UNPARSEABLE_RESPONSE',
        'MALFORMED_RESPONSE',
        'AUTH_ERROR',
        'UNKNOWN',
    ];

    /**
     * Provider conditions where the request provably did not vend.
     */
    private const RETRYABLE_STATUSES = [
        'INSUFFICIENT_BALANCE',
        'API_ERROR',
        'SERVICE_UNAVAILABLE',
        'RATE_LIMITED',
        'NOT_CONFIGURED',
        'INVALID_PROVIDER',
        'PLAN_NOT_FOUND',
        'UNSUPPORTED',
        'INVALID_AMOUNT',
        'EXCEPTION',
    ];
}

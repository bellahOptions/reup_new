<?php

namespace App\Services;

use App\Pricing\PriceChangeQuote;
use App\Pricing\PriceQuote;
use App\Pricing\PricingEngine;
use App\Support\Money;

/**
 * One place a data bundle is priced, for both the page and the purchase.
 *
 * ## The defect this exists to remove
 *
 * The price list and the purchase path each priced a bundle themselves. The page
 * read `your_price` out of the cached catalogue — a figure computed when the
 * cache was last filled — while the purchase looked the plan up again and put it
 * through the pricing engine. Those are the same calculation only while nothing
 * has changed, so any provider cost movement or any edit to a pricing rule
 * between the two made the page's figure and the charged figure disagree. The
 * customer saw one number, submitted it, and was told a different one.
 *
 * Worse, the outcome depended on which route they used: the pricelist endpoint
 * ignored the posted price and simply charged the new figure, while the airtime
 * page refused and asked them to submit again. Same bundle, same moment, two
 * different answers — because the decision lived in two controllers instead of
 * one service.
 *
 * **So there is now exactly one method that decides what a bundle costs a
 * customer**, `quote()`, and both surfaces call it. Display and charge cannot
 * drift because they are not two implementations.
 *
 * ## Why the cache is still the right source
 *
 * `quote()` reads the cached catalogue rather than calling the provider. A
 * customer waiting for a price must not wait for NelloBytes, and a full price
 * list per keystroke would spend the rate limit that purchases need. The cache is
 * refreshed by the scheduled synchronisation, and when a provider cost does move,
 * this is what detects it and asks the customer to confirm — see
 * `PriceChangeQuote`.
 *
 * ## Nothing here trusts the client
 *
 * No method accepts a price. The only client-supplied value is a plan identifier,
 * and it is used to *look up* a row — never to supply a figure. A confirmation
 * token is likewise an identifier: the amount is read back from the server-side
 * quote it names and re-derived, not taken from the request.
 */
class DataPriceService
{
    /**
     * How much the price may move before it is worth mentioning.
     *
     * A single kobo is a rounding artefact from the naira/kobo boundary, not a
     * re-price, and demanding a confirmation for it would train customers to
     * click through the modal without reading it. One kobo is also exactly the
     * smallest representable difference, so this is the tightest threshold that
     * does not fire on representation noise.
     */
    private const NEGLIGIBLE_MINOR = 1;

    public function __construct(
        private readonly ClubKonnectCatalogue $catalogue,
        private readonly PricingEngine $pricing,
        private readonly ProviderCostResolver $costs,
        private readonly PriceChangeQuote $quotes,
    ) {
    }

    /**
     * The priced catalogue, for the price list and the JSON API.
     *
     * @return array<string,mixed>
     */
    public function cataloguePayload(): array
    {
        return $this->catalogue->plans();
    }

    /**
     * Price one bundle, exactly as the purchase will.
     *
     * The catalogue row is looked up by identifier on every call, so the figure
     * returned here is the figure the engine produces *now*, not a number that
     * travelled through the browser.
     *
     * @return array{
     *     found:bool,
     *     plan:?array<string,mixed>,
     *     quote:?PriceQuote,
     *     price_minor:?int,
     *     price:?Money,
     *     network_key:?string,
     *     network_name:?string,
     *     refusal:?string,
     *     catalogue_stale:bool,
     *     catalogue_age_seconds:?int
     * }
     */
    /**
     * Price one data bundle.
     *
     * ## `$networkKey` is the network the caller believes the bundle belongs to
     *
     * It is checked against the plan's **own** network, which is the authority: a
     * plan's network is what decides where the data can be delivered, and a caller
     * that names a different one has a stale or tampered form.
     *
     * The check lives here rather than only in the controller because this is the
     * single place a bundle is priced, and the only place that can promise it. The
     * controller previously passed a network and this method ignored it — so a
     * mismatched pair was priced happily and stored under the plan's network, which
     * is what let a purchase claim one network and dispatch another.
     *
     * A `null` key skips the check, which is what read-only callers (the price list,
     * the admin preview) pass when they have no network claim to verify.
     */
    public function quote(?string $planIdentifier, ?string $networkKey = null): array
    {
        $plan = $planIdentifier === null || $planIdentifier === ''
            ? null
            : $this->catalogue->plan($planIdentifier);

        $status = $this->catalogue->status();

        if ($plan === null) {
            return $this->outcome(
                found: false,
                plan: null,
                quote: null,
                networkKey: $networkKey,
                networkName: null,
                refusal: 'That data bundle is no longer available. Please refresh the page and choose it again.',
                status: $status,
            );
        }

        $resolvedKey = NetworkResolver::key((string) ($plan['network_code'] ?? ''), 'clubkonnect');

        /*
         * The caller's claim, checked against the plan's own network.
         *
         * Refused rather than corrected. The plan's network is authoritative for
         * *where the data goes*, but silently sending a different network from the
         * one the customer selected is how a purchase spends money on a bundle that
         * never arrives — and the customer is the only one who can say whether the
         * form was stale or the network was ported.
         */
        if ($networkKey !== null && $resolvedKey !== null && $networkKey !== $resolvedKey) {
            return $this->outcome(
                found: true,
                plan: $plan,
                quote: null,
                networkKey: $resolvedKey,
                networkName: NetworkResolver::displayName((string) ($plan['network_code'] ?? ''), 'clubkonnect'),
                refusal: 'That data bundle belongs to a different network from the one selected. '
                    . 'Please reselect the network and bundle.',
                status: $status,
            );
        }

        $cost = $this->costs->forDataBundle($plan);

        if (! $cost) {
            return $this->outcome(
                found: true,
                plan: $plan,
                quote: null,
                networkKey: $resolvedKey,
                networkName: NetworkResolver::displayName((string) ($plan['network_code'] ?? ''), 'clubkonnect'),
                refusal: $this->costs->unavailableMessage(),
                status: $status,
            );
        }

        $quote = $this->pricing->quote(
            providerCost: $cost->costMinor,
            quantity: 1,
            context: [
                'network' => $resolvedKey,
                'capability' => ProviderCostResolver::CAPABILITY_DATA,
            ],
            /*
             * No price basis for a bundle: the customer buys a *bundle*, not a
             * face value, so the engine prices it cost-plus from the verified
             * cost. A FACE_VALUE rule on data therefore prices at cost — which is
             * exactly what face value means for a bundle, and why data is
             * normally given a markup rule.
             */
            costMeta: $cost->toEngineMetadata(),
        );

        return $this->outcome(
            found: true,
            plan: $plan,
            quote: $quote,
            networkKey: $resolvedKey,
            networkName: NetworkResolver::displayName((string) ($plan['network_code'] ?? ''), 'clubkonnect'),
            refusal: $quote->isSellable() ? null : ($quote->refusalReason ?? $this->costs->unavailableMessage()),
            status: $status,
        );
    }

    /**
     * Whether a presented price differs from the price now.
     *
     * Returns false when either side is unknown, because "we could not tell"
     * must not be answered with "no change" — the caller's next step for an
     * unknown price is a refusal, not a purchase.
     */
    public function priceHasMoved(?int $presentedMinor, ?int $currentMinor): bool
    {
        if ($presentedMinor === null || $currentMinor === null) {
            return false;
        }

        return abs($presentedMinor - $currentMinor) > self::NEGLIGIBLE_MINOR;
    }

    /**
     * Parse a decimal naira price from a form into exact kobo.
     *
     * Returns null rather than zero for anything unparseable: a malformed price
     * is not a free bundle, and the caller's answer to null is to ignore the
     * submitted figure and ask for confirmation.
     */
    public function presentedMinor($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return Money::fromNaira($value)->minor();
    }

    /**
     * The confirmation token, and the expiry the modal counts down.
     *
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function issueConfirmation(
        \App\Models\User $user,
        array $plan,
        PriceQuote $quote,
        ?string $idempotencyKey = null
    ): array {
        return $this->quotes->issue(
            $user,
            PriceChangeQuote::descriptorForPlan($plan),
            $quote->customerPriceMinor,
            $quote->pricingRuleId,
            $idempotencyKey,
            PriceChangeQuote::NAMESPACE_DATA,
        );
    }

    public function quotes(): PriceChangeQuote
    {
        return $this->quotes;
    }

    /**
     * The single shape every outcome has, so a caller cannot read a key that is
     * sometimes absent.
     *
     * @param  array<string,mixed>  $status
     * @return array<string,mixed>
     */
    private function outcome(
        bool $found,
        ?array $plan,
        ?PriceQuote $quote,
        ?string $networkKey,
        ?string $networkName,
        ?string $refusal,
        array $status
    ): array {
        $priceMinor = $quote !== null && $quote->isSellable() ? $quote->customerPriceMinor : null;

        return [
            'found' => $found,
            'plan' => $plan,
            'quote' => $quote,
            'price_minor' => $priceMinor,
            'price' => $priceMinor === null ? null : Money::fromMinor($priceMinor),
            'network_key' => $networkKey,
            'network_name' => $networkName,
            'refusal' => $refusal,
            /*
             * The parameters the provider must be called with. Built here because
             * they are a property of the plan, not of the route that sold it: two
             * callers assembling their own would eventually disagree about which
             * id identifies the bundle.
             *
             * @var array<string,mixed>
             */
            'provider_params' => [
                'network' => (string) ($plan['network_code'] ?? ''),
                'plan' => (string) ($plan['plan_id'] ?? $plan['plan_code'] ?? ''),
            ],
            /*
             * Carried on every outcome so the page can say "this price is old"
             * without a second request, and so the purchase path can refuse a sale
             * priced from a catalogue it cannot vouch for.
             */
            'catalogue_stale' => (bool) ($status['stale'] ?? true),
            'catalogue_age_seconds' => $status['age_seconds'] ?? null,
        ];
    }
}

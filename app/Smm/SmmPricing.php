<?php

namespace App\Smm;

use App\Models\ProviderProduct;
use App\Pricing\PriceQuote;
use App\Pricing\PricingEngine;
use App\Pricing\ProviderCost;
use App\Support\Money;

/**
 * What a social-boost order costs ReUp, and the floor it must be sold above.
 *
 * ## The provider's pricing model, read from the documentation
 *
 * Nitro publishes a **rate per 1,000 units** and charges
 *
 *     charge = rate × quantity ÷ 1000
 *
 * so a 1,000-unit order at a rate of ₦2,720 costs ₦2,720 — not ₦2,720,000. The
 * divisor is `NitroSmmProvider::rateDivisor()`, which reads the same configured
 * constant the adapter uses, so the price we quote and the charge the provider
 * applies cannot be computed with two different divisors.
 *
 * The arithmetic is integer kobo and half-up to the kobo, exactly like every
 * other monetary calculation in this application. `NitroSmmProvider::expectedCost()`
 * is the single implementation; this class calls it rather than re-deriving it.
 *
 * ## The mandatory floor
 *
 * Every social-boost purchase must earn at least `pricing.smm.minimum_markup_bps`
 * (10% by default) over the verified provider cost. This is enforced here — on the
 * *quote*, immediately before it is used — rather than only as a validation on the
 * pricing console, because a console check constrains what an operator may type
 * and this constrains what the platform may charge. A rule created directly, a
 * narrower scope overriding a broader one, or a future code path that forgets the
 * console are all caught by the same sentence.
 *
 * It works on the quote's **effective markup**, `(price - cost) / cost`, which is
 * the same basis the floor is stated in. Using the gross margin instead would be
 * the classic confusion: a 10% margin on ₦1,000 is ₦111.11 of profit, while a 10%
 * *markup* is ₦100, and the two are not interchangeable.
 *
 * ## Markup is not margin
 *
 * Both are reported and neither is derived from the other. `PriceQuote` carries
 * `markupPercentageBps` and `profitMarginBps` separately and `PriceQuote`'s own
 * docblock says why: on ₦1,000 cost and ₦1,250 price the markup is 25% and the
 * margin is 20%.
 */
class SmmPricing
{
    public function __construct(
        private readonly PricingEngine $engine,
    ) {
    }

    /**
     * The configured floor, in basis points.
     *
     * Bounded below at 1 bps so a misconfiguration cannot silently disable the
     * control; there is deliberately no upper bound, because a higher floor is a
     * stricter policy rather than a broken one.
     */
    public function minimumMarkupBps(): int
    {
        return max(1, (int) config('pricing.smm.minimum_markup_bps', 1000));
    }

    /**
     * Whether a provider rate is too old to price on.
     *
     * Nitro improves rates automatically as monthly volume rises, so the error is
     * one-directional: a stale rate sells at yesterday's cost and buys at today's.
     */
    public function rateIsStale(ProviderProduct $offering): bool
    {
        $maxAgeHours = (int) config('pricing.smm.rate_max_age_hours', 24);

        if ($maxAgeHours <= 0 || config('pricing.smm.allow_stale_rate', false)) {
            return false;
        }

        $seenAt = $offering->cost_synced_at ?? $offering->last_seen_at;

        if ($seenAt === null) {
            return true;
        }

        return $seenAt->addHours($maxAgeHours)->lessThan(now());
    }

    /**
     * What Nitro will charge for this quantity, as a `ProviderCost`.
     *
     * Returns null rather than a number when the rate cannot be established or is
     * stale, because the alternative — inventing one — makes every profit figure
     * downstream a fiction and would let an unprofitable order through silently.
     */
    public function providerCost(ProviderProduct $offering, int $quantity): ?ProviderCost
    {
        if ($quantity < 1) {
            return null;
        }

        $rateMinor = $offering->provider_rate_minor;

        if ($rateMinor === null || $rateMinor <= 0) {
            /*
             * No published rate. The catalogue sync stores null rather than
             * carrying the last figure forward, deliberately: a service whose rate
             * the provider has stopped quoting must become unsellable, not keep a
             * price derived from a rate that no longer exists.
             */
            return null;
        }

        if ($this->rateIsStale($offering)) {
            return null;
        }

        $adapter = app(\App\Providers\ProviderRegistry::class)
            ->adapterFor($offering->provider);

        if (! $adapter instanceof \App\Providers\Adapters\NitroSmmProvider) {
            return null;
        }

        $costMinor = $adapter->expectedCost($rateMinor, $quantity)->minor();

        if ($costMinor <= 0) {
            /*
             * A cost that rounds to zero kobo cannot be marked up meaningfully and
             * would let a purchase through for nothing. Refused rather than sold.
             */
            return null;
        }

        return new ProviderCost(
            costMinor: $costMinor,
            listMinor: $costMinor,
            discountBps: 0,
            source: ProviderCost::SOURCE_PROVIDER_PRODUCT,
            verifiedAt: ($offering->cost_synced_at ?? $offering->last_seen_at)?->toDateTimeString(),
            estimated: false,
            context: [
                'provider_product_id' => $offering->getKey(),
                'provider_service_id' => $offering->provider_product_id,
                'rate_minor' => $rateMinor,
                'rate_divisor' => $adapter->rateDivisor(),
                'quantity' => $quantity,
            ],
        );
    }

    /**
     * Price a social-boost order and enforce the floor.
     *
     * @param  array<string,mixed>  $context  pricing-rule resolution context
     * @return array{
     *     ok:bool,
     *     quote:?PriceQuote,
     *     cost:?ProviderCost,
     *     message:?string,
     *     effective_markup_bps:?int,
     *     minimum_markup_bps:int,
     *     shortfall_minor:?int
     * }
     */
    public function quote(ProviderProduct $offering, int $quantity, array $context = []): array
    {
        $minimumBps = $this->minimumMarkupBps();

        $cost = $this->providerCost($offering, $quantity);

        if (! $cost) {
            return $this->refusal(
                'This service is temporarily unavailable while we confirm its pricing. '
                . 'No money has left your wallet.',
                null,
                $minimumBps,
            );
        }

        $quote = $this->engine->quote(
            providerCost: $cost->costMinor,
            quantity: $quantity,
            context: $context,
            costMeta: $cost->toEngineMetadata(),
        );

        if (! $quote->isSellable()) {
            /*
             * The engine's own controls — a missing rule, a loss, a breached floor
             * or margin — already refused. Passing its reason through rather than
             * inventing a second one keeps a single explanation of why.
             */
            return $this->refusal(
                $quote->refusalReason ?? 'This service is temporarily unavailable.',
                $quote,
                $minimumBps,
                $cost,
            );
        }

        $effectiveBps = $this->effectiveMarkupBps($quote);

        if ($effectiveBps < $minimumBps) {
            /*
             * ## Why this is a refusal and not a price rise
             *
             * The floor is a margin control. Silently lifting the price to meet it
             * would mean the customer is charged a figure the pricing console does
             * not show and no rule produces — which is exactly the "the price is
             * whatever the code decided" problem the centralised engine exists to
             * remove. Refusing is visible, auditable and fixable: an operator sees
             * the service as unpriced and configures a rule that meets the floor.
             */
            return $this->refusal(
                'This service is temporarily unavailable while we update its pricing. '
                . 'No money has left your wallet.',
                $quote,
                $minimumBps,
                $cost,
            );
        }

        return [
            'ok' => true,
            'quote' => $quote,
            'cost' => $cost,
            'message' => null,
            'effective_markup_bps' => $effectiveBps,
            'minimum_markup_bps' => $minimumBps,
            'shortfall_minor' => null,
        ];
    }

    /**
     * The markup a quote actually applies, in basis points, on the cost basis.
     *
     * `(price - cost) / cost`, which is what "markup" means. Guarded against a
     * zero or negative cost: a quote priced against no cost is not a markup of
     * infinity, it is a quote that cannot be evaluated — and it is refused
     * upstream rather than reaching here.
     */
    public function effectiveMarkupBps(PriceQuote $quote): int
    {
        $base = $quote->baseCostMinor;

        if ($base <= 0) {
            return 0;
        }

        return $this->engine->marginBps($quote->customerPriceMinor - $base, $base);
    }

    /**
     * The lowest price that satisfies the floor for a cost.
     *
     * Used by the console's preview so an operator can see the price the floor
     * implies, and by the admin screen's "what would this need to be" figure. It
     * is a *display* calculation — nothing charges from it, because the engine is
     * the only thing that decides a price.
     */
    public function minimumPriceFor(int $costMinor, ?int $markupBps = null): Money
    {
        $bps = $markupBps ?? $this->minimumMarkupBps();

        return Money::fromMinor($costMinor + $this->engine->applyBps($costMinor, $bps));
    }

    /**
     * A shortfall in kobo between a price and the floor, or zero when it meets it.
     *
     * Reported so the admin screen can say how far below the floor a service is
     * rather than only that it is.
     */
    public function shortfallMinor(PriceQuote $quote): int
    {
        $base = $quote->baseCostMinor;

        if ($base <= 0) {
            return 0;
        }

        $required = $this->minimumPriceFor($base)->minor();

        return max(0, $required - $quote->customerPriceMinor);
    }

    /**
     * @return array{ok:bool,quote:?PriceQuote,cost:?ProviderCost,message:string,effective_markup_bps:?int,minimum_markup_bps:int,shortfall_minor:?int}
     */
    private function refusal(
        string $message,
        ?PriceQuote $quote,
        int $minimumBps,
        ?ProviderCost $cost = null
    ): array {
        return [
            'ok' => false,
            'quote' => $quote,
            'cost' => $cost,
            'message' => $message,
            'effective_markup_bps' => $quote === null ? null : $this->effectiveMarkupBps($quote),
            'minimum_markup_bps' => $minimumBps,
            'shortfall_minor' => $quote === null ? null : $this->shortfallMinor($quote),
        ];
    }
}

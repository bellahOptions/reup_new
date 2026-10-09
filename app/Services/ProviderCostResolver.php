<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\ProviderNetworkCost;
use App\Models\ProviderProduct;
use App\Pricing\ProviderCost;
use Illuminate\Support\Facades\Log;

/**
 * Establishes what a provider charges ReUp for one purchase.
 *
 * ## Where this sits
 *
 * The pricing engine is deliberately blind to *how* a cost was obtained: it is
 * handed a number and a rule and returns a quote. This class is the layer that
 * produces that number, and its whole job is to be honest about how good the
 * number is. "We do not know the cost" is a first-class answer here, because the
 * alternative — inventing one — makes every profit figure downstream a fiction.
 *
 * ## Resolution order
 *
 * Most authoritative first, per the brief:
 *
 *   1. an actual quote or charge for this exact transaction (`forTransaction`);
 *   2. a verified provider catalogue price for the exact product (`forDataBundle`
 *      reads the cached ClubKonnect catalogue);
 *   3. an explicitly configured, approved term — the per-network airtime discount
 *      in `provider_network_costs` (`forAirtime`).
 *
 * A configured term is only used when `pricing.policy.allow_configured_assumptions`
 * is on, and the result is flagged `estimated = true` so the profit booked against
 * it is reported as estimated rather than realised.
 *
 * ## A stale cost is an unknown cost
 *
 * A figure older than `pricing.policy.max_age_hours` is refused by default. Selling
 * on a stale cost is how a margin disappears silently: the provider re-priced, no
 * one updated the row, and every sale in between booked a profit that was not
 * there.
 */
class ProviderCostResolver
{
    /** The capability strings used by `provider_network_costs.capability`. */
    public const CAPABILITY_AIRTIME = 'airtime';

    public const CAPABILITY_DATA = 'data';

    /**
     * The effective cost of selling `$faceValue` of airtime on `$networkKey`.
     *
     * @param  int  $faceValueMinor  what the customer asked for, in kobo
     * @param  string|null  $networkKey  canonical network key ('mtn'…)
     * @param  string|null  $providerSlug  restrict to one provider's term
     * @return ProviderCost|null null when no usable term exists — the caller must
     *                           refuse the sale rather than invent a cost
     */
    public function forAirtime(int $faceValueMinor, ?string $networkKey, ?string $providerSlug = null): ?ProviderCost
    {
        if ($faceValueMinor <= 0) {
            return null;
        }

        $term = $this->airtimeTerm($networkKey, $providerSlug);

        if (! $term) {
            /*
             * No configured term. The cost is genuinely unknown, and the brief is
             * explicit that it must not be invented. The purchase path turns this
             * into a customer-friendly refusal.
             */
            Log::warning('No provider cost term configured for airtime', [
                'network' => $networkKey,
                'provider' => $providerSlug,
                'face_value_minor' => $faceValueMinor,
            ]);

            return null;
        }

        $estimated = ! $term->isVerified();

        /*
         * A configured-but-unverified rate is an assumption. Whether it may price a
         * sale at all is policy, and the default is yes — the 3% is a known business
         * assumption, not a guess — but the resulting profit is marked estimated.
         */
        if ($estimated && ! config('pricing.policy.allow_configured_assumptions', true)) {
            Log::warning('Airtime provider cost is unverified and assumptions are disabled', [
                'network' => $networkKey,
                'provider' => $term->provider?->slug,
            ]);

            return null;
        }

        if ($term->isStale() && ! config('pricing.policy.allow_stale', false)) {
            Log::warning('Airtime provider cost is stale and stale costs are not allowed', [
                'network' => $networkKey,
                'provider' => $term->provider?->slug,
                'verified_at' => $term->verified_at?->toDateTimeString(),
            ]);

            return null;
        }

        $cost = $term->costFor($faceValueMinor);

        return new ProviderCost(
            costMinor: $cost->minor(),
            listMinor: $faceValueMinor,
            discountBps: (int) $term->discount_bps,
            source: ProviderCost::SOURCE_CONFIGURED,
            verifiedAt: $term->verified_at?->toDateTimeString(),
            estimated: $estimated,
            context: [
                'provider_slug' => $term->provider?->slug,
                'network' => $networkKey,
                'face_value_minor' => $faceValueMinor,
                'provider_fee_minor' => (int) $term->provider_fee_minor,
            ],
        );
    }

    /**
     * The effective cost of a data bundle, from the provider's catalogue.
     *
     * `$catalogueCostMinor` is the price the provider charges us for the bundle,
     * which the catalogue reveals as `clubkonnect_price`. A bundle whose cost the
     * catalogue does not state, or whose catalogue copy is cold, yields null: the
     * sale is refused rather than priced against a guess.
     *
     * @param  array<string,mixed>|null  $plan  the catalogue row that was resolved
     * @return ProviderCost|null
     */
    public function forDataBundle(?array $plan, ?string $providerSlug = null): ?ProviderCost
    {
        if (! is_array($plan)) {
            return null;
        }

        $cost = $plan['clubkonnect_price'] ?? null;

        if (! is_numeric($cost) || (float) $cost <= 0) {
            Log::warning('Data bundle has no usable provider cost in the catalogue', [
                'plan_code' => $plan['plan_code'] ?? null,
                'plan_id' => $plan['plan_id'] ?? null,
            ]);

            return null;
        }

        $costMinor = (int) round(((float) $cost) * 100);

        return new ProviderCost(
            costMinor: $costMinor,
            listMinor: $costMinor,
            discountBps: 0,
            source: ProviderCost::SOURCE_CATALOGUE,
            /*
             * The catalogue is re-fetched every ten minutes and a day-old copy is
             * served during an outage, so a non-null value is fresh. Staleness is
             * enforced by the catalogue's own TTL rather than a second timestamp
             * here; the fetch time is recorded below for the snapshot.
             */
            verifiedAt: $plan['_catalogue_fetched_at'] ?? null,
            estimated: false,
            context: [
                'provider_slug' => $providerSlug,
                'plan_code' => $plan['plan_code'] ?? null,
                'plan_id' => $plan['plan_id'] ?? null,
                'plan_name' => $plan['plan_name'] ?? null,
                'network' => $plan['network'] ?? null,
            ],
        );
    }

    /**
     * A cost recorded against a mapped provider product row.
     *
     * Layer 3 of the resolution order, for services whose commercial model is a
     * per-product price rather than a rate.
     *
     * @return ProviderCost|null
     */
    public function forProviderProduct(?ProviderProduct $offering): ?ProviderCost
    {
        if (! $offering || $offering->provider_cost_minor === null) {
            return null;
        }

        $verifiedAt = $offering->cost_synced_at ?? $offering->last_seen_at;

        $stale = $verifiedAt !== null && $this->isTimestampStale($verifiedAt);

        if ($stale && ! config('pricing.policy.allow_stale', false)) {
            Log::warning('Provider product cost is stale', [
                'provider_product_id' => $offering->getKey(),
                'cost_synced_at' => $offering->cost_synced_at?->toDateTimeString(),
            ]);

            return null;
        }

        return new ProviderCost(
            costMinor: (int) $offering->provider_cost_minor,
            listMinor: (int) $offering->provider_cost_minor,
            discountBps: 0,
            source: ProviderCost::SOURCE_PROVIDER_PRODUCT,
            verifiedAt: $verifiedAt?->toDateTimeString(),
            estimated: false,
            context: [
                'provider_product_id' => $offering->getKey(),
                'provider_name' => $offering->provider_name,
            ],
        );
    }

    /**
     * A cost the provider quoted for this exact transaction.
     *
     * The most authoritative source, used when a provider response states what it
     * actually charged.
     *
     * @return ProviderCost|null
     */
    public function forTransactionQuote(int $quotedCostMinor, ?string $providerSlug = null): ?ProviderCost
    {
        if ($quotedCostMinor <= 0) {
            return null;
        }

        return new ProviderCost(
            costMinor: $quotedCostMinor,
            listMinor: $quotedCostMinor,
            discountBps: 0,
            source: ProviderCost::SOURCE_TRANSACTION,
            verifiedAt: now()->toDateTimeString(),
            estimated: false,
            context: ['provider_slug' => $providerSlug],
        );
    }

    /**
     * Whether the platform is allowed to sell when no cost could be established.
     *
     * Defaults to false, and the policy value is `refuse`. There is no setting that
     * means "sell anyway on a guessed cost"; the only variation is whether the block
     * is also flagged for an administrator.
     */
    public function maySellWithoutVerifiedCost(): bool
    {
        return false;
    }

    /**
     * The refusal message shown to a customer when a cost cannot be established.
     *
     * Deliberately says nothing about costs, margins or providers — those are
     * internal, and telling a customer "we cannot confirm our cost" invites exactly
     * the wrong conversation. It states the service is unavailable and that nothing
     * was charged, which is the part that matters to them.
     */
    public function unavailableMessage(): string
    {
        return 'This service is temporarily unavailable while we confirm its pricing. '
            . 'No money has left your wallet.';
    }

    /**
     * The active airtime term for a network, most specific first.
     *
     * A provider-specific row beats an "any network" row, and an inactive row is
     * never eligible.
     */
    private function airtimeTerm(?string $networkKey, ?string $providerSlug): ?ProviderNetworkCost
    {
        $query = ProviderNetworkCost::query()
            ->active()
            ->forCapability(self::CAPABILITY_AIRTIME)
            ->with('provider');

        if ($providerSlug !== null) {
            $providerId = Provider::where('slug', $providerSlug)->value('id');

            if ($providerId === null) {
                return null;
            }

            $query->where('provider_id', $providerId);
        }

        if ($networkKey !== null) {
            $specific = (clone $query)->where('network', $networkKey)->first();

            if ($specific) {
                return $specific;
            }
        }

        // Fall back to a provider-wide term covering every network.
        return $query->whereNull('network')->first();
    }

    private function isTimestampStale(\Illuminate\Support\Carbon $verifiedAt): bool
    {
        $maxAgeHours = (int) config('pricing.policy.max_age_hours', 168);

        if ($maxAgeHours <= 0) {
            return false;
        }

        return $verifiedAt->addHours($maxAgeHours)->lessThan(now());
    }
}

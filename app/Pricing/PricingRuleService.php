<?php

namespace App\Pricing;

use App\Models\AdminLog;
use App\Models\PricingRule;
use App\Models\PricingRuleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creation, editing, versioning and audit of pricing rules.
 *
 * ## Why rule changes need their own service
 *
 * A pricing rule is the most consequential configuration in the application: it
 * decides what every customer pays and what margin the business earns. Changing
 * one is therefore treated like a financial operation, not like editing a setting:
 *
 *   * the **previous** state is written to `pricing_rule_versions` before the new
 *     one is saved, so "what was the markup when this order was priced" is
 *     answerable from the database;
 *   * a **field-level diff** is recorded, so the audit log reads
 *     "Markup %: 20.00% → 25.00%" rather than "rule 4 updated";
 *   * the acting administrator and a reason are captured;
 *   * an `AdminLog` entry is written for the privileged-action feed.
 *
 * ## Why it is not simply a model observer
 *
 * An observer would capture edits made by any code path including a seeder, and
 * would have no actor or reason to record. Requiring callers to come through
 * `update()` means an actor and a reason are always available — and a seeder that
 * wants to skip the audit can say so explicitly rather than silently producing
 * unattributed financial history.
 */
class PricingRuleService
{
    /** Fields whose change is financially significant and always audited. */
    private const TRACKED_FIELDS = [
        'name',
        'scope',
        'category_id',
        'provider_id',
        /*
         * The mobile network a rule is scoped to. Tracked so a change to which
         * network a rule applies to is versioned and diffed like any other
         * financially significant edit — moving a rule from MTN to Airtel silently
         * would re-price every Airtel sale.
         */
        'network',
        'service_product_id',
        'provider_product_id',
        'markup_type',
        'markup_percentage_bps',
        'markup_fixed_minor',
        'minimum_profit_minor',
        'maximum_markup_minor',
        'minimum_selling_price_minor',
        'maximum_selling_price_minor',
        'minimum_margin_bps',
        'customer_fee_enabled',
        'customer_fee_type',
        'customer_fee_minor',
        'customer_fee_bps',
        'discount_bps',
        'discount_fixed_minor',
        'promotion_starts_at',
        'promotion_ends_at',
        'allow_negative_margin',
        'rounding_step_minor',
        'rounding_mode',
        'on_unprofitable',
        'fallback_rule_id',
        'priority',
        'is_active',
    ];

    public function __construct(
        private readonly PricingEngine $engine,
    ) {
    }

    /**
     * Create a rule, writing version 1 of its history.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor, ?string $reason = null): PricingRule
    {
        $attributes = $this->normalise($attributes);

        $this->assertScopeIsConsistent($attributes);
        $this->assertNoFallbackCycle($attributes, null);
        $this->assertWithinDataMarkupBand($attributes, null);
        $this->assertSmmMarkupFloor($attributes);

        return DB::transaction(function () use ($attributes, $actor, $reason) {
            $rule = PricingRule::create($attributes + [
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            /*
             * Version 1 records the state at creation. It is the only version
             * whose snapshot is the state *after* the change it accompanies,
             * because there is no state before it.
             */
            $this->writeVersion(
                $rule,
                version: 1,
                snapshot: $this->snapshot($rule),
                changes: null,
                reason: $reason ?? 'Rule created',
                actor: $actor,
            );

            $this->log($actor, 'pricing_rule_created', $rule, $reason, [
                'after' => $this->snapshot($rule),
            ]);

            return $rule;
        });
    }

    /**
     * Update a rule, recording the state it had before the change.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(PricingRule $rule, array $changes, User $actor, ?string $reason = null): PricingRule
    {
        $changes = $this->normalise($changes);

        return DB::transaction(function () use ($rule, $changes, $actor, $reason) {
            /*
             * Re-read under a lock. Two administrators editing the same rule
             * concurrently would otherwise both diff against the same "before"
             * state and the second write would produce a version row that does
             * not describe the change that actually happened.
             */
            $locked = PricingRule::whereKey($rule->getKey())->lockForUpdate()->firstOrFail();

            /*
             * Captured BEFORE the change is applied. The version row records the
             * state a rule was in, so the history answers "what was the markup
             * when this order was priced" directly rather than by replaying
             * diffs — and a snapshot is what the pricing snapshot on an order
             * points at.
             */
            $before = $this->snapshot($locked);
            $diff = $this->diff($locked, $changes);

            if ($diff === []) {
                // Nothing changed. Writing a version row would add noise to the
                // audit trail and imply a change that did not happen.
                return $locked;
            }

            $merged = $this->merge($locked, $changes);

            $this->assertScopeIsConsistent($merged);
            $this->assertNoFallbackCycle($merged, $locked->getKey());
            $this->assertWithinDataMarkupBand($merged, $locked);
            $this->assertSmmMarkupFloor($merged, $locked);

            $locked->fill($changes);
            $locked->updated_by = $actor->getKey();
            $locked->save();

            $this->writeVersion(
                $locked,
                version: $this->nextVersion($locked),
                snapshot: $before,
                changes: $diff,
                reason: $reason ?? 'Rule updated',
                actor: $actor,
            );

            $this->log($actor, 'pricing_rule_updated', $locked, $reason, [
                'before' => $before,
                'after' => $this->snapshot($locked),
                'changed_fields' => array_keys($diff),
            ]);

            return $locked;
        });
    }

    /**
     * Activate or deactivate a rule.
     *
     * Separate from `update()` so the audit entry says "deactivated" rather than
     * listing a boolean field, which is what an operator scanning the log needs.
     */
    public function setActive(PricingRule $rule, bool $active, User $actor, ?string $reason = null): PricingRule
    {
        return $this->update(
            $rule,
            ['is_active' => $active],
            $actor,
            $reason ?? ($active ? 'Rule activated' : 'Rule deactivated'),
        );
    }

    /**
     * Delete a rule.
     *
     * Refused while another rule falls back to it: deleting it would leave that
     * rule's fallback pointing at nothing, and the failure would only surface at
     * the moment a customer tried to buy something unprofitable.
     */
    public function delete(PricingRule $rule, User $actor, ?string $reason = null): void
    {
        $dependents = PricingRule::where('fallback_rule_id', $rule->getKey())->pluck('name');

        if ($dependents->isNotEmpty()) {
            throw new RuntimeException(
                'This rule is the fallback for: ' . $dependents->implode(', ')
                . '. Change those rules first.'
            );
        }

        DB::transaction(function () use ($rule, $actor, $reason) {
            $this->log($actor, 'pricing_rule_deleted', $rule, $reason, [
                'before' => $this->snapshot($rule),
            ]);

            // The version history cascades with the rule. That is deliberate: a
            // deleted rule's history is not meaningful without its subject, and
            // the AdminLog entry above preserves what it was.
            $rule->delete();
        });
    }

    /* =====================================================================
     | Preview
     | =================================================================== */

    /**
     * Show what a proposed change would do, before it is saved.
     *
     * This is the answer to "if I raise the markup to 25%, what happens?" and it
     * is deliberately computed with the *same engine* that prices real orders, so
     * a preview cannot disagree with the result.
     *
     * @param  array<string, mixed>  $changes  the proposed rule changes
     * @param  int  $sampleCostMinor  a representative provider cost in kobo
     * @return array<string, mixed>
     */
    public function preview(PricingRule $rule, array $changes, int $sampleCostMinor = 100000, int $quantity = 1): array
    {
        $merged = $this->merge($rule, $this->normalise($changes));

        // A detached copy, so nothing here touches the database.
        $proposed = new PricingRule();
        $proposed->forceFill($merged);
        $proposed->setAttribute('id', $rule->getKey());

        $current = $this->engine->quoteWithRule($sampleCostMinor, $quantity, $rule);
        $after = $this->engine->quoteWithRule($sampleCostMinor, $quantity, $proposed);

        return [
            'sample_provider_cost_minor' => $sampleCostMinor,
            'quantity' => $quantity,
            'current' => [
                'price_minor' => $current->customerPriceMinor,
                'profit_minor' => $current->grossProfitMinor,
                'margin_bps' => $current->profitMarginBps,
                'sellable' => $current->isSellable(),
                'formatted_price' => $current->price()->format(),
                'formatted_profit' => $current->grossProfit()->format(),
                'formatted_margin' => $current->grossMarginLabel(),
            ],
            'proposed' => [
                'price_minor' => $after->customerPriceMinor,
                'profit_minor' => $after->grossProfitMinor,
                'margin_bps' => $after->profitMarginBps,
                'sellable' => $after->isSellable(),
                'refusal_reason' => $after->refusalReason,
                'formatted_price' => $after->price()->format(),
                'formatted_profit' => $after->grossProfit()->format(),
                'formatted_margin' => $after->grossMarginLabel(),
            ],
            'delta' => [
                'price_minor' => $after->customerPriceMinor - $current->customerPriceMinor,
                'profit_minor' => $after->grossProfitMinor - $current->grossProfitMinor,
                'margin_bps' => $after->profitMarginBps - $current->profitMarginBps,
            ],
            /*
             * "Significant" is what triggers the confirmation requirement in the
             * UI: a price movement of more than 5%, or a change that would take a
             * product from sellable to not.
             */
            'requires_confirmation' => $this->isSignificantChange($current, $after),
        ];
    }

    private function isSignificantChange(PriceQuote $before, PriceQuote $after): bool
    {
        if ($before->isSellable() !== $after->isSellable()) {
            return true;
        }

        if ($before->customerPriceMinor === 0) {
            return $after->customerPriceMinor !== 0;
        }

        $movement = abs($after->customerPriceMinor - $before->customerPriceMinor);
        $threshold = intdiv($before->customerPriceMinor, 20); // 5%

        return $movement > $threshold;
    }

    /* =====================================================================
     | Bulk operations
     | =================================================================== */

    /**
     * Apply the same change to every rule matching a filter.
     *
     * Used by the "bulk-update categories" control. Each rule is versioned and
     * audited individually, because a bulk edit is still N financial decisions
     * and the history has to show which rule changed to what.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $filter   e.g. ['scope' => 'category', 'category_id' => 3]
     * @return array{updated:int, skipped:int, rules:array<int, string>}
     */
    public function bulkUpdate(array $changes, array $filter, User $actor, ?string $reason = null): array
    {
        $query = PricingRule::query();

        foreach ($filter as $column => $value) {
            if (in_array($column, self::TRACKED_FIELDS, true)) {
                $query->where($column, $value);
            }
        }

        $updated = 0;
        $skipped = 0;
        $names = [];

        foreach ($query->get() as $rule) {
            $result = $this->update($rule, $changes, $actor, $reason ?? 'Bulk pricing update');

            if ($result->wasChanged()) {
                $updated++;
                $names[] = $result->name;
            } else {
                $skipped++;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'rules' => $names];
    }

    /* =====================================================================
     | Validation
     | =================================================================== */

    /**
     * A rule's scope and its foreign keys must agree.
     *
     * A `product` rule with no `service_product_id` would resolve for nothing and
     * sit in the console looking active; a `global` rule with a `provider_id` set
     * would apply far more broadly than the operator intended. Both are silent
     * misconfigurations, so both are refused.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertScopeIsConsistent(array $attributes): void
    {
        $scope = $attributes['scope'] ?? null;

        if (! in_array($scope, PricingRule::SCOPE_ORDER, true)) {
            throw new RuntimeException("Unknown pricing rule scope [{$scope}].");
        }

        $required = match ($scope) {
            PricingRule::SCOPE_CATEGORY => 'category_id',
            PricingRule::SCOPE_PROVIDER => 'provider_id',
            PricingRule::SCOPE_NETWORK => 'network',
            PricingRule::SCOPE_PRODUCT => 'service_product_id',
            PricingRule::SCOPE_PROVIDER_PRODUCT => 'provider_product_id',
            default => null,
        };

        if ($required !== null && empty($attributes[$required])) {
            throw new RuntimeException("A {$scope} pricing rule must name its subject ({$required}).");
        }

        /*
         * `network` is deliberately excluded from the "must not also set" list: a
         * network rule names the provider as well, because the same network can be
         * priced differently through different upstreams.
         */
        foreach (['category_id', 'provider_id', 'service_product_id', 'provider_product_id'] as $key) {
            if ($key !== $required && ! empty($attributes[$key])) {
                throw new RuntimeException(
                    "A {$scope} pricing rule must not also set [{$key}]; the subject would be ambiguous."
                );
            }
        }

        $this->assertNumericFieldsAreIntegers($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertNumericFieldsAreIntegers(array $attributes): void
    {
        foreach ([
            'markup_percentage_bps', 'markup_fixed_minor', 'minimum_profit_minor',
            'maximum_markup_minor', 'minimum_selling_price_minor', 'maximum_selling_price_minor',
            'minimum_margin_bps', 'customer_fee_minor', 'customer_fee_bps',
            'discount_bps', 'discount_fixed_minor', 'rounding_step_minor', 'priority',
        ] as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null) {
                continue;
            }

            if (! is_int($attributes[$field]) && ! is_numeric($attributes[$field])) {
                throw new RuntimeException("Pricing field [{$field}] must be a number.");
            }

            if ((float) $attributes[$field] < 0) {
                throw new RuntimeException("Pricing field [{$field}] cannot be negative.");
            }
        }

        // Sanity bounds that catch a decimal entered where basis points were
        // expected: "20" meaning 20% must be entered as 2000, and 20 bps is a
        // 0.2% markup that nobody intends.
        foreach (['markup_percentage_bps', 'minimum_margin_bps', 'discount_bps', 'customer_fee_bps'] as $field) {
            if (isset($attributes[$field]) && (int) $attributes[$field] > 100000) {
                throw new RuntimeException(
                    "Pricing field [{$field}] exceeds 1000%. Basis points are expected: 20% is 2000."
                );
            }
        }
    }

    /**
     * A data markup must stay inside the approved band.
     *
     * ## Why a band rather than a free number
     *
     * The markup on a data bundle is the platform's entire commercial margin on
     * that bundle, and the approved policy is 2–3% on the verified provider cost.
     * Before this check the console accepted anything up to 1000%, so a mistyped
     * field — `30` where `3` was meant — would multiply every bundle's price by
     * nearly a third and nothing would object. A bound turns a typo into a
     * validation error while leaving the decision of *where* inside the band with
     * the operator, which is what makes the band a policy rather than a constant.
     *
     * ## What counts as a data markup rule
     *
     * A `network`- or `global`-scoped rule with a percentage markup. Airtime rules
     * are `FACE_VALUE` and carry no markup at all, so they are excluded by type
     * and the airtime pricing policy is untouched by this check.
     *
     * ## What it deliberately does not do
     *
     * It does not re-price rules that already exist outside the band, and it does
     * not refuse an edit that leaves the markup alone. Changing a rule's name, its
     * rounding step or its profit floor on a rule whose markup predates the policy
     * must keep working: silently re-pricing live bundles to fit a band introduced
     * afterwards would change what customers are charged without anybody deciding
     * to, which is the opposite of a financial control.
     *
     * @param  array<string, mixed>  $attributes  the merged, prospective state
     * @param  PricingRule|null  $existing  the rule as it stands, when editing
     */
    private function assertWithinDataMarkupBand(array $attributes, ?PricingRule $existing): void
    {
        if (! $this->isDataMarkupRule($attributes)) {
            return;
        }

        $bps = (int) ($attributes['markup_percentage_bps'] ?? 0);

        /*
         * An edit that does not touch the markup is exempt. The band governs
         * changes to the margin, not the existence of a rule that predates it.
         */
        if ($existing !== null && $bps === (int) $existing->markup_percentage_bps) {
            return;
        }

        $min = max(0, (int) config('pricing.data_markup_bps_min', 200));
        $max = max($min, (int) config('pricing.data_markup_bps_max', 300));

        if ($bps >= $min && $bps <= $max) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The data-bundle markup must be between %s%% and %s%% of the verified provider cost. '
            . 'You entered %s%%. Markup is not margin: it applies to the cost, so a %s%% markup '
            . 'on a ₦1,000 bundle charges ₦%s.',
            $this->percent($min),
            $this->percent($max),
            $this->percent($bps),
            $this->percent($max),
            number_format(1000 * (1 + $max / 10000), 2),
        ));
    }

    /**
     * Whether a rule's markup is a data-bundle markup.
     *
     * ## What the band covers, and what it deliberately does not
     *
     * Scope and type are the only signals the schema carries, and together they
     * are enough for the case the policy exists for — but they are **not** enough
     * to claim the band covers every rule that could charge a data price, and it
     * would be dishonest to pretend otherwise. What is covered:
     *
     *   * a `global` or `network` rule with a `percentage` markup — which is what
     *     data bundles are priced by. The seeded default is exactly this shape, so
     *     the rule that prices every data bundle on a fresh install is inside the
     *     band.
     *
     * What is not:
     *
     *   * a `fixed` or `percentage_plus_fixed` markup. Those are deliberate
     *     commercial choices rather than a margin stated as a percentage, and a
     *     2–3% band says nothing about what a ₦50 handling fee ought to be.
     *   * a `FACE_VALUE` rule, which carries no markup at all. Airtime is priced
     *     by these, so the airtime policy is untouched by this check.
     *   * a `product`, `provider` or `provider_product` scoped rule. Nothing in the
     *     console distinguishes a data rule at those scopes from any other
     *     product's, and refusing a legitimate markup there to catch a
     *     hypothetical data rule is a worse trade than the narrow band below.
     *
     * The honest statement of this control is therefore: **the data default is
     * bounded, and any percentage markup at the scope data is priced by is
     * bounded.** A rule deliberately narrowed to one product is an operator's
     * explicit decision about that product.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function isDataMarkupRule(array $attributes): bool
    {
        $type = $attributes['markup_type'] ?? null;

        if (! in_array($type, [
            PricingRule::MARKUP_PERCENTAGE,
            PricingRule::MARKUP_PERCENTAGE_PLUS_FIXED,
        ], true)) {
            return false;
        }

        return in_array($attributes['scope'] ?? null, [
            PricingRule::SCOPE_NETWORK,
            PricingRule::SCOPE_GLOBAL,
        ], true);
    }

    private function percent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * A social-boost markup must meet the mandatory floor.
     *
     * ## Why this is a floor and not a band
     *
     * Data bundles have an approved *band* (2–3%): a price above it is as wrong
     * as one below, because the commercial decision is "charge roughly what the
     * market charges". Social boost is the opposite. The floor exists so the
     * platform never sells engagement at or below what Nitro charges it, and a
     * higher markup is simply a better price — so only the lower bound is a
     * policy, and a ceiling would be a commercial decision rather than a control.
     *
     * ## Why it is enforced here *and* in `SmmPricing`
     *
     * This constrains what an operator may *type*. `SmmPricing` constrains what
     * the platform may *charge*, immediately before the wallet is debited. The
     * second is the control that matters — it catches a rule created by a seeder,
     * a direct write, or a code path that never went through this service — and
     * the first is what keeps the console from being configured into a state that
     * only fails at checkout, in front of a customer.
     *
     * ## What it deliberately does not do
     *
     * It does not re-price rules that already exist below the floor, and it does
     * not fire on an edit that leaves the markup alone. A rule that predates the
     * policy keeps working until somebody edits it, at which point the floor
     * applies — because silently re-pricing live services would change what
     * customers are charged without anybody deciding to.
     *
     * @param  array<string, mixed>  $attributes  the merged, prospective state
     * @param  PricingRule|null  $existing  the rule as it stands, when editing
     */
    private function assertSmmMarkupFloor(array $attributes, ?PricingRule $existing = null): void
    {
        if (($attributes['markup_type'] ?? null) === PricingRule::MARKUP_FACE_VALUE) {
            return;
        }

        /*
         * Only a percentage markup states a proportion of cost, which is what the
         * floor is expressed in. A `none` rule charges cost exactly — refused by
         * `SmmPricing` at the point of sale, and not caught here because a rule
         * with no markup is a deliberate statement about a *different* product
         * family and this check cannot tell which one it will price.
         */
        if (($attributes['markup_type'] ?? null) !== PricingRule::MARKUP_PERCENTAGE
            && ($attributes['markup_type'] ?? null) !== PricingRule::MARKUP_PERCENTAGE_PLUS_FIXED) {
            return;
        }

        if (! $this->isSocialBoostRule($attributes)) {
            return;
        }

        $bps = (int) ($attributes['markup_percentage_bps'] ?? 0);

        if ($existing !== null && $bps === (int) $existing->markup_percentage_bps) {
            return;
        }

        $floor = max(1, (int) config('pricing.smm.minimum_markup_bps', 1000));

        if ($bps >= $floor) {
            return;
        }

        throw new RuntimeException(sprintf(
            'A social-boost markup must be at least %s%% of the verified provider cost; you entered %s%%. '
            . 'Markup is not margin: a %s%% markup on a ₦1,000 boost charges ₦%s and earns ₦%s — a %s%% gross margin.',
            $this->percent($floor),
            $this->percent($bps),
            $this->percent($floor),
            number_format(1000 * (1 + $floor / 10000), 2),
            number_format(1000 * $floor / 10000, 2),
            $this->percent((int) round(($floor / (10000 + $floor)) * 10000)),
        ));
    }

    /**
     * Whether a rule prices social boost.
     *
     * Scope is the signal the schema carries. A social-boost rule is scoped to the
     * service category (`Group: social`), to a product inside it, or to one
     * provider's offering of one — because that is how the catalogue is
     * structured, and it is the only shape in which the console can express
     * "this price is for a boost".
     *
     * A `global` rule is deliberately **not** treated as an SMM rule: it prices
     * every product family, and applying a social-boost floor to it would refuse a
     * perfectly valid global default for airtime and data. The mandatory floor is
     * enforced unconditionally at the point of sale instead, which is where it can
     * be enforced without guessing.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function isSocialBoostRule(array $attributes): bool
    {
        $scopes = [
            PricingRule::SCOPE_CATEGORY => 'category_id',
            PricingRule::SCOPE_PRODUCT => 'service_product_id',
            PricingRule::SCOPE_PROVIDER_PRODUCT => 'provider_product_id',
        ];

        foreach ($scopes as $scope => $column) {
            if (($attributes['scope'] ?? null) !== $scope) {
                continue;
            }

            $id = $attributes[$column] ?? null;

            if ($id === null) {
                return false;
            }

            /*
             * A category-scoped rule names the category directly; a product- or
             * offering-scoped one names a subject whose own category decides. Both
             * resolve to "is this rule about the social category", which is the only
             * question this check can answer without guessing which product family a
             * rule will end up pricing.
             */
            return $this->belongsToSocialCategory($scope, (int) $id);
        }

        return false;
    }

    /**
     * Whether a rule's subject sits in the social category.
     */
    private function belongsToSocialCategory(string $scope, int $id): bool
    {
        $categoryId = match ($scope) {
            PricingRule::SCOPE_CATEGORY => $id,
            PricingRule::SCOPE_PRODUCT => \App\Models\ServiceProduct::whereKey($id)->value('category_id'),
            PricingRule::SCOPE_PROVIDER_PRODUCT => \App\Models\ProviderProduct::whereKey($id)
                ->with('serviceProduct')
                ->first()?->serviceProduct?->category_id,
            default => null,
        };

        if ($categoryId === null) {
            return false;
        }

        return \App\Models\ServiceCategory::where('group', \App\Models\ServiceCategory::GROUP_SOCIAL)
            ->whereKey($categoryId)
            ->exists();
    }

    /**
     * A fallback chain must not loop.
     *
     * `A → B → A` would recurse until the request died, and the customer would
     * see a 500 rather than a price. Checked at write time so the loop cannot
     * exist in the database.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoFallbackCycle(array $attributes, ?int $selfId): void
    {
        $fallbackId = $attributes['fallback_rule_id'] ?? null;

        if ($fallbackId === null) {
            return;
        }

        if ($selfId !== null && (int) $fallbackId === $selfId) {
            throw new RuntimeException('A pricing rule cannot fall back to itself.');
        }

        // Walk the chain, bounded, to detect a cycle that does not include self.
        $seen = [];
        $cursor = (int) $fallbackId;

        while ($cursor !== 0 && ! isset($seen[$cursor])) {
            if ($selfId !== null && $cursor === $selfId) {
                throw new RuntimeException('That fallback would create a loop between pricing rules.');
            }

            $seen[$cursor] = true;

            $next = PricingRule::whereKey($cursor)->value('fallback_rule_id');
            $cursor = $next === null ? 0 : (int) $next;

            if (count($seen) > 20) {
                throw new RuntimeException('The pricing fallback chain is too deep.');
            }
        }
    }

    /* =====================================================================
     | Internals
     | =================================================================== */

    /**
     * Coerce incoming values into the integer representation the columns use.
     *
     * Percentages arriving from a form are human percentages ("20" meaning 20%),
     * so they are converted to basis points here — once, at the boundary — rather
     * than by every caller. A value that already looks like basis points is left
     * alone by the caller using the `_bps` field names directly.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalise(array $attributes): array
    {
        $result = [];

        foreach ($attributes as $key => $value) {
            if (! in_array($key, self::TRACKED_FIELDS, true)) {
                continue;
            }

            if (in_array($key, [
                'markup_percentage_bps', 'markup_fixed_minor', 'minimum_profit_minor',
                'maximum_markup_minor', 'minimum_selling_price_minor', 'maximum_selling_price_minor',
                'minimum_margin_bps', 'customer_fee_minor', 'customer_fee_bps',
                'discount_bps', 'discount_fixed_minor', 'rounding_step_minor', 'priority',
                'category_id', 'provider_id', 'service_product_id', 'provider_product_id',
                'fallback_rule_id',
            ], true)) {
                $result[$key] = $value === null || $value === '' ? null : (int) $value;

                continue;
            }

            if (in_array($key, ['customer_fee_enabled', 'allow_negative_margin', 'is_active'], true)) {
                $result[$key] = (bool) $value;

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * The current state of a rule, for the version snapshot.
     *
     * @return array<string, mixed>
     */
    private function snapshot(PricingRule $rule): array
    {
        $snapshot = [];

        foreach (self::TRACKED_FIELDS as $field) {
            $value = $rule->getAttribute($field);

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(DATE_ATOM);
            }

            $snapshot[$field] = $value;
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, array{from:mixed,to:mixed}>
     */
    private function diff(PricingRule $rule, array $changes): array
    {
        $diff = [];

        foreach ($changes as $field => $newValue) {
            $oldValue = $rule->getAttribute($field);

            if ($oldValue instanceof \DateTimeInterface) {
                $oldValue = $oldValue->format(DATE_ATOM);
            }

            // Loose comparison on purpose: a form posts "2000" where the model
            // holds int 2000, and that is not a change.
            if ($oldValue == $newValue) {
                continue;
            }

            $diff[$field] = ['from' => $oldValue, 'to' => $newValue];
        }

        return $diff;
    }

    /**
     * What the rule would look like after the changes.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function merge(PricingRule $rule, array $changes): array
    {
        return array_merge($this->snapshot($rule), $changes);
    }

    private function nextVersion(PricingRule $rule): int
    {
        return ((int) PricingRuleVersion::where('pricing_rule_id', $rule->getKey())->max('version')) + 1;
    }

    /**
     * Write one version row.
     *
     * `$snapshot` is passed rather than derived, because the two callers need
     * different states: creation records the state it created, and an edit records
     * the state that existed *before* the edit. Deriving it here from the current
     * model would make both record the post-change state, and the history would
     * then answer "what is the markup now" for every version.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $changes
     */
    private function writeVersion(
        PricingRule $rule,
        int $version,
        array $snapshot,
        ?array $changes,
        string $reason,
        User $actor
    ): void {
        PricingRuleVersion::create([
            'pricing_rule_id' => $rule->getKey(),
            'version' => $version,
            'snapshot' => $snapshot,
            'changes' => $changes,
            'reason' => $reason,
            'changed_by' => $actor->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(User $actor, string $action, PricingRule $rule, ?string $reason, array $details): void
    {
        AdminLog::log($actor->getKey(), $action, $details + [
            'pricing_rule_id' => $rule->getKey(),
            'rule_name' => $rule->name,
            'scope' => $rule->scope,
            'subject' => $rule->subjectLabel(),
            'reason' => $reason,
        ]);
    }
}

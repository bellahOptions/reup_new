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
            PricingRule::SCOPE_PRODUCT => 'service_product_id',
            PricingRule::SCOPE_PROVIDER_PRODUCT => 'provider_product_id',
            default => null,
        };

        if ($required !== null && empty($attributes[$required])) {
            throw new RuntimeException("A {$scope} pricing rule must name its subject ({$required}).");
        }

        // The other subject keys must be empty, or the rule is ambiguous.
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

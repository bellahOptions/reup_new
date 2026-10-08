<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable history of a pricing rule.
 *
 * A rule is edited in place, which would otherwise destroy the evidence of what
 * it used to be. Every save writes a version here, so "what was the markup when
 * this order was priced" is answerable from the database rather than from
 * someone's memory of a Slack message.
 *
 * ## Reading the timeline
 *
 *   * **version 1** — the state the rule was created with.
 *   * **version N (N > 1)** — the state the rule had **before** edit N.
 *   * **`changes`** — the fields edit N moved, with both values, so the step from
 *     version N to N+1 is visible without diffing two JSON blobs.
 *
 * So a rule's state *at* version N is `snapshot`, and its current state is the
 * live `pricing_rules` row.
 *
 * The snapshots record the *before* state rather than the after state because a
 * before-snapshot is what an order's pricing snapshot needs to refer to: an order
 * priced under version N was priced by the rule as version N describes it.
 */
class PricingRuleVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'pricing_rule_id',
        'version',
        'snapshot',
        'changes',
        'reason',
        'changed_by',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'changes' => 'array',
        'version' => 'integer',
    ];

    public function pricingRule()
    {
        return $this->belongsTo(PricingRule::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * A human-readable summary of what this version changed, e.g.
     * "markup_percentage_bps: 2000 → 2500".
     *
     * @return array<int, string>
     */
    public function changeSummary(): array
    {
        $labels = [
            'name' => 'Name',
            'markup_type' => 'Markup type',
            'markup_percentage_bps' => 'Markup %',
            'markup_fixed_minor' => 'Fixed markup',
            'minimum_profit_minor' => 'Minimum profit',
            'maximum_markup_minor' => 'Maximum markup',
            'minimum_selling_price_minor' => 'Minimum price',
            'maximum_selling_price_minor' => 'Maximum price',
            'minimum_margin_bps' => 'Minimum margin',
            'customer_fee_enabled' => 'Customer fee',
            'customer_fee_type' => 'Customer fee type',
            'customer_fee_minor' => 'Customer fee amount',
            'customer_fee_bps' => 'Customer fee %',
            'discount_bps' => 'Discount %',
            'discount_fixed_minor' => 'Discount amount',
            'allow_negative_margin' => 'Allow negative margin',
            'rounding_step_minor' => 'Rounding step',
            'rounding_mode' => 'Rounding mode',
            'on_unprofitable' => 'Unprofitable policy',
            'priority' => 'Priority',
            'is_active' => 'Active',
        ];

        $summary = [];

        /*
         * `getAttribute('changes')` rather than `$this->changes`. The cast is a
         * JSON array, and reading it through the magic property returned an empty
         * array here while the accessor returns the decoded value — so the history
         * screen rendered "— → —" for every field. Reading through the accessor is
         * explicit about which representation is wanted and does not depend on
         * magic-property behaviour.
         */
        $changes = $this->getAttribute('changes');

        if (! is_array($changes)) {
            return $summary;
        }

        foreach ($changes as $field => $change) {
            $label = $labels[$field] ?? $field;

            $from = $this->formatValue($field, is_array($change) ? ($change['from'] ?? null) : null);
            $to = $this->formatValue($field, is_array($change) ? ($change['to'] ?? null) : null);

            $summary[] = "{$label}: {$from} → {$to}";
        }

        return $summary;
    }

    /**
     * Render a stored value the way an operator reads it: money as naira,
     * percentages as percentages, booleans as yes/no.
     *
     * @param  mixed  $value
     */
    private function formatValue(string $field, $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (str_ends_with($field, '_minor')) {
            return '₦' . number_format(((int) $value) / 100, 2);
        }

        if (str_ends_with($field, '_bps')) {
            return number_format(((int) $value) / 100, 2) . '%';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }
}

<?php

namespace App\Pricing;

use App\Support\Money;

/**
 * What a provider actually charges ReUp for one purchase, and how sure we are.
 *
 * ## Why the provenance travels with the number
 *
 * A cost is only as good as its source. "₦970, from an invoice reconciliation
 * last Tuesday" and "₦970, because somebody typed 3% into a form once" are the
 * same number and completely different evidence, and the difference decides
 * whether the profit recorded against a sale is *realised* or merely *estimated*.
 *
 * Carrying the source and the verification time next to the amount is what lets
 * `ProfitRecorder` state which one it is instead of quietly presenting an
 * assumption as fact.
 *
 * ## No floating point
 *
 * `costMinor` is integer kobo and `discountBps` is integer basis points, like
 * every other monetary value in this application. A discount applied with a float
 * drifts by a kobo per order and the drift lands in the reported margin.
 */
final class ProviderCost
{
    /** An actual quote or charge for this specific transaction. */
    public const SOURCE_TRANSACTION = 'transaction';

    /** A verified price from the provider's own catalogue. */
    public const SOURCE_CATALOGUE = 'catalogue';

    /** An operator-configured commercial assumption (e.g. an airtime discount). */
    public const SOURCE_CONFIGURED = 'configured';

    /** A cost recorded against a mapped provider product row. */
    public const SOURCE_PROVIDER_PRODUCT = 'provider_product';

    public function __construct(
        /** Effective cost to ReUp, in kobo, after any discount. */
        public readonly int $costMinor,
        /** The face value or list price the discount was applied to, in kobo. */
        public readonly int $listMinor,
        /** The discount applied, in basis points. 0 when the cost is quoted as-is. */
        public readonly int $discountBps,
        /** One of the SOURCE_* constants. */
        public readonly string $source,
        /** When this figure was last verified upstream, if it ever was. */
        public readonly ?string $verifiedAt = null,
        /** True when the figure is an assumption rather than a quote. */
        public readonly bool $estimated = false,
        /** Free-form context for the admin console (product code, network…). */
        public readonly array $context = [],
    ) {
    }

    /** The effective cost as an exact value object. */
    public function money(): Money
    {
        return Money::fromMinor($this->costMinor);
    }

    /** The value the discount was taken off. */
    public function list(): Money
    {
        return Money::fromMinor($this->listMinor);
    }

    /** The discount itself, in kobo. */
    public function discountMinor(): int
    {
        return $this->listMinor - $this->costMinor;
    }

    /**
     * Whether this cost may be treated as confirmed for accounting purposes.
     *
     * Two ways to qualify:
     *
     *   * the figure came from a quote or a provider catalogue, which is evidence
     *     by itself;
     *   * the figure was entered by an operator and then **verified** against a real
     *     document (an invoice, a statement, a provider response) — which is what
     *     `verified_at` records. A provider discount is only ever knowable this way,
     *     since no API publishes it, so excluding configured terms here would mean
     *     airtime profit could never be recognised as realised even after somebody
     *     had checked it.
     *
     * The `estimated` flag remains the operative one for profit reporting: it is
     * false exactly when this method would be true.
     */
    public function isVerified(): bool
    {
        if ($this->estimated) {
            return false;
        }

        if (in_array($this->source, [self::SOURCE_TRANSACTION, self::SOURCE_CATALOGUE], true)) {
            return true;
        }

        return $this->verifiedAt !== null;
    }

    /**
     * The provenance block stored on a pricing snapshot.
     *
     * @return array<string,mixed>
     */
    public function toSnapshotAttributes(): array
    {
        return [
            'cost_source' => $this->source,
            'cost_verified_at' => $this->verifiedAt,
            'cost_is_estimated' => $this->estimated,
        ];
    }

    /**
     * The metadata handed to the pricing engine, which copies it onto the quote.
     *
     * @return array<string,mixed>
     */
    public function toEngineMetadata(): array
    {
        return [
            'source' => $this->source,
            'verified_at' => $this->verifiedAt,
            'estimated' => $this->estimated,
        ];
    }
}

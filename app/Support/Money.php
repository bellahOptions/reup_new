<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * An exact monetary amount, held as an integer number of minor units (kobo).
 *
 * ## Why
 *
 * Every monetary value in this application was a PHP `float`, and floats cannot
 * represent decimal fractions exactly. `0.1 + 0.2 !== 0.3`, and
 * `(int) (1500.50 * 100)` is `150049` on some platforms because the nearest
 * double to 1500.50 is very slightly below it. Two consequences that matter
 * here:
 *
 *   * a wallet balance accumulated over hundreds of float additions drifts away
 *     from the sum of its transactions, so the ledger stops agreeing with the
 *     statement;
 *   * converting naira to the gateway's kobo with `(int) round($x * 100)` is
 *     only correct because of the `round()`. Drop it once, as one code path
 *     had, and the customer is charged a kobo less than they owe.
 *
 * Integer minor units remove the class of bug entirely: ₦1,500.50 is exactly
 * `150050` kobo, addition is exact, and comparison is exact.
 *
 * ## Contract
 *
 *   * construction is exact — `Money::fromNaira('0.01')` is 1 kobo;
 *   * a decimal string with more precision than the currency has is **rejected**
 *     rather than silently rounded, because silently rounding money is how a
 *     one-kobo discrepancy becomes an unexplained balance;
 *   * `Money` is immutable; every operation returns a new instance;
 *   * `toDecimalString()` produces the exact `decimal(15,2)` representation the
 *     existing schema stores, so nothing about the database has to change.
 *
 * The currency is fixed to NGN. Introducing a second currency is a schema
 * change (amounts would need a currency column), not something this class
 * should hide behind a default.
 */
final class Money
{
    /** Minor units in one major unit. NGN has 100 kobo to the naira. */
    public const MINOR_UNITS = 100;

    public const CURRENCY = 'NGN';

    private function __construct(private readonly int $minor)
    {
    }

    /* =====================================================================
     | Construction
     |=================================================================== */

    /** A zero amount. */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * From an integer number of kobo.
     */
    public static function fromMinor(int $minor): self
    {
        return new self($minor);
    }

    /**
     * From a decimal string or number of naira.
     *
     * Accepts `int`, `float` and numeric `string`. A float is accepted only
     * because legacy call sites and JSON payloads produce them; it is converted
     * through a fixed-precision string first so that no binary rounding error
     * survives the call.
     *
     * @throws InvalidArgumentException when the value is not a valid amount
     */
    public static function fromNaira($amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }

        if (is_int($amount)) {
            return new self($amount * self::MINOR_UNITS);
        }

        if (is_float($amount)) {
            if (! is_finite($amount)) {
                throw new InvalidArgumentException('Money cannot be NaN or infinite.');
            }

            // number_format with explicit precision, rather than string casting:
            // PHP renders a float at `precision` significant digits by default,
            // which turns a large amount into scientific notation.
            $amount = number_format($amount, 2, '.', '');
        }

        if (! is_string($amount)) {
            throw new InvalidArgumentException('Money must be an int, float or numeric string.');
        }

        return self::fromDecimalString($amount);
    }

    /**
     * Parse an exact decimal string such as "1500.50" or "-12.3".
     *
     * Rejects anything with more than two decimal places: `1.005` is not a
     * representable naira amount, and rounding it silently would hide the
     * caller's error.
     */
    public static function fromDecimalString(string $value): self
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Money cannot be empty.');
        }

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            if (preg_match('/^(-?\d+)\.(\d{3,})$/', $value)) {
                throw new InvalidArgumentException(
                    "Money [{$value}] has more precision than a kobo; round it explicitly before use."
                );
            }

            throw new InvalidArgumentException("Money [{$value}] is not a valid amount.");
        }

        $negative = $matches[1] === '-';
        $major = ltrim($matches[2], '0');
        $major = $major === '' ? '0' : $major;
        $fraction = str_pad($matches[3] ?? '', 2, '0');

        // Guard against an amount that cannot survive the decimal(15,2) columns
        // this application stores. 13 major digits is the schema's ceiling.
        if (strlen($major) > 13) {
            throw new InvalidArgumentException("Money [{$value}] exceeds the supported magnitude.");
        }

        $minor = ((int) $major) * self::MINOR_UNITS + (int) $fraction;

        return new self($negative ? -$minor : $minor);
    }

    /**
     * From what the database returns for a `decimal(15,2)` column.
     *
     * MySQL hands back a numeric string, so this is exact. Null is treated as
     * zero, which matches the nullable columns it is used for
     * (`transactions.balance_before` on rows written before the wallet service
     * existed).
     *
     * @param  mixed  $value
     */
    public static function fromDatabase($value): self
    {
        if ($value === null || $value === '') {
            return self::zero();
        }

        return self::fromNaira($value);
    }

    /**
     * Sum a list of amounts, exactly.
     *
     * @param  iterable<mixed>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += self::fromNaira($amount)->minor();
        }

        return new self($total);
    }

    /* =====================================================================
     | Accessors
     |=================================================================== */

    /** The exact number of minor units (kobo). */
    public function minor(): int
    {
        return $this->minor;
    }

    /**
     * The naira value as a float, for display and legacy call sites only.
     *
     * Never use this for arithmetic or for building a gateway payload: use
     * `minor()` for both.
     */
    public function toFloat(): float
    {
        return $this->minor / self::MINOR_UNITS;
    }

    /**
     * The exact `decimal(15,2)` representation, e.g. "1500.50".
     *
     * This is what gets written to the existing decimal columns, so no schema
     * change is required to store exact values.
     */
    public function toDecimalString(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = abs($this->minor);

        return $sign . intdiv($absolute, self::MINOR_UNITS)
            . '.'
            . str_pad((string) ($absolute % self::MINOR_UNITS), 2, '0', STR_PAD_LEFT);
    }

    /** The amount formatted for a customer, e.g. "₦1,500.50". */
    public function format(bool $withSymbol = true): string
    {
        $formatted = number_format($this->toFloat(), 2);

        return $withSymbol ? '₦' . $formatted : $formatted;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    /* =====================================================================
     | Arithmetic
     |=================================================================== */

    public function plus(self|int|float|string $other): self
    {
        return new self($this->minor + self::fromNaira($other)->minor);
    }

    public function minus(self|int|float|string $other): self
    {
        return new self($this->minor - self::fromNaira($other)->minor);
    }

    public function absolute(): self
    {
        return new self(abs($this->minor));
    }

    public function negated(): self
    {
        return new self(-$this->minor);
    }

    /**
     * Multiply by a plain integer quantity (units of a product, months of a
     * plan). Deliberately not `float $factor`: a fractional multiplier on money
     * is either a fee calculation — which belongs in a named method with an
     * explicit rounding rule — or a mistake.
     */
    public function multipliedBy(int $quantity): self
    {
        return new self($this->minor * $quantity);
    }

    /**
     * Apply a percentage, rounding half-up to the nearest kobo.
     *
     * Rounding is explicit here because it is unavoidable: 1.5% of ₦333.33 is
     * ₦5.00 (499.995 kobo). Half-up is the convention the Nigerian payment
     * gateways use and what the previous `round()` calls did, so switching to
     * integer arithmetic does not change a single historical fee.
     *
     * The arithmetic is exact integer arithmetic throughout. The percentage is
     * parsed into its own minor units (so `1.5` becomes 150, meaning 150/10000),
     * and the division happens once, at the end, on integers:
     *
     *     result_kobo = round(minor_kobo * percent_minor / (100 * 100))
     *
     * Written this way rather than as `minor * percent / 100` because that form
     * mixes a minor unit with a percentage and is off by a factor of 100 —
     * exactly the bug a test caught here.
     */
    public function percentage(string|int|float $percent): self
    {
        // `1.5` -> 150, `2` -> 200, `0.25` -> 25.
        $percentMinor = self::fromNaira($percent)->minor();

        $divisor = 100 * self::MINOR_UNITS; // 10,000
        $product = $this->minor * $percentMinor;
        $quotient = intdiv($product, $divisor);
        $remainder = $product % $divisor;

        if (abs($remainder) * 2 >= $divisor) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return new self($quotient);
    }

    /** The smaller of two amounts. */
    public function min(self $other): self
    {
        return $this->minor <= $other->minor ? $this : $other;
    }

    /** The larger of two amounts. */
    public function max(self $other): self
    {
        return $this->minor >= $other->minor ? $this : $other;
    }

    /* =====================================================================
     | Comparison
     |=================================================================== */

    public function equals(self|int|float|string $other): bool
    {
        return $this->minor === self::fromNaira($other)->minor;
    }

    public function greaterThan(self|int|float|string $other): bool
    {
        return $this->minor > self::fromNaira($other)->minor;
    }

    public function greaterThanOrEqual(self|int|float|string $other): bool
    {
        return $this->minor >= self::fromNaira($other)->minor;
    }

    public function lessThan(self|int|float|string $other): bool
    {
        return $this->minor < self::fromNaira($other)->minor;
    }

    public function lessThanOrEqual(self|int|float|string $other): bool
    {
        return $this->minor <= self::fromNaira($other)->minor;
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    /**
     * @return array{minor:int,decimal:string,currency:string}
     */
    public function toArray(): array
    {
        return [
            'minor' => $this->minor,
            'decimal' => $this->toDecimalString(),
            'currency' => self::CURRENCY,
        ];
    }
}

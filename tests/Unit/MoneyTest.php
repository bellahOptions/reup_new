<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `Money` is the foundation of every financial calculation in the application,
 * so these tests are about exactness rather than features.
 *
 * The cases that matter are the ones floating point gets wrong: a single kobo,
 * a value that is not representable in binary, and repeated accumulation of a
 * small amount until the drift would become visible in a balance.
 */
class MoneyTest extends TestCase
{
    /* =====================================================================
     | Exact parsing
     |=================================================================== */

    public function test_a_single_kobo_is_representable(): void
    {
        $money = Money::fromNaira('0.01');

        $this->assertSame(1, $money->minor());
        $this->assertSame('0.01', $money->toDecimalString());
    }

    public function test_a_whole_amount_carries_no_fraction(): void
    {
        $money = Money::fromNaira('1500');

        $this->assertSame(150000, $money->minor());
        $this->assertSame('1500.00', $money->toDecimalString());
    }

    public function test_a_decimal_amount_is_exact(): void
    {
        $money = Money::fromNaira('1500.50');

        $this->assertSame(150050, $money->minor());
        $this->assertSame('1500.50', $money->toDecimalString());
    }

    public function test_a_fractional_float_does_not_lose_a_kobo(): void
    {
        /*
         * The bug this class exists to prevent. 1500.50 has no exact binary
         * representation, so `(int) (1500.50 * 100)` is 150049 on some
         * platforms. Parsing through a fixed-precision string must give 150050.
         */
        $money = Money::fromNaira(1500.50);

        $this->assertSame(150050, $money->minor());
        $this->assertSame('1500.50', $money->toDecimalString());
    }

    public function test_a_float_with_binary_noise_parses_to_the_intended_amount(): void
    {
        // 0.1 + 0.2 === 0.30000000000000004 in IEEE-754.
        $noisy = 0.1 + 0.2;

        $this->assertSame(30, Money::fromNaira($noisy)->minor());
        $this->assertSame('0.30', Money::fromNaira($noisy)->toDecimalString());
    }

    public function test_database_values_round_trip(): void
    {
        $this->assertSame('0.00', Money::fromDatabase(null)->toDecimalString());
        $this->assertSame('0.00', Money::fromDatabase('')->toDecimalString());
        $this->assertSame('999999999.99', Money::fromDatabase('999999999.99')->toDecimalString());
        $this->assertSame('5.00', Money::fromDatabase('5.00')->toDecimalString());
        $this->assertSame('5.00', Money::fromDatabase('5')->toDecimalString());
    }

    /* =====================================================================
     | Refusing to guess
     |=================================================================== */

    public function test_more_precision_than_a_kobo_is_rejected_rather_than_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more precision than a kobo');

        Money::fromNaira('1.005');
    }

    public function test_a_non_numeric_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromNaira('one thousand');
    }

    public function test_an_empty_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromNaira('  ');
    }

    public function test_an_amount_beyond_the_schema_magnitude_is_rejected(): void
    {
        // decimal(15,2) holds 13 major digits.
        $this->expectException(InvalidArgumentException::class);

        Money::fromNaira('12345678901234.00');
    }

    /* =====================================================================
     | Arithmetic
     |=================================================================== */

    public function test_addition_of_small_amounts_does_not_drift(): void
    {
        // Ten thousand additions of one kobo is exactly one hundred naira.
        $total = Money::zero();

        for ($i = 0; $i < 10000; $i++) {
            $total = $total->plus('0.01');
        }

        $this->assertSame('100.00', $total->toDecimalString());
        $this->assertSame(10000, $total->minor());
    }

    public function test_subtraction_is_exact(): void
    {
        $this->assertSame('0.00', Money::fromNaira('0.01')->minus('0.01')->toDecimalString());
        $this->assertSame('1499.99', Money::fromNaira('1500.00')->minus('0.01')->toDecimalString());
    }

    public function test_a_balance_can_be_driven_negative_and_is_reported_as_such(): void
    {
        $result = Money::fromNaira('5.00')->minus('5.01');

        $this->assertTrue($result->isNegative());
        $this->assertSame('-0.01', $result->toDecimalString());
    }

    public function test_percentage_rounds_half_up_to_the_nearest_kobo(): void
    {
        // 1.5% of 333.33 is 4.99995 naira = 499.995 kobo -> 500 kobo.
        $this->assertSame('5.00', Money::fromNaira('333.33')->percentage(1.5)->toDecimalString());

        // 1.5% of 100.00 is exactly 1.50.
        $this->assertSame('1.50', Money::fromNaira('100.00')->percentage(1.5)->toDecimalString());

        // 1.5% of 1.00 is 0.015 naira = 1.5 kobo -> 2 kobo.
        $this->assertSame('0.02', Money::fromNaira('1.00')->percentage(1.5)->toDecimalString());

        // A whole 2% of a round amount stays round.
        $this->assertSame('30.00', Money::fromNaira('1500.00')->percentage(2)->toDecimalString());
    }

    public function test_multiplication_is_exact(): void
    {
        // Three WAEC pins at 3,500.50 each.
        $this->assertSame('10501.50', Money::fromNaira('3500.50')->multipliedBy(3)->toDecimalString());
    }

    public function test_a_sum_of_mixed_amounts_is_exact(): void
    {
        $total = Money::sum(['0.01', '0.02', 1500.50, '999999.99']);

        $this->assertSame('1001500.52', $total->toDecimalString());
    }

    public function test_min_and_max_choose_the_boundary(): void
    {
        $cap = Money::fromNaira('2000');

        $this->assertSame('2000.00', Money::fromNaira('5000')->min($cap)->toDecimalString());
        $this->assertSame('500.00', Money::fromNaira('500')->min($cap)->toDecimalString());
        $this->assertSame('0.00', Money::fromNaira('-10')->max(Money::zero())->toDecimalString());
    }

    /* =====================================================================
     | Comparison
     |=================================================================== */

    public function test_comparison_is_exact_at_the_kobo_boundary(): void
    {
        $this->assertTrue(Money::fromNaira('0.01')->isPositive());
        $this->assertFalse(Money::fromNaira('0.00')->isPositive());
        $this->assertTrue(Money::fromNaira('0.00')->isZero());

        // The comparison a spend limit depends on.
        $this->assertTrue(Money::fromNaira('499999.99')->lessThan(Money::fromNaira('500000.00')));
        $this->assertFalse(Money::fromNaira('500000.01')->lessThanOrEqual(Money::fromNaira('500000.00')));
        $this->assertTrue(Money::fromNaira('1500.50')->equals('1500.50'));
    }

    public function test_formatting_is_for_display_only(): void
    {
        $this->assertSame('₦1,500.50', Money::fromNaira('1500.50')->format());
        $this->assertSame('1,500.50', Money::fromNaira('1500.50')->format(false));
        $this->assertSame('₦0.01', Money::fromNaira('0.01')->format());
    }

    /* =====================================================================
     | Gateway payloads
     |=================================================================== */

    public function test_minor_units_are_what_a_gateway_receives(): void
    {
        /*
         * Paystack takes kobo as an integer. This is the value that used to be
         * computed as `(int) round($amount * 100)` — correct only because of the
         * `round()`, and wrong by a kobo in the one place the `round()` was
         * missing.
         */
        $this->assertSame(150050, Money::fromNaira('1500.50')->minor());
        $this->assertSame(1, Money::fromNaira('0.01')->minor());
        $this->assertSame(150000, Money::fromNaira('1500.00')->minor());
        $this->assertSame(0, Money::zero()->minor());
    }

    public function test_a_gateway_amount_in_minor_units_round_trips(): void
    {
        $this->assertSame('1500.50', Money::fromMinor(150050)->toDecimalString());
        $this->assertSame('0.01', Money::fromMinor(1)->toDecimalString());
    }
}

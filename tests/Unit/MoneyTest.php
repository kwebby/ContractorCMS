<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Unit;

use App\Finance\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_decimal_conversion_never_uses_binary_floating_point(): void
    {
        $this->assertSame(101, Money::decimal('1.01')->minor);
        $this->assertSame('-0.09', Money::decimal('-0.09', 'CAD')->format());
        $this->assertSame(250, Money::decimal('2.5')->minor);
        $this->assertSame(1234, Money::quantity('1.234'));
        $this->assertSame(Money::MAX_MINOR, Money::decimal((new Money(Money::MAX_MINOR))->format())->minor);
    }

    public function test_fractional_minor_units_round_half_up(): void
    {
        $this->assertSame(13, Money::roundRatio(125, 10));
        $this->assertSame(12, Money::roundRatio(124, 10));
        $this->assertSame(0, Money::roundRatio(4, 10));
    }

    public function test_excess_decimal_precision_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::decimal('0.009');
    }

    public function test_currency_mixing_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(100, 'CAD'))->add(new Money(100, 'USD'));
    }

    public function test_unbounded_quantities_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::quantity('10000.001');
    }
}

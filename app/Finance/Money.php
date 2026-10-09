<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Finance;

class Money
{
    public const MAX_MINOR = 1000000000000;

    public function __construct(public readonly int $minor, public readonly string $currency = 'USD')
    {
        if (! in_array($currency, ['USD', 'CAD'], true) || abs($minor) > self::MAX_MINOR) {
            throw new \InvalidArgumentException('Use USD or CAD and an amount within the supported limit.');
        }
    }

    public static function decimal(string $amount, string $currency = 'USD'): self
    {
        if (! preg_match('/^(-?)(\d{1,11})(?:\.(\d{1,2}))?$/D', $amount, $matches)) {
            throw new \InvalidArgumentException('Amounts must be decimal strings with at most two decimal places.');
        }

        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return new self(($matches[1] === '-' ? -1 : 1) * $minor, $currency);
    }

    public static function quantity(string $quantity): int
    {
        if (! preg_match('/^(\d{1,5})(?:\.(\d{1,3}))?$/D', $quantity, $matches)) {
            throw new \InvalidArgumentException('Quantity must be a positive decimal with at most three decimal places.');
        }
        $milli = ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
        if ($milli <= 0 || $milli > 10000000) {
            throw new \InvalidArgumentException('Quantity must be between 0.001 and 10000.');
        }

        return $milli;
    }

    public static function roundRatio(int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $numerator < 0) {
            throw new \InvalidArgumentException('Only nonnegative ratios are supported.');
        }

        return intdiv($numerator, $denominator) + ((($numerator % $denominator) * 2 >= $denominator) ? 1 : 0);
    }

    public function format(): string
    {
        return ($this->minor < 0 ? '-' : '').intdiv(abs($this->minor), 100).'.'.str_pad((string) (abs($this->minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot add different currencies.');
        }

        return new self($this->minor + $other->minor, $this->currency);
    }
}

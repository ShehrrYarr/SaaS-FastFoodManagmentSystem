<?php

namespace App\Support;

/**
 * Exact 2-decimal money arithmetic on strings (bcmath), so balances never
 * drift the way floats do.
 */
final class Money
{
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        $value = is_string($value) ? trim(str_replace(',', '', $value)) : $value;

        return number_format((float) $value, 2, '.', '');
    }

    public static function add(string|int|float $a, string|int|float $b): string
    {
        return bcadd(self::of($a), self::of($b), 2);
    }

    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return bcsub(self::of($a), self::of($b), 2);
    }

    public static function mul(string|int|float $a, string|int|float $b): string
    {
        return bcmul(self::of($a), (string) $b, 2);
    }

    /** @param iterable<string|int|float|null> $values */
    public static function sum(iterable $values): string
    {
        $total = '0.00';

        foreach ($values as $value) {
            $total = self::add($total, self::of($value));
        }

        return $total;
    }

    /** $percent % of $amount, rounded half-up to 2 decimals: percentOf(1250, 10) = "125.00". */
    public static function percentOf(string|int|float $amount, string|int|float $percent): string
    {
        $exact = bcdiv(bcmul(self::of($amount), (string) $percent, 6), '100', 6);

        return self::round($exact);
    }

    /** Half-up rounding of a high-precision decimal string. */
    public static function round(string $value): string
    {
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd($value, $offset, 2);
    }

    public static function compare(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::of($a), self::of($b), 2);
    }

    public static function min(string|int|float $a, string|int|float $b): string
    {
        return self::compare($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    public static function max(string|int|float $a, string|int|float $b): string
    {
        return self::compare($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    public static function isZero(string|int|float $value): bool
    {
        return bccomp(self::of($value), '0.00', 2) === 0;
    }

    public static function isPositive(string|int|float $value): bool
    {
        return bccomp(self::of($value), '0.00', 2) === 1;
    }

    public static function isNegative(string|int|float $value): bool
    {
        return bccomp(self::of($value), '0.00', 2) === -1;
    }

    /** A discount of $value ("percent" or "fixed") on $base, never more than $base. */
    public static function discount(string|int|float $base, ?string $type, string|int|float|null $value): string
    {
        if (! $type || ! self::isPositive($value ?? 0) || ! self::isPositive($base)) {
            return '0.00';
        }

        $amount = $type === 'percent'
            ? self::percentOf($base, min((float) $value, 100))
            : self::of($value);

        return self::min($amount, $base);
    }
}

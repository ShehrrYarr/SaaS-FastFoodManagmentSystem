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

    public static function isZero(string|int|float $value): bool
    {
        return bccomp(self::of($value), '0.00', 2) === 0;
    }
}

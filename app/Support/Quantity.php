<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact 4-decimal stock quantities (bcmath) and unit conversion.
 *
 * Units convert only within their family:
 *   mass   kg = 1000 g
 *   volume L  = 1000 ml
 *   count  dozen = 12 pcs, pair = 2 pcs
 */
final class Quantity
{
    /** unit => [family, size in the family's smallest unit, label] */
    public const UNITS = [
        'kg' => ['mass', '1000', 'Kilogram'],
        'g' => ['mass', '1', 'Gram'],
        'L' => ['volume', '1000', 'Litre'],
        'ml' => ['volume', '1', 'Millilitre'],
        'pcs' => ['count', '1', 'Pieces'],
        'pair' => ['count', '2', 'Pair'],
        'dozen' => ['count', '12', 'Dozen'],
    ];

    public const SCALE = 4;

    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        $value = is_string($value) ? trim(str_replace(',', '', $value)) : $value;

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("[{$value}] is not a quantity.");
        }

        return self::round(is_string($value) ? $value : number_format((float) $value, 8, '.', ''));
    }

    public static function add(string|int|float $a, string|int|float $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string|int|float $a, string|int|float $b): string
    {
        return self::round(bcmul(self::of($a), (string) $b, 8));
    }

    public static function compare(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isPositive(string|int|float $value): bool
    {
        return self::compare($value, 0) === 1;
    }

    public static function isNegative(string|int|float $value): bool
    {
        return self::compare($value, 0) === -1;
    }

    public static function isZero(string|int|float $value): bool
    {
        return self::compare($value, 0) === 0;
    }

    /** The quantity, or zero if it is negative. */
    public static function max0(string|int|float $value): string
    {
        return self::isNegative($value) ? '0.0000' : self::of($value);
    }

    /** Half-up rounding to 4 decimals of a decimal string with any precision. */
    public static function round(string $value): string
    {
        if (! str_contains($value, '.') || strlen(substr($value, strpos($value, '.') + 1)) <= self::SCALE) {
            return bcadd($value, '0', self::SCALE);
        }

        $offset = str_starts_with($value, '-') ? '-0.00005' : '0.00005';

        return bcadd($value, $offset, self::SCALE);
    }

    // ---------------------------------------------------------------- units

    public static function isUnit(string $unit): bool
    {
        return isset(self::UNITS[$unit]);
    }

    public static function family(string $unit): string
    {
        return self::UNITS[$unit][0] ?? throw new InvalidArgumentException("Unknown unit [{$unit}].");
    }

    /** Units that convert to and from $unit ("kg" -> kg, g). @return list<string> */
    public static function compatible(string $unit): array
    {
        $family = self::family($unit);

        return array_keys(array_filter(self::UNITS, fn ($u) => $u[0] === $family));
    }

    /** 200 g -> kg = "0.2000" */
    public static function convert(string|int|float $quantity, string $from, string $to): string
    {
        if (self::family($from) !== self::family($to)) {
            throw new InvalidArgumentException("Cannot convert {$from} to {$to}.");
        }

        $smallest = bcmul(self::of($quantity), self::UNITS[$from][1], 8);

        return self::round(bcdiv($smallest, self::UNITS[$to][1], 8));
    }

    /** "0.2 kg", "1,250 g", "12 pcs" */
    public static function format(string|int|float|null $quantity, ?string $unit = null): string
    {
        $value = self::of($quantity);
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = explode('.', ltrim($value, '-'));
        $fraction = rtrim($fraction, '0');

        $text = ($negative ? '−' : '').number_format((float) $whole).($fraction !== '' ? '.'.$fraction : '');

        return $unit ? $text.' '.$unit : $text;
    }

    /** "0.2" (no grouping), for form fields. */
    public static function plain(string|int|float|null $quantity): string
    {
        $value = self::of($quantity);

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}

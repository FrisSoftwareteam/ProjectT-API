<?php

namespace App\Services\CompanyDataRelease;

use InvalidArgumentException;

class FixedScaleDecimal
{
    public const SCALE = 6;

    public static function normalize(string|int|float $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/\A(\d+)(?:\.(\d{0,6}))?\z/', $value, $matches)) {
            throw new InvalidArgumentException("Invalid non-negative six-decimal value: {$value}");
        }

        $integer = ltrim($matches[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad($matches[2] ?? '', self::SCALE, '0');

        return $integer.'.'.$fraction;
    }

    public static function add(string $left, string $right): string
    {
        $leftDigits = str_replace('.', '', self::normalize($left));
        $rightDigits = str_replace('.', '', self::normalize($right));
        $sum = self::addUnsigned($leftDigits, $rightDigits);
        $sum = str_pad($sum, self::SCALE + 1, '0', STR_PAD_LEFT);

        return self::normalize(substr($sum, 0, -self::SCALE).'.'.substr($sum, -self::SCALE));
    }

    public static function equals(string $left, string $right): bool
    {
        return self::normalize($left) === self::normalize($right);
    }

    public static function isPositive(string $value): bool
    {
        return self::normalize($value) !== '0.000000';
    }

    private static function addUnsigned(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $result = '';

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry;
            if ($leftIndex >= 0) {
                $sum += (int) $left[$leftIndex--];
            }
            if ($rightIndex >= 0) {
                $sum += (int) $right[$rightIndex--];
            }
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }
}

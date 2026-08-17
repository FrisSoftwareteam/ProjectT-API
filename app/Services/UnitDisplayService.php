<?php

namespace App\Services;

use App\Models\Register;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class UnitDisplayService
{
    /**
     * Format a unit value using the precision configured for its register.
     *
     * This is intended for server-rendered documents. JSON APIs should continue
     * returning raw decimal strings alongside the register's unit_precision data.
     */
    public function format(string|int|float|null $value, Register $register, bool $groupThousands = true): string
    {
        $scale = $register->getMaxDecimalPlaces();
        $decimal = BigDecimal::of((string) ($value ?? 0))
            ->toScale($scale, RoundingMode::HALF_UP)
            ->__toString();

        if (! $groupThousands) {
            return $decimal;
        }

        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, null);
        $sign = str_starts_with($integer, '-') ? '-' : '';
        $digits = ltrim($integer, '-');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $digits) ?? $digits;

        return $sign.$grouped.($fraction !== null ? '.'.$fraction : '');
    }
}

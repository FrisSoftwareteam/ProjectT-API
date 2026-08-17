<?php

namespace Tests\Unit;

use App\Models\Register;
use App\Services\UnitDisplayService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnitDisplayServiceTest extends TestCase
{
    #[DataProvider('formats')]
    public function test_it_formats_units_using_register_precision(
        string $type,
        ?int $places,
        string $value,
        string $expected
    ): void {
        $register = new Register([
            'unit_precision_type' => $type,
            'decimal_precision' => $places,
        ]);

        $this->assertSame($expected, (new UnitDisplayService)->format($value, $register));
    }

    public static function formats(): array
    {
        return [
            'whole number' => ['whole_number', null, '1234567.000000', '1,234,567'],
            'two decimals' => ['decimal', 2, '1234567.5', '1,234,567.50'],
            'four decimals' => ['decimal', 4, '1234567.5', '1,234,567.5000'],
            'round half up' => ['decimal', 2, '12.345', '12.35'],
            'negative value' => ['decimal', 2, '-1234.5', '-1,234.50'],
        ];
    }

    public function test_register_exposes_stable_precision_metadata(): void
    {
        $register = new Register([
            'unit_precision_type' => 'decimal',
            'decimal_precision' => 4,
        ]);

        $this->assertSame([
            'type' => 'decimal',
            'decimal_places' => 4,
        ], $register->unit_precision);
    }
}

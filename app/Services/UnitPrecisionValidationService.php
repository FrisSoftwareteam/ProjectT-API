<?php

namespace App\Services;

use App\Models\Register;
use App\Models\ShareClass;
use Illuminate\Validation\ValidationException;

class UnitPrecisionValidationService
{
    /**
     * Validate a value against a register's precision config, by register ID.
     *
     * @param string|int|float $value
     */
    public function assertValidForRegister(int $registerId, $value, string $field = 'quantity'): void
    {
        $register = Register::findOrFail($registerId);
        $this->assertValid($register, $value, $field);
    }

    /**
     * Validate a value against the precision configured on the register
     * that owns the given share class.
     *
     * @param string|int|float $value
     */
    public function assertValidForShareClass(int $shareClassId, $value, string $field = 'quantity'): void
    {
        $shareClass = ShareClass::with('register')->findOrFail($shareClassId);
        $this->assertValid($shareClass->register, $value, $field);
    }

    /**
     * @param string|int|float $value
     */
    public function assertValid(Register $register, $value, string $field = 'quantity'): void
    {
        if ($register->validateUnitPrecision($value)) {
            return;
        }

        $message = $register->isWholeNumberOnly()
            ? 'This instrument type only accepts whole number values.'
            : sprintf(
                'This instrument type accepts a maximum of %d decimal place(s).',
                $register->getMaxDecimalPlaces()
            );

        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }
}

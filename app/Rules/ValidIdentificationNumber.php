<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an identification number against the format expected for the
 * selected ID type (e.g. NIN, Driver's Licence, Passport, CAC Certificate).
 */
class ValidIdentificationNumber implements ValidationRule
{
    /**
     * Format patterns per ID type. Types not listed here (e.g. bvn, other)
     * are not format-restricted.
     */
    protected const PATTERNS = [
        'nin' => '/^\d{11}$/',
        'drivers_license' => '/^[A-Za-z0-9]{12}$/',
        'passport' => '/^[A-Za-z]\d{8}$/',
        'cac_cert' => '/^(RC|BN|IT|LP)?\d{5,8}$/i',
    ];

    protected const DESCRIPTIONS = [
        'nin' => 'exactly 11 digits',
        'drivers_license' => "exactly 12 alphanumeric characters",
        'passport' => '1 letter followed by 8 digits (9 characters)',
        'cac_cert' => '5-8 digits, with an optional RC, BN, IT, or LP prefix',
    ];

    public function __construct(protected ?string $idType)
    {
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $idType = $this->idType;

        if ($idType === null || ! isset(self::PATTERNS[$idType])) {
            return;
        }

        if (! is_string($value) || ! preg_match(self::PATTERNS[$idType], $value)) {
            $fail("The :attribute format is invalid for the selected ID type. It must be ".self::DESCRIPTIONS[$idType].'.');
        }
    }
}

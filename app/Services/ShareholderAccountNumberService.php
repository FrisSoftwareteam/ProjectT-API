<?php

namespace App\Services;

use App\Models\ShareHolder;

class ShareholderAccountNumberService
{
    /**
     * Generate a unique shareholder account number.
     */
    public function generate(): string
    {
        do {
            $accountNumber = $this->buildAccountNumber();
        } while ($this->accountNumberExists($accountNumber));

        return $accountNumber;
    }

    /**
     * Build the base structure for the account number.
     */
    protected function buildAccountNumber(): string
    {
        return str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }

    /**
     * Check if the generated account number already exists.
     */
    protected function accountNumberExists(string $accountNumber): bool
    {
        return ShareHolder::where('account_no', $accountNumber)->exists();
    }
}



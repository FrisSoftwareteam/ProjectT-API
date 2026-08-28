<?php

namespace App\Services;

use App\Models\ShareholderChangeRequest;

class ShareholderChangeRequestReferenceService
{
    /**
     * Generate a unique control number for a shareholder change request.
     */
    public function generate(): string
    {
        do {
            $controlNo = $this->buildControlNo();
        } while ($this->controlNoExists($controlNo));

        return $controlNo;
    }

    protected function buildControlNo(): string
    {
        return 'CR-'.now()->format('Ymd').'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected function controlNoExists(string $controlNo): bool
    {
        return ShareholderChangeRequest::where('control_no', $controlNo)->exists();
    }
}

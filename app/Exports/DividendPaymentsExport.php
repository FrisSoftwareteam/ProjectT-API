<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class DividendPaymentsExport implements FromCollection, WithHeadings, WithMapping
{
    private Collection $entitlements;
    private int $corporateActionId;

    public function __construct(Collection $entitlements, int $corporateActionId)
    {
        $this->entitlements = $entitlements;
        $this->corporateActionId = $corporateActionId;
    }

    public function collection(): Collection
    {
        return $this->entitlements;
    }

    public function headings(): array
    {
        return [
            'Register Account ID',
            'Shareholder Name',
            'Bank Details',
            'Net Amount',
            'Tax Amount',
            'Share Class',
            'Corporate Action ID',
        ];
    }

    public function map($entitlement): array
    {
        $account = $entitlement->registerAccount;
        $shareholder = $account ? $account->shareholder : null;
        $shareClass = $entitlement->shareClass;

        $bankDetails = '';
        if ($shareholder && $shareholder->relationLoaded('mandates')) {
            $mandate = $shareholder->mandates->firstWhere('status', 'active');
            if ($mandate) {
                $bankDetails = sprintf(
                    '%s | %s | %s',
                    $mandate->bank_name,
                    $mandate->account_name,
                    $mandate->account_number
                );
            }
        }

        return [
            $entitlement->register_account_id,
            $shareholder?->full_name ?? 'N/A',
            $bankDetails,
            $entitlement->net_amount,
            $entitlement->tax_amount,
            $shareClass?->class_code ?? '',
            $this->corporateActionId,
        ];
    }
}

<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class DividendEntitlementsExport implements FromCollection, WithHeadings, WithMapping
{
    private Collection $entitlements;
    private float $ratePerShare;

    public function __construct(Collection $entitlements, float $ratePerShare)
    {
        $this->entitlements = $entitlements;
        $this->ratePerShare = $ratePerShare;
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
            'Shareholder No',
            'Share Class',
            'Eligible Shares',
            'Rate Per Share',
            'Gross Amount',
            'Tax Amount',
            'Net Amount',
            'Currency',
            'Is Payable',
            'Ineligibility Reason',
        ];
    }

    public function map($entitlement): array
    {
        $account = $entitlement->registerAccount;
        $shareholder = $account ? $account->shareholder : null;
        $shareClass = $entitlement->shareClass;

        return [
            $entitlement->register_account_id,
            $shareholder?->full_name ?? 'N/A',
            $account?->shareholder_no ?? '',
            $shareClass?->class_code ?? '',
            $entitlement->eligible_shares,
            $this->ratePerShare,
            $entitlement->gross_amount,
            $entitlement->tax_amount,
            $entitlement->net_amount,
            $shareClass?->currency ?? '',
            $entitlement->is_payable ? 'YES' : 'NO',
            $entitlement->ineligibility_reason,
        ];
    }
}

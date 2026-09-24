<?php

namespace App\Services\Fris;

class FrisRegisterMetadataResolver
{
    /**
     * Only group register codes where the FRIS names clearly identify the same
     * legal issuer. Funds managed by an issuer remain separate companies.
     *
     * @var array<string, array{issuer_code:string,company_name:string,default_register:int,register_codes:array<int>}>
     */
    private const ISSUER_GROUPS = [
        'cr-services' => [
            'issuer_code' => 'FRIS-GRP-CR-SERVICES',
            'company_name' => 'CR SERVICES (CREDIT BUREAU) PLC',
            'default_register' => 318,
            'register_codes' => [318, 319, 326],
        ],
        'fidelity-bank' => [
            'issuer_code' => 'FRIS-GRP-FIDELITY-BANK',
            'company_name' => 'FIDELITY BANK PLC',
            'default_register' => 87,
            'register_codes' => [87, 362, 428],
        ],
        'stanbic-ibtc-bank' => [
            'issuer_code' => 'FRIS-GRP-STANBIC-IBTC-BANK',
            'company_name' => 'STANBIC IBTC BANK PLC',
            'default_register' => 82,
            'register_codes' => [82, 188, 263],
        ],
        'first-bank' => [
            'issuer_code' => 'FRIS-GRP-FIRST-BANK',
            'company_name' => 'FIRST BANK OF NIGERIA LIMITED',
            'default_register' => 3,
            'register_codes' => [3, 287],
        ],
        'fbn-insurance' => [
            'issuer_code' => 'FRIS-GRP-FBN-INSURANCE',
            'company_name' => 'FBN INSURANCE LIMITED',
            'default_register' => 353,
            'register_codes' => [225, 353],
        ],
        'oando' => [
            'issuer_code' => 'FRIS-GRP-OANDO',
            'company_name' => 'OANDO PLC',
            'default_register' => 9,
            'register_codes' => [9, 30],
        ],
        'ecobank-transnational' => [
            'issuer_code' => 'FRIS-GRP-ECOBANK-TRANS',
            'company_name' => 'ECOBANK TRANSNATIONAL INCORPORATED',
            'default_register' => 246,
            'register_codes' => [183, 246],
        ],
        'nigerian-breweries' => [
            'issuer_code' => 'FRIS-GRP-NB-PLC',
            'company_name' => 'NIGERIAN BREWERIES PLC',
            'default_register' => 166,
            'register_codes' => [166, 356],
        ],
        'prestige-assurance' => [
            'issuer_code' => 'FRIS-GRP-PRESTIGE',
            'company_name' => 'PRESTIGE ASSURANCE PLC',
            'default_register' => 167,
            'register_codes' => [167, 357],
        ],
        'aso-savings' => [
            'issuer_code' => 'FRIS-GRP-ASO-SAVINGS',
            'company_name' => 'ASO SAVINGS AND LOANS PLC',
            'default_register' => 172,
            'register_codes' => [172, 439],
        ],
        'cadbury' => [
            'issuer_code' => 'FRIS-GRP-CADBURY',
            'company_name' => 'CADBURY NIGERIA PLC',
            'default_register' => 274,
            'register_codes' => [274, 280],
        ],
        'mrs-oil' => [
            'issuer_code' => 'FRIS-GRP-MRS-OIL',
            'company_name' => 'MRS OIL NIGERIA PLC',
            'default_register' => 327,
            'register_codes' => [327, 438],
        ],
        'afrinvest-plutus' => [
            'issuer_code' => 'FRIS-GRP-AFRINVEST-PLUTUS',
            'company_name' => 'AFRINVEST PLUTUS FUND',
            'default_register' => 328,
            'register_codes' => [328, 339],
        ],
        'presco' => [
            'issuer_code' => 'FRIS-GRP-PRESCO',
            'company_name' => 'PRESCO PLC',
            'default_register' => 63,
            'register_codes' => [63, 420, 437],
        ],
    ];

    /**
     * @return array{
     *   issuer_code:string,company_name:string,register_code:string,register_name:string,
     *   is_default:bool,instrument_type_code:string,instrument_category:string,
     *   unit_precision_type:string,decimal_precision:?int,class_code:string,
     *   class_name:string,class_description:string,cscs_security_code:string
     * }
     */
    public function resolve(int $registerCode, ?string $sourceName): array
    {
        $sourceName = trim((string) $sourceName) ?: 'FRIS Register '.$registerCode;
        $group = $this->groupFor($registerCode);
        $instrumentTypeCode = $this->instrumentTypeCode($sourceName);
        $shareClass = $this->shareClass($registerCode, $sourceName, $instrumentTypeCode);

        return [
            'issuer_code' => $group['issuer_code'] ?? 'FRIS-'.$registerCode,
            'company_name' => $group['company_name'] ?? $sourceName,
            'register_code' => (string) $registerCode,
            'register_name' => $sourceName,
            'is_default' => $group === null || $group['default_register'] === $registerCode,
            'instrument_type_code' => $instrumentTypeCode,
            'instrument_category' => $this->instrumentCategory($instrumentTypeCode),
            'unit_precision_type' => $instrumentTypeCode === 'bond' ? 'whole_number' : 'decimal',
            'decimal_precision' => $instrumentTypeCode === 'bond' ? null : 6,
            'class_code' => $shareClass['code'],
            'class_name' => $shareClass['name'],
            'class_description' => $shareClass['description'],
            'cscs_security_code' => 'FRIS'.$registerCode,
        ];
    }

    /** @return array{issuer_code:string,company_name:string,default_register:int,register_codes:array<int>}|null */
    private function groupFor(int $registerCode): ?array
    {
        foreach (self::ISSUER_GROUPS as $group) {
            if (in_array($registerCode, $group['register_codes'], true)) {
                return $group;
            }
        }

        return null;
    }

    private function instrumentTypeCode(string $name): string
    {
        if (preg_match('/\b(FUND|ETF|REIT)\b|INVESTMENT\s+TRUST/i', $name)) {
            return 'mutual_fund';
        }

        if (preg_match('/\b(BOND|BONDS|DEBENTURE|DEBENTURES)\b|DEBT\s+ISSUANCE|FIXED\s+RATE/i', $name)) {
            return 'bond';
        }

        return 'ordinary_share';
    }

    private function instrumentCategory(string $instrumentTypeCode): string
    {
        return match ($instrumentTypeCode) {
            'bond' => 'debt',
            'mutual_fund' => 'fund',
            default => 'equity',
        };
    }

    /** @return array{code:string,name:string,description:string} */
    private function shareClass(int $registerCode, string $name, string $instrumentTypeCode): array
    {
        $explicit = match ($registerCode) {
            319 => ['code' => 'PREF-B', 'name' => 'Preference Class B'],
            326 => ['code' => 'PREF-A', 'name' => 'Preference Class A'],
            default => null,
        };

        if ($explicit !== null) {
            return $explicit + ['description' => $explicit['name'].' imported from FRIS'];
        }

        if (preg_match('/PREFERENCE.*CLASS\s*([A-Z])/i', $name, $matches)) {
            $letter = strtoupper($matches[1]);

            return [
                'code' => 'PREF-'.$letter,
                'name' => 'Preference Class '.$letter,
                'description' => 'Preference Class '.$letter.' imported from FRIS',
            ];
        }

        if ($instrumentTypeCode === 'mutual_fund') {
            return ['code' => 'UNIT', 'name' => 'Fund Units', 'description' => 'Fund units imported from FRIS'];
        }

        if ($instrumentTypeCode === 'bond') {
            return ['code' => 'BOND', 'name' => 'Bond Units', 'description' => 'Bond units imported from FRIS'];
        }

        if (preg_match('/PRIVATE\s+PLACEMENT/i', $name)) {
            return ['code' => 'ORD-PRIVATE', 'name' => 'Private Placement Ordinary', 'description' => 'Private placement ordinary shares imported from FRIS'];
        }

        return ['code' => 'ORD', 'name' => 'Ordinary', 'description' => 'Ordinary shares imported from FRIS'];
    }
}

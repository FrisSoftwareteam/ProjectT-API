<?php

namespace Tests\Unit;

use App\Services\Fris\FrisRegisterMetadataResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FrisRegisterMetadataResolverTest extends TestCase
{
    #[DataProvider('metadataCases')]
    public function test_it_resolves_company_instrument_and_share_class_metadata(
        int $registerCode,
        string $name,
        string $issuerCode,
        string $instrumentType,
        string $classCode,
    ): void {
        $metadata = (new FrisRegisterMetadataResolver)->resolve($registerCode, $name);

        $this->assertSame($issuerCode, $metadata['issuer_code']);
        $this->assertSame($instrumentType, $metadata['instrument_type_code']);
        $this->assertSame($classCode, $metadata['class_code']);
    }

    /** @return array<string, array{int,string,string,string,string}> */
    public static function metadataCases(): array
    {
        return [
            'ordinary equity' => [96, 'FIRST CUSTODIAN NIGERIA LIMITED', 'FRIS-96', 'ordinary_share', 'ORD'],
            'CR ordinary group' => [318, 'CR SERVICES (CREDIT BUREAU) PLC (ORDINARY)', 'FRIS-GRP-CR-SERVICES', 'ordinary_share', 'ORD'],
            'CR preference B' => [319, 'CR SERVICES (CREDIT BUREAU) PLC (PREFERENCE) CLASS B', 'FRIS-GRP-CR-SERVICES', 'ordinary_share', 'PREF-B'],
            'CR preference A' => [326, 'CR SERVICES (CREDIT BUREAU) PLC (PREFERENCE) CLASS A', 'FRIS-GRP-CR-SERVICES', 'ordinary_share', 'PREF-A'],
            'Fidelity private placement' => [428, 'FIDELITY BANK PLC (PRIVATE PLACEMENT OF 3,037,414,308)', 'FRIS-GRP-FIDELITY-BANK', 'ordinary_share', 'ORD-PRIVATE'],
            'mutual fund' => [159, 'FBN HERITAGE FUND', 'FRIS-159', 'mutual_fund', 'UNIT'],
            'eurobond fund remains a fund' => [149, 'FBN NIGERIA EUROBOND (USD) FUND', 'FRIS-149', 'mutual_fund', 'UNIT'],
            'bond' => [343, 'UNION BANK DEBT ISSUANCE SERIES 3 FIXED RATE BOND', 'FRIS-343', 'bond', 'BOND'],
        ];
    }
}

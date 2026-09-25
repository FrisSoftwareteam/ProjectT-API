<?php

namespace App\Services\Fris;

class FrisStageRecordFactory
{
    /** @param array<string, mixed> $row */
    public function profileRecord(int $batchId, array $row, mixed $now): array
    {
        $sourceKey = $row['register_code'].'|'.$row['account_number'];
        $errors = [];
        $name = trim((string) ($row['names'] ?? ''));
        $address = trim((string) ($row['address'] ?? ''));
        if ($name === '') {
            $errors[] = 'MISSING_NAME';
        }
        if ($address === '') {
            $errors[] = 'MISSING_ADDRESS';
        }

        return [
            'batch_id' => $batchId,
            'source_row_number' => $row['source_row_number'],
            'register_code' => $row['register_code'],
            'account_number' => $row['account_number'],
            'source_key_hash' => hash('sha256', $sourceKey),
            'row_hash' => hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES)),
            'status' => $errors === [] ? 'VALID' : 'ERROR',
            'holder_type' => null,
            'normalized_name' => $name ?: null,
            'normalized_email' => trim((string) ($row['mail'] ?? '')) ?: null,
            'normalized_mobile' => trim((string) ($row['mobile'] ?? '')) ?: null,
            'source_holdings' => is_numeric($row['holdings'] ?? null) ? number_format((float) $row['holdings'], 6, '.', '') : null,
            'source_data' => json_encode($row, JSON_UNESCAPED_SLASHES),
            'normalized_data' => json_encode([
                'name' => $name ?: null,
                'address' => $address ?: null,
                'email' => trim((string) ($row['mail'] ?? '')) ?: null,
                'mobile' => trim((string) ($row['mobile'] ?? '')) ?: null,
                'bankac' => trim((string) ($row['bankac'] ?? '')) ?: null,
                'branch_code' => trim((string) ($row['branch_code'] ?? '')) ?: null,
                'clearing_no' => trim((string) ($row['clearing_no'] ?? '')) ?: null,
            ], JSON_UNESCAPED_SLASHES),
            'errors' => $errors === [] ? null : json_encode($errors),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param array<string, mixed> $row */
    public function unitRecord(int $batchId, array $row, mixed $now): array
    {
        $sourceKey = 'units|'.$row['id'];
        $errors = [];
        if (empty($row['acctno']) || empty($row['regcode'])) {
            $errors[] = 'MISSING_ACCOUNT_KEY';
        }
        if (! is_numeric($row['units'] ?? null)) {
            $errors[] = 'INVALID_UNITS';
        }

        return [
            'batch_id' => $batchId,
            'source_row_number' => $row['source_row_number'],
            'fris_unit_id' => $row['id'],
            'register_code' => $row['regcode'],
            'account_number' => $row['acctno'],
            'cert_number' => $row['cert_number'],
            'source_key_hash' => hash('sha256', $sourceKey),
            'row_hash' => hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES)),
            'status' => $errors === [] ? 'VALID' : 'ERROR',
            'source_units' => is_numeric($row['units'] ?? null) ? number_format((float) $row['units'], 6, '.', '') : null,
            'issue_date' => $this->dateOrNull($row['issue_dt'] ?? null),
            'source_status' => $this->integerOrNull($row['status'] ?? null),
            'source_verified' => $this->integerOrNull($row['verif'] ?? null),
            'source_claimed' => $this->integerOrNull($row['claimed'] ?? null),
            'source_stop' => $this->integerOrNull($row['stop'] ?? null),
            'source_data' => json_encode($row, JSON_UNESCAPED_SLASHES),
            'normalized_data' => json_encode([
                'certificate_number' => $row['cert_number'],
                'units' => $row['units'],
                'issue_date' => $row['issue_dt'],
                'category' => $row['category'],
                'category_desc' => $row['category_desc'],
                'narration' => $row['narr'],
                'description' => $row['desc_trans'],
                'old_certificate_number' => trim((string) ($row['oldcertnumb'] ?? '')) ?: null,
                'transfer_reference' => trim((string) ($row['xfer_no'] ?? '')) ?: null,
                'verification_date' => $this->dateOrNull($row['verif_dt'] ?? null),
                'broker_verified' => trim((string) ($row['brok_verified'] ?? '')) ?: null,
                'legacy_cscs_account_no' => $this->stringOrNull($row['solid_acct'] ?? null),
                'claimed' => $this->integerOrNull($row['claimed'] ?? null),
                'unclaimed' => $this->integerOrNull($row['unclaim'] ?? null),
                'stopped' => $this->integerOrNull($row['stop'] ?? null),
                'lodge_reference' => $this->stringOrNull($row['lodge'] ?? null),
                'lodge_date' => $this->dateOrNull($row['lodge_dt'] ?? null),
                'lodge_counter' => $this->stringOrNull($row['lodge_cntr'] ?? null),
                'extra' => trim((string) ($row['extra_json'] ?? '')) ?: null,
            ], JSON_UNESCAPED_SLASHES),
            'errors' => $errors === [] ? null : json_encode($errors),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function integerOrNull(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' || ! is_numeric($value) ? null : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '0' ? null : $value;
    }
}

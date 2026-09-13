<?php

namespace App\Console\Commands;

use App\Models\FrisMigrationBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

class StageFrisRegister extends Command
{
    protected $signature = 'fris:stage-register
        {register_code : FRIS register code to stage}
        {path=FRIS.sqlite : Path to the FRIS SQLite database}
        {--chunk=1000 : Number of rows inserted per chunk}
        {--limit=0 : Optional row limit for pilot runs}
        {--profiles-only : Stage profiles without unit rows}
        {--skip-sha : Skip source file SHA-256 calculation}';

    protected $description = 'Stage one FRIS register into migration tables without publishing to Project T domain tables';

    public function handle(): int
    {
        $path = base_path((string) $this->argument('path'));
        if (! is_file($path)) {
            $this->error("FRIS SQLite file not found: {$path}");

            return self::FAILURE;
        }

        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
        } catch (Throwable $exception) {
            $this->error('Unable to open FRIS SQLite file: '.$exception->getMessage());

            return self::FAILURE;
        }

        $registerCode = (int) $this->argument('register_code');
        $company = $this->first($pdo, 'select register_code, company_name from companies where register_code = :register_code', [
            'register_code' => $registerCode,
        ]);
        if ($company === null) {
            $this->error("No FRIS company/register found for register code {$registerCode}.");

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $batch = FrisMigrationBatch::create([
            'public_id' => (string) Str::uuid(),
            'source_filename' => basename($path),
            'source_path' => $path,
            'source_sha256' => $this->option('skip-sha') ? null : hash_file('sha256', $path),
            'source_size' => filesize($path),
            'status' => FrisMigrationBatch::STAGING,
            'register_code' => $registerCode,
            'company_name' => $company['company_name'],
        ]);

        try {
            $this->info("Staging FRIS register {$registerCode}: {$company['company_name']}");
            $profileTotals = $this->stageProfiles($pdo, $batch, $registerCode, $limit);
            $unitTotals = ['rows' => 0, 'quantity' => '0.000000'];
            if (! $this->option('profiles-only')) {
                $unitTotals = $this->stageUnits($pdo, $batch, $registerCode, $limit);
            }

            $batch->update([
                'status' => FrisMigrationBatch::STAGED,
                'expected_profile_rows' => $profileTotals['rows'],
                'expected_unit_rows' => $unitTotals['rows'],
                'staged_profile_rows' => $profileTotals['rows'],
                'staged_unit_rows' => $unitTotals['rows'],
                'valid_profile_rows' => $profileTotals['valid'],
                'valid_unit_rows' => $unitTotals['valid'] ?? 0,
                'error_profile_rows' => $profileTotals['errors'],
                'error_unit_rows' => $unitTotals['errors'] ?? 0,
                'staged_profile_holdings' => $profileTotals['holdings'],
                'staged_unit_quantity' => $unitTotals['quantity'],
            ]);

            $this->info("FRIS staging batch {$batch->id} completed.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $batch->update([
                'status' => FrisMigrationBatch::FAILED,
                'failure_reason' => $exception->getMessage(),
            ]);
            $this->error('FRIS staging failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{rows:int,valid:int,errors:int,holdings:string} */
    private function stageProfiles(PDO $pdo, FrisMigrationBatch $batch, int $registerCode, int $limit): array
    {
        $sql = 'select rowid as source_row_number, * from profiles where register_code = :register_code order by account_number';
        if ($limit > 0) {
            $sql .= ' limit '.$limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['register_code' => $registerCode]);

        $rows = 0;
        $valid = 0;
        $errors = 0;
        $holdings = 0.0;
        $buffer = [];
        $chunk = max(1, (int) $this->option('chunk'));
        $now = now();

        while ($row = $stmt->fetch()) {
            $rows++;
            $record = $this->profileRecord($batch->id, $row, $now);
            $record['status'] === 'VALID' ? $valid++ : $errors++;
            $holdings += (float) ($record['source_holdings'] ?? 0);
            $buffer[] = $record;
            if (count($buffer) >= $chunk) {
                DB::table('fris_migration_profiles')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('fris_migration_profiles')->insert($buffer);
        }

        return ['rows' => $rows, 'valid' => $valid, 'errors' => $errors, 'holdings' => number_format($holdings, 6, '.', '')];
    }

    /** @return array{rows:int,valid:int,errors:int,quantity:string} */
    private function stageUnits(PDO $pdo, FrisMigrationBatch $batch, int $registerCode, int $limit): array
    {
        $sql = 'select rowid as source_row_number, * from units where regcode = :register_code order by id';
        if ($limit > 0) {
            $sql .= ' limit '.$limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['register_code' => $registerCode]);

        $rows = 0;
        $valid = 0;
        $errors = 0;
        $quantity = 0.0;
        $buffer = [];
        $chunk = max(1, (int) $this->option('chunk'));
        $now = now();

        while ($row = $stmt->fetch()) {
            $rows++;
            $record = $this->unitRecord($batch->id, $row, $now);
            $record['status'] === 'VALID' ? $valid++ : $errors++;
            $quantity += (float) ($record['source_units'] ?? 0);
            $buffer[] = $record;
            if (count($buffer) >= $chunk) {
                DB::table('fris_migration_units')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('fris_migration_units')->insert($buffer);
        }

        return ['rows' => $rows, 'valid' => $valid, 'errors' => $errors, 'quantity' => number_format($quantity, 6, '.', '')];
    }

    /** @param array<string, mixed> $row */
    private function profileRecord(int $batchId, array $row, mixed $now): array
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
    private function unitRecord(int $batchId, array $row, mixed $now): array
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
            'source_status' => $row['status'],
            'source_verified' => $row['verif'],
            'source_claimed' => $row['claimed'],
            'source_stop' => $row['stop'],
            'source_data' => json_encode($row, JSON_UNESCAPED_SLASHES),
            'normalized_data' => json_encode([
                'certificate_number' => $row['cert_number'],
                'units' => $row['units'],
                'issue_date' => $row['issue_dt'],
                'category' => $row['category'],
                'category_desc' => $row['category_desc'],
                'narration' => $row['narr'],
                'description' => $row['desc_trans'],
            ], JSON_UNESCAPED_SLASHES),
            'errors' => $errors === [] ? null : json_encode($errors),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param array<string, mixed> $bindings */
    private function first(PDO $pdo, string $sql, array $bindings): ?array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
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
}

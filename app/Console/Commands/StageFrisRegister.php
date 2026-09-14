<?php

namespace App\Console\Commands;

use App\Models\FrisMigrationBatch;
use App\Services\Fris\FrisStageRecordFactory;
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

    public function __construct(private readonly FrisStageRecordFactory $records)
    {
        parent::__construct();
    }

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
            $record = $this->records->profileRecord($batch->id, $row, $now);
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
            $record = $this->records->unitRecord($batch->id, $row, $now);
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

    /** @param array<string, mixed> $bindings */
    private function first(PDO $pdo, string $sql, array $bindings): ?array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

}

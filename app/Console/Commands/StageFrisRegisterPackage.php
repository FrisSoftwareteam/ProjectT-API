<?php

namespace App\Console\Commands;

use App\Models\FrisMigrationBatch;
use App\Services\Fris\FrisStageRecordFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

class StageFrisRegisterPackage extends Command
{
    protected $signature = 'fris:stage-register-package
        {package : Path to a FRIS register package ZIP}
        {--chunk=1000 : Number of rows inserted per chunk}
        {--resume-batch= : Resume a matching failed or interrupted staging batch}';

    protected $description = 'Stage one FRIS register from a portable ZIP package';

    public function __construct(private readonly FrisStageRecordFactory $records)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $packagePath = base_path((string) $this->argument('package'));
        if (! is_file($packagePath)) {
            $this->error("FRIS register package not found: {$packagePath}");

            return self::FAILURE;
        }

        $extractDir = storage_path('app/fris-package-work/'.pathinfo($packagePath, PATHINFO_FILENAME).'-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($extractDir);

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            $this->error('Unable to open FRIS register package ZIP.');

            return self::FAILURE;
        }
        $zip->extractTo($extractDir);
        $zip->close();

        try {
            $manifestPath = $extractDir.'/manifest.json';
            $profilesPath = $extractDir.'/profiles.csv';
            $unitsPath = $extractDir.'/units.csv';
            if (! is_file($manifestPath) || ! is_file($profilesPath) || ! is_file($unitsPath)) {
                throw new \RuntimeException('Package must contain manifest.json, profiles.csv, and units.csv.');
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (($manifest['format'] ?? null) !== 'projectt-fris-register-package-v1') {
                throw new \RuntimeException('Unsupported FRIS package format.');
            }
            if (($manifest['profiles']['sha256'] ?? null) !== hash_file('sha256', $profilesPath)) {
                throw new \RuntimeException('profiles.csv checksum mismatch.');
            }
            if (($manifest['units']['sha256'] ?? null) !== hash_file('sha256', $unitsPath)) {
                throw new \RuntimeException('units.csv checksum mismatch.');
            }

            $packageHash = hash_file('sha256', $packagePath);
            $batch = $this->resolveBatch($packagePath, $packageHash, $manifest);
            $resuming = $this->option('resume-batch') !== null;

            $action = $resuming ? 'Resuming' : 'Staging';
            $this->info("{$action} FRIS package register {$batch->register_code}: {$batch->company_name}");
            $profileTotals = $this->stageCsv($profilesPath, $batch, 'profile', $resuming);
            $unitTotals = $this->stageCsv($unitsPath, $batch, 'unit', $resuming);

            $actualProfileRows = DB::table('fris_migration_profiles')->where('batch_id', $batch->id)->count();
            $actualUnitRows = DB::table('fris_migration_units')->where('batch_id', $batch->id)->count();
            if ($actualProfileRows !== $profileTotals['rows'] || $actualUnitRows !== $unitTotals['rows']) {
                throw new \RuntimeException(sprintf(
                    'Staged row count mismatch after resume (profiles %d/%d, units %d/%d).',
                    $actualProfileRows,
                    $profileTotals['rows'],
                    $actualUnitRows,
                    $unitTotals['rows']
                ));
            }

            $batch->update([
                'status' => FrisMigrationBatch::STAGED,
                'expected_profile_rows' => $profileTotals['rows'],
                'expected_unit_rows' => $unitTotals['rows'],
                'staged_profile_rows' => $profileTotals['rows'],
                'staged_unit_rows' => $unitTotals['rows'],
                'valid_profile_rows' => $profileTotals['valid'],
                'valid_unit_rows' => $unitTotals['valid'],
                'error_profile_rows' => $profileTotals['errors'],
                'error_unit_rows' => $unitTotals['errors'],
                'staged_profile_holdings' => $profileTotals['quantity'],
                'staged_unit_quantity' => $unitTotals['quantity'],
            ]);

            $this->info("FRIS package staging batch {$batch->id} completed.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            if (isset($batch)) {
                $batch->update([
                    'status' => FrisMigrationBatch::FAILED,
                    'failure_reason' => Str::limit($exception->getMessage(), 1000, '...'),
                ]);
            }
            $this->error('FRIS package staging failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($extractDir);
        }
    }

    /** @return array{rows:int,valid:int,errors:int,quantity:string} */
    private function stageCsv(string $path, FrisMigrationBatch $batch, string $type, bool $resuming): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV: '.$path);
        }

        $headers = fgetcsv($handle, null, ',', '"', '');
        if ($headers === false) {
            fclose($handle);

            return ['rows' => 0, 'valid' => 0, 'errors' => 0, 'quantity' => '0.000000'];
        }

        $rows = 0;
        $valid = 0;
        $errors = 0;
        $quantity = 0.0;
        $buffer = [];
        $chunk = max(1, (int) $this->option('chunk'));
        $now = now();
        $table = $type === 'profile' ? 'fris_migration_profiles' : 'fris_migration_units';
        $repairedCsvRows = 0;
        $resumeAfter = $resuming
            ? (int) DB::table($table)->where('batch_id', $batch->id)->max('source_row_number')
            : 0;
        $committedChunks = 0;
        if ($resumeAfter > 0) {
            $this->line("Resuming {$type} rows after source row {$resumeAfter}.");
        }

        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count($values) !== count($headers)) {
                $values = $this->repairTrailingExtraJson($headers, $values);
                $repairedCsvRows++;
            }

            $row = array_combine($headers, $values);
            if ($row === false) {
                continue;
            }
            $rows++;
            $record = $type === 'profile'
                ? $this->records->profileRecord($batch->id, $row, $now)
                : $this->records->unitRecord($batch->id, $row, $now);
            $record['status'] === 'VALID' ? $valid++ : $errors++;
            $quantity += (float) ($type === 'profile' ? ($record['source_holdings'] ?? 0) : ($record['source_units'] ?? 0));
            if ((int) $record['source_row_number'] <= $resumeAfter) {
                continue;
            }
            $buffer[] = $record;
            if (count($buffer) >= $chunk) {
                DB::table($table)->insert($buffer);
                $committedChunks++;
                if ($committedChunks % 100 === 0) {
                    $this->line("Committed {$type} chunk {$committedChunks}; last source row {$record['source_row_number']}");
                }
                $buffer = [];
            }
        }
        fclose($handle);

        if ($buffer !== []) {
            DB::table($table)->insert($buffer);
            $committedChunks++;
        }
        if ($committedChunks > 0) {
            $this->line("Committed {$type} chunks: {$committedChunks}");
        }
        if ($repairedCsvRows > 0) {
            $this->warn("Repaired {$repairedCsvRows} {$type} CSV row(s) with fragmented trailing extra_json.");
        }

        return ['rows' => $rows, 'valid' => $valid, 'errors' => $errors, 'quantity' => number_format($quantity, 6, '.', '')];
    }

    /** @param array<int, string> $headers @param array<int, string|null> $values @return array<int, string|null> */
    private function repairTrailingExtraJson(array $headers, array $values): array
    {
        $expected = count($headers);
        if (count($values) > $expected && end($headers) === 'extra_json') {
            return array_merge(
                array_slice($values, 0, $expected - 1),
                [implode(',', array_slice($values, $expected - 1))]
            );
        }

        throw new \RuntimeException(sprintf(
            'CSV row has %d columns, expected %d.',
            count($values),
            $expected
        ));
    }

    /** @param array<string, mixed> $manifest */
    private function resolveBatch(string $packagePath, string $packageHash, array $manifest): FrisMigrationBatch
    {
        $resumeBatchId = $this->option('resume-batch');
        if ($resumeBatchId === null) {
            return FrisMigrationBatch::create([
                'public_id' => (string) Str::uuid(),
                'source_filename' => basename($packagePath),
                'source_path' => $packagePath,
                'source_sha256' => $packageHash,
                'source_size' => filesize($packagePath),
                'status' => FrisMigrationBatch::STAGING,
                'register_code' => (int) $manifest['register_code'],
                'company_name' => (string) $manifest['company_name'],
            ]);
        }

        $batch = FrisMigrationBatch::find((int) $resumeBatchId);
        if ($batch === null) {
            throw new \RuntimeException('Resume batch not found.');
        }
        if (! in_array($batch->status, [FrisMigrationBatch::FAILED, FrisMigrationBatch::STAGING], true)) {
            throw new \RuntimeException('Only FAILED or STAGING batches can be resumed.');
        }
        if ((int) $batch->register_code !== (int) $manifest['register_code']) {
            throw new \RuntimeException('Resume batch register does not match the package register.');
        }
        if ($batch->source_sha256 !== null && ! hash_equals($batch->source_sha256, $packageHash)) {
            throw new \RuntimeException('Resume batch package checksum does not match.');
        }

        $batch->update([
            'source_filename' => basename($packagePath),
            'source_path' => $packagePath,
            'source_sha256' => $packageHash,
            'source_size' => filesize($packagePath),
            'status' => FrisMigrationBatch::STAGING,
            'failure_reason' => null,
        ]);

        return $batch->fresh();
    }
}

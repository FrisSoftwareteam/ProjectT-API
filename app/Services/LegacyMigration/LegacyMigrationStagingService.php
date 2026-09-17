<?php

namespace App\Services\LegacyMigration;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRecord;
use Illuminate\Validation\ValidationException;
use Throwable;

class LegacyMigrationStagingService
{
    public function __construct(
        private readonly LegacyMigrationPackageRegistry $packages,
        private readonly EstockJsonReader $reader,
        private readonly LegacyMigrationWorkflowService $workflow
    ) {}

    public function stage(int $batchId, ?int $actorId = null): void
    {
        $batch = LegacyMigrationBatch::findOrFail($batchId);
        if (! in_array($batch->status, [LegacyMigrationBatch::CREATED, LegacyMigrationBatch::FAILED], true)) {
            throw ValidationException::withMessages(['batch' => ['Only a created or failed batch may be staged.']]);
        }

        $package = $this->packages->get($batch->package_key);
        $this->assertSource($batch, $package);
        $from = $batch->status;
        $batch->update(['status' => LegacyMigrationBatch::STAGING, 'failure_reason' => null]);
        $this->workflow->event($batch, 'STAGING_STARTED', $from, LegacyMigrationBatch::STAGING, $actorId);

        try {
            LegacyMigrationRecord::where('batch_id', $batch->id)->delete();
            $buffer = [];
            foreach ($this->reader->rows($package['source_path']) as $rowNumber => $row) {
                $buffer[] = $this->transform($batch, $package, $rowNumber, $row);
                if (count($buffer) >= config('legacy_migrations.chunk_size', 1000)) {
                    LegacyMigrationRecord::insert($buffer);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                LegacyMigrationRecord::insert($buffer);
            }

            $this->markDuplicateSourceKeys($batch);
            $this->refreshTotals($batch);
            $batch->refresh()->update(['status' => LegacyMigrationBatch::STAGED]);
            $this->workflow->event($batch, 'STAGING_COMPLETED', LegacyMigrationBatch::STAGING, LegacyMigrationBatch::STAGED, $actorId);
        } catch (Throwable $exception) {
            $batch->update(['status' => LegacyMigrationBatch::FAILED, 'failure_reason' => $exception->getMessage()]);
            $this->workflow->event($batch, 'STAGING_FAILED', LegacyMigrationBatch::STAGING, LegacyMigrationBatch::FAILED, $actorId);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $package @param array<string, mixed> $row @return array<string, mixed> */
    private function transform(LegacyMigrationBatch $batch, array $package, int $rowNumber, array $row): array
    {
        $legacyAccount = trim((string) ($row['account_no'] ?? ''));
        $name = trim((string) ($row['NAME'] ?? ''));
        $address = trim((string) ($row['ADDRESS'] ?? ''));
        $category = strtoupper(trim((string) ($row['HOLDERS TYPE'] ?? '')));
        $temporaryType = strtoupper(trim((string) ($row['TEMP TYPE'] ?? '')));
        $sourceRegisterCode = trim((string) ($row['reg_code'] ?? ''));
        $quantity = is_numeric($row['SumOfno_of_units'] ?? null)
            ? number_format((float) $row['SumOfno_of_units'], 6, '.', '')
            : null;
        $holderType = $category === 'V'
            ? ($package['foreign_temp_types'][$temporaryType] ?? null)
            : ($package['category_holder_types'][$category] ?? null);
        $errors = [];
        if ($legacyAccount === '' || strlen($legacyAccount) > 30) {
            $errors[] = 'INVALID_LEGACY_ACCOUNT';
        }
        if ($sourceRegisterCode !== (string) ($package['source_register_code'] ?? '')) {
            $errors[] = 'WRONG_SOURCE_REGISTER';
        }
        if ($name === '' || strlen($name) > 255) {
            $errors[] = 'INVALID_NAME';
        }
        if ($address === '' || strlen($address) > 255) {
            $errors[] = 'INVALID_ADDRESS';
        }
        if (strlen(trim((string) ($row['state name'] ?? ''))) > 100) {
            $errors[] = 'INVALID_STATE';
        }
        if ($holderType === null) {
            $errors[] = 'UNMAPPED_HOLDER_TYPE';
        }
        if ($quantity === null || (float) $quantity <= 0 || (float) $quantity >= 1.0e22) {
            $errors[] = 'INVALID_QUANTITY';
        }

        $sourceKey = ($package['source_register_code'] ?? '87').'|'.$legacyAccount;
        $sourceHash = hash('sha256', $sourceKey);
        $normalized = [
            'full_name' => $name,
            'address_line1' => $address,
            'state' => trim((string) ($row['state name'] ?? '')) ?: null,
            'country' => 'Nigeria',
            'status' => $package['status'],
            'contact_verified' => false,
        ];
        $now = now();

        return [
            'batch_id' => $batch->id,
            'source_row_number' => $rowNumber,
            'source_key_hash' => $sourceHash,
            'row_hash' => hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'idempotency_key' => hash('sha256', $batch->package_key.'|'.$sourceKey),
            'source_account_number' => $legacyAccount,
            'target_account_no' => 'L'.strtoupper(substr($sourceHash, 0, 19)),
            'target_email' => strtolower('legacy-'.substr($sourceHash, 0, 24).'@invalid.projectt.local'),
            'target_phone' => 'LEG'.strtoupper(substr($sourceHash, 0, 29)),
            'holder_type' => $holderType ?? 'individual',
            'category_code' => $category ?: 'UNKNOWN',
            'quantity' => $quantity ?? '0.000000',
            'holding_mode' => $package['holding_mode'],
            'status' => $errors === [] ? 'VALID' : 'ERROR',
            'normalized_data' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'errors' => $errors === [] ? null : json_encode($errors),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param array<string, mixed> $package */
    private function assertSource(LegacyMigrationBatch $batch, array $package): void
    {
        $path = $package['source_path'];
        if (! is_file($path) || hash_file('sha256', $path) !== $batch->source_sha256) {
            throw ValidationException::withMessages(['source' => ['The source file is missing or its checksum has changed.']]);
        }
    }

    private function refreshTotals(LegacyMigrationBatch $batch): void
    {
        $query = LegacyMigrationRecord::where('batch_id', $batch->id);
        $batch->update([
            'staged_rows' => (clone $query)->count(),
            'valid_rows' => (clone $query)->where('status', 'VALID')->count(),
            'error_rows' => (clone $query)->where('status', 'ERROR')->count(),
            'staged_quantity' => (string) ((clone $query)->where('status', 'VALID')->sum('quantity')),
        ]);
    }

    private function markDuplicateSourceKeys(LegacyMigrationBatch $batch): void
    {
        LegacyMigrationRecord::where('batch_id', $batch->id)->select('source_key_hash')
            ->groupBy('source_key_hash')->havingRaw('COUNT(*) > 1')->pluck('source_key_hash')
            ->chunk(500)->each(function ($hashes) use ($batch) {
                LegacyMigrationRecord::where('batch_id', $batch->id)->whereIn('source_key_hash', $hashes)
                    ->update(['status' => 'ERROR', 'errors' => json_encode(['DUPLICATE_SOURCE_KEY'])]);
            });
    }
}

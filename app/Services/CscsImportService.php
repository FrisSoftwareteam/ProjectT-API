<?php

namespace App\Services;

use App\Models\CscsUploadBatch;
use App\Models\CscsUploadRow;
use App\Models\ShareClass;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use App\Models\SraExternalIdentifier;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CscsImportService
{
    public function __construct(
        private readonly ShareholderAccountNumberService $accountNumberService
    ) {
    }

    /**
     * @param array<int, UploadedFile> $files
     * @return array<string, mixed>
     */
    public function import(array $files, ?int $defaultRegisterId, ?int $uploadedBy): array
    {
        $batch = CscsUploadBatch::create([
            'uploaded_by' => $uploadedBy,
            'register_id' => $defaultRegisterId,
            'status' => 'processing',
            'uploaded_files' => [],
        ]);

        $masterProfiles = [];
        $movementRows = [];
        $storedFiles = [];
        $counts = [
            'total_rows' => 0,
            'posted_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
            'master_rows' => 0,
            'movement_rows' => 0,
            'replay_rows' => 0,
        ];

        try {
            foreach ($files as $file) {
                $fileType = $this->detectFileType($file);
                $storedPath = $file->storeAs(
                    'private/cscs_uploads',
                    now()->format('Ymd_His_u') . '_' . $file->getClientOriginalName()
                );

                $storedFiles[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $storedPath,
                    'type' => $fileType,
                ];

                $contents = Storage::get($storedPath);
                $lines = preg_split('/\r\n|\n|\r/', (string) $contents) ?: [];

                if ($fileType === 'master') {
                    $parsedProfiles = $this->parseMasterFile($lines);
                    $masterProfiles = array_replace($masterProfiles, $parsedProfiles);
                    $counts['master_rows'] += count($parsedProfiles);
                    continue;
                }

                $parsedMovements = $this->parseMovementFile($lines, $file->getClientOriginalName());
                $movementRows = array_merge($movementRows, $parsedMovements);
                $counts['movement_rows'] += count($parsedMovements);
            }

            foreach ($movementRows as $row) {
                $counts['total_rows']++;
                $this->processMovementRow(
                    $batch,
                    $row,
                    $masterProfiles,
                    $defaultRegisterId,
                    $uploadedBy,
                    $counts
                );
            }

            $batchStatus = $counts['failed_rows'] > 0 ? 'completed_with_errors' : 'completed';
            $batch->update([
                'status' => $batchStatus,
                'uploaded_files' => $storedFiles,
                'summary' => $counts,
            ]);

            return [
                'batch_id' => $batch->id,
                'status' => $batchStatus,
                'summary' => $counts,
            ];
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'uploaded_files' => $storedFiles,
                'summary' => array_merge($counts, ['fatal_error' => $e->getMessage()]),
            ]);

            throw $e;
        }
    }

    /**
     * Reprocess failed movement rows from a previous batch into a new retry batch.
     *
     * @return array<string, mixed>
     */
    public function reprocessFailedRows(int $sourceBatchId, ?int $uploadedBy): array
    {
        $sourceBatch = CscsUploadBatch::findOrFail($sourceBatchId);
        $files = $sourceBatch->uploaded_files ?? [];
        $masterProfiles = [];

        foreach ($files as $fileMeta) {
            if (($fileMeta['type'] ?? null) !== 'master') {
                continue;
            }
            $path = $fileMeta['path'] ?? null;
            if (! $path || ! Storage::exists($path)) {
                continue;
            }
            $contents = Storage::get($path);
            $lines = preg_split('/\r\n|\n|\r/', (string) $contents) ?: [];
            $masterProfiles = array_replace($masterProfiles, $this->parseMasterFile($lines));
        }

        $retryBatch = CscsUploadBatch::create([
            'uploaded_by' => $uploadedBy,
            'register_id' => $sourceBatch->register_id,
            'status' => 'processing',
            'uploaded_files' => $files,
            'summary' => ['source_batch_id' => $sourceBatchId],
        ]);

        $counts = [
            'total_rows' => 0,
            'posted_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
            'master_rows' => count($masterProfiles),
            'movement_rows' => 0,
            'replay_rows' => 0,
            'source_batch_id' => $sourceBatchId,
        ];

        $failedRows = CscsUploadRow::where('batch_id', $sourceBatchId)
            ->where('file_type', 'movement')
            ->where('status', 'failed')
            ->orderBy('id')
            ->get();

        foreach ($failedRows as $failedRow) {
            $counts['total_rows']++;
            $counts['movement_rows']++;
            $parsed = $this->parseMovementLine(
                (string) $failedRow->raw_line,
                (int) $failedRow->row_number,
                (string) $failedRow->source_filename
            );

            $this->processMovementRow(
                $retryBatch,
                $parsed,
                $masterProfiles,
                $sourceBatch->register_id ? (int) $sourceBatch->register_id : null,
                $uploadedBy,
                $counts
            );
        }

        $batchStatus = $counts['failed_rows'] > 0 ? 'completed_with_errors' : 'completed';
        $retryBatch->update([
            'status' => $batchStatus,
            'summary' => $counts,
        ]);

        return [
            'batch_id' => $retryBatch->id,
            'status' => $batchStatus,
            'summary' => $counts,
        ];
    }

    private function detectFileType(UploadedFile $file): string
    {
        $line = '';
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle !== false) {
            while (($raw = fgets($handle)) !== false) {
                $raw = rtrim($raw, "\r\n");
                if ($raw !== '') {
                    $line = $raw;
                    break;
                }
            }
            fclose($handle);
        }

        $length = strlen($line);
        if ($length === 393) {
            return 'master';
        }

        if ($length === 114) {
            return 'movement';
        }

        throw ValidationException::withMessages([
            'files' => ["Unsupported file format for {$file->getClientOriginalName()}."],
        ]);
    }

    /**
     * @param array<int, string> $lines
     * @return array<string, array<string, string|null>>
     */
    private function parseMasterFile(array $lines): array
    {
        $profiles = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $identifier = trim(substr($line, 0, 12));
            if ($identifier === '') {
                continue;
            }

            preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $line, $emailMatch);
            preg_match('/\b\d{10,13}\b/', $line, $phoneMatch);

            $name = trim(substr($line, 12, 45));
            if ($name === '') {
                $name = $identifier;
            }

            $profiles[$identifier] = [
                'full_name' => preg_replace('/\s+/', ' ', $name),
                'email' => $emailMatch[0] ?? null,
                'phone' => $phoneMatch[0] ?? null,
            ];
        }

        return $profiles;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function parseMovementFile(array $lines, string $sourceFilename): array
    {
        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = $this->parseMovementLine($line, $index + 1, $sourceFilename);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMovementLine(string $line, int $rowNumber, string $sourceFilename): array
    {
        $matches = [];
        $matched = preg_match(
            '/^(\d{14,16})\s+(\d{1,2})\s+(\d{8})([A-Z0-9]+)\s+(\d+)\s+0([+-])([A-Z0-9]+)\s*$/',
            $line,
            $matches
        );

        if ($matched !== 1) {
            return [
                'row_number' => $rowNumber,
                'source_filename' => $sourceFilename,
                'raw_line' => $line,
                'parse_error' => 'Invalid movement row format',
            ];
        }

        $identifierValue = trim($matches[7]);
        $identifierType = str_starts_with($identifierValue, 'C') ? 'chn' : 'cscs_account_no';
        $tradeDate = Carbon::createFromFormat('Ymd', $matches[3])->toDateString();

        return [
            'row_number' => $rowNumber,
            'source_filename' => $sourceFilename,
            'raw_line' => $line,
            'tran_no' => trim($matches[1]),
            'tran_seq' => trim($matches[2]),
            'trade_date' => $tradeDate,
            'sec_code' => trim($matches[4]),
            'volume' => (float) trim($matches[5]),
            'sign' => trim($matches[6]),
            'identifier_type' => $identifierType,
            'identifier_value' => $identifierValue,
        ];
    }

    /**
     * @param array<string, array<string, string|null>> $masterProfiles
     * @param array<string, int> $counts
     */
    private function processMovementRow(
        CscsUploadBatch $batch,
        array $row,
        array $masterProfiles,
        ?int $defaultRegisterId,
        ?int $uploadedBy,
        array &$counts
    ): void {
        if (isset($row['parse_error'])) {
            CscsUploadRow::create([
                'batch_id' => $batch->id,
                'file_type' => 'movement',
                'source_filename' => $row['source_filename'],
                'row_number' => $row['row_number'],
                'status' => 'failed',
                'error_message' => $row['parse_error'],
                'raw_line' => $row['raw_line'],
            ]);
            $counts['failed_rows']++;
            return;
        }

        if ($row['tran_seq'] === '0') {
            CscsUploadRow::create([
                'batch_id' => $batch->id,
                'file_type' => 'movement',
                'source_filename' => $row['source_filename'],
                'row_number' => $row['row_number'],
                'tran_no' => $row['tran_no'],
                'tran_seq' => $row['tran_seq'],
                'trade_date' => $row['trade_date'],
                'sec_code' => $row['sec_code'],
                'identifier_type' => $row['identifier_type'],
                'identifier_value' => $row['identifier_value'],
                'sign' => $row['sign'],
                'volume' => $row['volume'],
                'status' => 'skipped',
                'error_message' => 'Ignored by rule: TRAN_SEQ=0',
                'raw_line' => $row['raw_line'],
                'extra_details' => ['sec_code' => $row['sec_code']],
            ]);
            $counts['skipped_rows']++;
            return;
        }

        $fingerprint = hash(
            'sha256',
            implode('|', [
                $row['tran_no'],
                $row['tran_seq'],
                $row['trade_date'],
                $row['identifier_type'],
                $row['identifier_value'],
                $row['sign'],
                $row['volume'],
            ])
        );

        if (CscsUploadRow::where('fingerprint', $fingerprint)->exists()) {
            CscsUploadRow::create([
                'batch_id' => $batch->id,
                'file_type' => 'movement',
                'source_filename' => $row['source_filename'],
                'row_number' => $row['row_number'],
                'tran_no' => $row['tran_no'],
                'tran_seq' => $row['tran_seq'],
                'trade_date' => $row['trade_date'],
                'sec_code' => $row['sec_code'],
                'identifier_type' => $row['identifier_type'],
                'identifier_value' => $row['identifier_value'],
                'sign' => $row['sign'],
                'volume' => $row['volume'],
                'status' => 'skipped',
                'error_message' => 'Skipped replay row (already posted earlier)',
                'raw_line' => $row['raw_line'],
                'extra_details' => ['sec_code' => $row['sec_code']],
            ]);
            $counts['replay_rows']++;
            $counts['skipped_rows']++;
            return;
        }

        DB::beginTransaction();
        try {
            [$sra, $shareholder, $matchedBy] = $this->resolveOrCreateAccount(
                $row,
                $masterProfiles,
                $defaultRegisterId,
                $uploadedBy
            );

            $shareClass = $this->resolveShareClass($sra);
            if (! $shareClass) {
                throw ValidationException::withMessages([
                    'share_class_id' => ["No share class found for register ID {$sra->register_id}."],
                ]);
            }

            $delta = $row['sign'] === '+' ? (float) $row['volume'] : (0 - (float) $row['volume']);

            $position = SharePosition::where('sra_id', $sra->id)
                ->where('share_class_id', $shareClass->id)
                ->lockForUpdate()
                ->first();

            $before = $position ? (float) $position->quantity : 0.0;
            $after = $before + $delta;
            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => ["Insufficient balance for {$row['identifier_value']}."],
                ]);
            }

            if (! $position) {
                $position = SharePosition::create([
                    'sra_id' => $sra->id,
                    'share_class_id' => $shareClass->id,
                    'quantity' => 0,
                    'holding_mode' => 'demat',
                ]);
            }

            $position->quantity = (string) $after;
            $position->save();

            $txType = $delta >= 0 ? 'transfer_in' : 'transfer_out';
            $shareTx = ShareTransaction::create([
                'sra_id' => $sra->id,
                'share_class_id' => $shareClass->id,
                'tx_type' => $txType,
                'quantity' => (string) abs($delta),
                'tx_ref' => 'CSCS-' . $row['tran_no'] . '-' . $row['tran_seq'],
                'tx_date' => $row['trade_date'],
                'created_by' => $uploadedBy,
            ]);

            CscsUploadRow::create([
                'batch_id' => $batch->id,
                'file_type' => 'movement',
                'source_filename' => $row['source_filename'],
                'row_number' => $row['row_number'],
                'tran_no' => $row['tran_no'],
                'tran_seq' => $row['tran_seq'],
                'trade_date' => $row['trade_date'],
                'sec_code' => $row['sec_code'],
                'identifier_type' => $row['identifier_type'],
                'identifier_value' => $row['identifier_value'],
                'sign' => $row['sign'],
                'volume' => $row['volume'],
                'status' => 'posted',
                'matched_by' => $matchedBy,
                'before_qty' => $before,
                'delta_qty' => $delta,
                'after_qty' => $after,
                'shareholder_id' => $shareholder->id,
                'sra_id' => $sra->id,
                'share_class_id' => $shareClass->id,
                'share_transaction_id' => $shareTx->id,
                'fingerprint' => $fingerprint,
                'raw_line' => $row['raw_line'],
                'extra_details' => [
                    'sec_code' => $row['sec_code'],
                    'posted_by' => $uploadedBy,
                ],
            ]);

            DB::commit();
            $counts['posted_rows']++;
        } catch (\Throwable $e) {
            DB::rollBack();

            CscsUploadRow::create([
                'batch_id' => $batch->id,
                'file_type' => 'movement',
                'source_filename' => $row['source_filename'],
                'row_number' => $row['row_number'],
                'tran_no' => $row['tran_no'],
                'tran_seq' => $row['tran_seq'],
                'trade_date' => $row['trade_date'],
                'sec_code' => $row['sec_code'],
                'identifier_type' => $row['identifier_type'],
                'identifier_value' => $row['identifier_value'],
                'sign' => $row['sign'],
                'volume' => $row['volume'],
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'raw_line' => $row['raw_line'],
                'extra_details' => ['sec_code' => $row['sec_code']],
            ]);

            $counts['failed_rows']++;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, string|null>> $masterProfiles
     * @return array{0: ShareholderRegisterAccount, 1: Shareholder, 2: string}
     */
    private function resolveOrCreateAccount(
        array $row,
        array $masterProfiles,
        ?int $defaultRegisterId,
        ?int $uploadedBy
    ): array {
        $identifierType = $row['identifier_type'];
        $identifierValue = $row['identifier_value'];

        $sra = ShareholderRegisterAccount::query()
            ->whereHas('externalIdentifiers', function ($q) use ($identifierType, $identifierValue) {
                $q->where('identifier_type', $identifierType)
                    ->where('identifier_value', $identifierValue);
            })
            ->first();

        if ($sra) {
            return [$sra, $sra->shareholder, 'external_identifier'];
        }

        $column = $identifierType === 'chn' ? 'chn' : 'cscs_account_no';
        $sra = ShareholderRegisterAccount::where($column, $identifierValue)->first();
        if ($sra) {
            $this->attachIdentifier($sra, $identifierType, $identifierValue, $uploadedBy);
            return [$sra, $sra->shareholder, $column];
        }

        $profile = $masterProfiles[$identifierValue] ?? null;
        if (! $profile) {
            throw ValidationException::withMessages([
                $column => ["No profile row found in master file for {$identifierValue}."],
            ]);
        }

        $email = $profile['email'] ?? null;
        $phone = $profile['phone'] ?? null;

        if (! $email || ! $phone) {
            throw ValidationException::withMessages([
                'profile' => ["Missing email/phone in master profile for {$identifierValue}."],
            ]);
        }

        $shareholder = Shareholder::where('email', $email)
            ->where('phone', $phone)
            ->first();

        if (! $shareholder) {
            $name = trim($profile['full_name'] ?? 'UNKNOWN SHAREHOLDER');
            $parts = preg_split('/\s+/', $name) ?: [];
            $firstName = array_shift($parts) ?: 'UNKNOWN';
            $lastName = ! empty($parts) ? array_pop($parts) : null;
            $middleName = ! empty($parts) ? implode(' ', $parts) : null;

            $shareholder = Shareholder::create([
                'account_no' => $this->accountNumberService->generate(),
                'holder_type' => 'individual',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'middle_name' => $middleName,
                'full_name' => $name,
                'email' => $email,
                'phone' => $phone,
                'status' => 'active',
            ]);
        }

        $registerId = $defaultRegisterId;
        if (! $registerId) {
            throw ValidationException::withMessages([
                'register_id' => ['register_id is required for unmatched rows.'],
            ]);
        }

        $sra = ShareholderRegisterAccount::firstOrCreate(
            ['shareholder_id' => $shareholder->id, 'register_id' => $registerId],
            [
                'shareholder_no' => ShareholderRegisterAccount::generateAccountNumber($shareholder->id),
                'status' => 'active',
            ]
        );

        if ($identifierType === 'chn' && empty($sra->chn)) {
            $sra->chn = $identifierValue;
            $sra->save();
        }
        if ($identifierType === 'cscs_account_no' && empty($sra->cscs_account_no)) {
            $sra->cscs_account_no = $identifierValue;
            $sra->save();
        }

        $this->attachIdentifier($sra, $identifierType, $identifierValue, $uploadedBy);

        return [$sra, $shareholder, 'email_phone'];
    }

    private function resolveShareClass(ShareholderRegisterAccount $sra): ?ShareClass
    {
        $position = SharePosition::where('sra_id', $sra->id)->orderBy('id')->first();
        if ($position) {
            return ShareClass::find($position->share_class_id);
        }

        return ShareClass::where('register_id', $sra->register_id)->orderBy('id')->first();
    }

    private function attachIdentifier(
        ShareholderRegisterAccount $sra,
        string $identifierType,
        string $identifierValue,
        ?int $uploadedBy
    ): void {
        SraExternalIdentifier::firstOrCreate(
            [
                'identifier_type' => $identifierType,
                'identifier_value' => $identifierValue,
            ],
            [
                'sra_id' => $sra->id,
                'source' => 'cscs_upload',
                'created_by' => $uploadedBy,
            ]
        );
    }
}

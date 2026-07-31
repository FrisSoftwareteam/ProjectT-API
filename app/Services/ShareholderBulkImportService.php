<?php

namespace App\Services;

use App\Models\ShareClass;
use App\Models\Shareholder;
use App\Models\ShareholderAddress;
use App\Models\ShareholderCategory;
use App\Models\ShareholderImportBatch;
use App\Models\ShareholderImportRow;
use App\Models\ShareholderMandate;
use App\Models\ShareholderRegisterAccount;
use App\Models\ShareLot;
use App\Models\SharePosition;
use App\Models\ShareTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShareholderBulkImportService
{
    private const REQUIRED_HEADERS = [
        'holder_type',
        'first_name',
        'email',
        'phone',
        'status',
        'address_line1',
        'register_id',
        'share_class_id',
        'quantity',
    ];

    public function __construct(
        private readonly ShareholderAccountNumberService $accountNumberService,
        private readonly CapitalValidationService $capitalValidationService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFile $file, ?int $uploadedBy): array
    {
        $storedPath = $file->storeAs(
            'shareholder_imports',
            now()->format('Ymd_His_u') . '_' . $file->getClientOriginalName()
        );

        $batch = ShareholderImportBatch::create([
            'uploaded_by' => $uploadedBy,
            'status' => 'processing',
            'source_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'summary' => $this->emptySummary(),
        ]);

        $summary = $this->emptySummary();
        $touchedRegisterIds = [];

        try {
            [$headers, $rows] = $this->readCsv((string) Storage::get($storedPath));
            $this->assertRequiredHeaders($headers);

            foreach ($rows as $rowNumber => $row) {
                $summary['total_rows']++;
                $importRow = ShareholderImportRow::create([
                    'batch_id' => $batch->id,
                    'row_number' => $rowNumber,
                    'status' => 'pending',
                    'raw_data' => $row,
                ]);

                $result = $this->processRow($importRow, $row, $uploadedBy);

                if ($result['status'] === 'posted') {
                    $summary['posted_rows']++;
                    $touchedRegisterIds[(int) $result['register_id']] = true;
                    continue;
                }

                $summary['failed_rows']++;
            }

            foreach (array_keys($touchedRegisterIds) as $registerId) {
                $this->capitalValidationService->syncOutstandingUnits((int) $registerId);
            }

            $batchStatus = $summary['failed_rows'] > 0 ? 'completed_with_errors' : 'completed';
            $batch->update([
                'status' => $batchStatus,
                'summary' => $summary,
            ]);

            return $this->result($batch->fresh(), $summary);
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'summary' => array_merge($summary, ['fatal_error' => $e->getMessage()]),
            ]);

            throw $e;
        }
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<string, string|null>>}
     */
    private function readCsv(string $contents): array
    {
        $handle = fopen('php://temp', 'rb+');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['Unable to read uploaded shareholder import file.'],
            ]);
        }

        fwrite($handle, $contents);
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, ',', '"', '');
        if ($headerRow === false) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => ['The shareholder import file is empty.'],
            ]);
        }

        $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), $headerRow);
        $rows = [];
        $rowNumber = 1;

        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rowNumber++;
            if ($this->isEmptyRow($values)) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $values[$index] ?? null;
                $row[$header] = is_string($value) ? trim($value) : $value;
            }

            $rows[$rowNumber] = $row;
        }

        fclose($handle);

        return [$headers, $rows];
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;

        return trim($header, '_');
    }

    /**
     * @param array<int, string|null> $values
     */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $headers
     */
    private function assertRequiredHeaders(array $headers): void
    {
        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => ['Missing required CSV columns: ' . implode(', ', $missing)],
            ]);
        }
    }

    /**
     * @param array<string, string|null> $row
     * @return array{status: string, register_id?: int}
     */
    private function processRow(ShareholderImportRow $importRow, array $row, ?int $uploadedBy): array
    {
        try {
            $validated = $this->validateRow($row);
            $result = DB::transaction(function () use ($validated, $uploadedBy) {
                $shareholder = Shareholder::create([
                    'account_no' => $this->accountNumberService->generate(),
                    'holder_type' => $validated['holder_type'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'] ?? null,
                    'middle_name' => $validated['middle_name'] ?? null,
                    'full_name' => $this->fullName($validated),
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'sex' => $validated['sex'] ?? null,
                    'rc_number' => $validated['rc_number'] ?? null,
                    'nin' => $validated['nin'] ?? null,
                    'bvn' => $validated['bvn'] ?? null,
                    'tax_id' => $validated['tax_id'] ?? null,
                    'next_of_kin_name' => $validated['next_of_kin_name'] ?? null,
                    'next_of_kin_phone' => $validated['next_of_kin_phone'] ?? null,
                    'next_of_kin_relationship' => $validated['next_of_kin_relationship'] ?? null,
                    'status' => $validated['status'],
                ]);

                ShareholderAddress::create([
                    'shareholder_id' => $shareholder->id,
                    'address_line1' => $validated['address_line1'],
                    'address_line2' => $validated['address_line2'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'state' => $validated['state'] ?? null,
                    'postal_code' => $validated['postal_code'] ?? null,
                    'country' => $validated['country'] ?? 'Nigeria',
                    'is_primary' => true,
                    'valid_from' => $validated['address_valid_from'] ?? null,
                    'valid_to' => $validated['address_valid_to'] ?? null,
                ]);

                if ($this->hasMandate($validated)) {
                    ShareholderMandate::create([
                        'shareholder_id' => $shareholder->id,
                        'bank_name' => $validated['bank_name'],
                        'account_name' => $validated['account_name'],
                        'account_number' => $validated['bank_account_number'],
                        'bvn' => $validated['mandate_bvn'] ?? null,
                        'status' => $validated['mandate_status'] ?? 'pending',
                    ]);
                }

                $shareClass = ShareClass::findOrFail((int) $validated['share_class_id']);
                $registerId = (int) $validated['register_id'];
                if ((int) $shareClass->register_id !== $registerId) {
                    throw ValidationException::withMessages([
                        'share_class_id' => ['share_class_id does not belong to register_id.'],
                    ]);
                }

                $sra = ShareholderRegisterAccount::create([
                    'shareholder_id' => $shareholder->id,
                    'register_id' => $registerId,
                    'shareholder_category_id' => isset($validated['shareholder_category_code'])
                        ? ShareholderCategory::query()->where('code', $validated['shareholder_category_code'])->value('id')
                        : null,
                    'shareholder_no' => $validated['shareholder_no'] ?? ShareholderRegisterAccount::generateAccountNumber($shareholder->id),
                    'chn' => $validated['chn'] ?? null,
                    'cscs_account_no' => $validated['cscs_account_no'] ?? null,
                    'residency_status' => $validated['residency_status'] ?? 'resident',
                    'kyc_level' => $validated['kyc_level'] ?? 'basic',
                    'status' => $validated['register_account_status'] ?? 'active',
                ]);

                $quantity = (string) $validated['quantity'];
                $position = SharePosition::create([
                    'sra_id' => $sra->id,
                    'share_class_id' => $shareClass->id,
                    'quantity' => $quantity,
                    'holding_mode' => $validated['holding_mode'] ?? 'demat',
                    'last_updated_at' => now(),
                ]);

                $sourceType = $validated['source_type'] ?? 'allotment';
                $lotRef = $validated['lot_ref'] ?? ('IMPORT-' . strtoupper(Str::random(10)));
                $acquiredAt = $validated['acquired_at'] ?? now();

                $lot = ShareLot::create([
                    'sra_id' => $sra->id,
                    'share_class_id' => $shareClass->id,
                    'lot_ref' => $lotRef,
                    'source_type' => $sourceType,
                    'quantity' => $quantity,
                    'acquired_at' => $acquiredAt,
                ]);

                $transaction = ShareTransaction::create([
                    'sra_id' => $sra->id,
                    'share_class_id' => $shareClass->id,
                    'tx_type' => $this->transactionTypeForSource($sourceType),
                    'quantity' => $quantity,
                    'tx_ref' => $lotRef,
                    'tx_date' => $acquiredAt,
                    'created_by' => $uploadedBy,
                ]);

                return [
                    'shareholder' => $shareholder,
                    'sra' => $sra,
                    'position' => $position,
                    'lot' => $lot,
                    'transaction' => $transaction,
                    'register_id' => $registerId,
                ];
            });

            $importRow->update([
                'status' => 'posted',
                'shareholder_id' => $result['shareholder']->id,
                'shareholder_register_account_id' => $result['sra']->id,
                'share_position_id' => $result['position']->id,
                'share_lot_id' => $result['lot']->id,
                'share_transaction_id' => $result['transaction']->id,
            ]);

            return ['status' => 'posted', 'register_id' => $result['register_id']];
        } catch (ValidationException $e) {
            $this->markFailed($importRow, $e->errors());

            return ['status' => 'failed'];
        } catch (\Throwable $e) {
            $this->markFailed($importRow, ['row' => [$e->getMessage()]]);

            return ['status' => 'failed'];
        }
    }

    /**
     * @param array<string, string|null> $row
     * @return array<string, mixed>
     */
    private function validateRow(array $row): array
    {
        $row = $this->normalizeEmptyValues($row);
        if (! empty($row['shareholder_category_code'])) {
            $row['shareholder_category_code'] = strtoupper((string) $row['shareholder_category_code']);
        }
        $rules = [
            'holder_type' => ['required', 'in:individual,corporate'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', Rule::unique('shareholders', 'email')],
            'phone' => ['required', 'string', 'max:32', Rule::unique('shareholders', 'phone')],
            'date_of_birth' => ['nullable', 'date'],
            'sex' => ['nullable', 'in:male,female,other'],
            'rc_number' => ['nullable', 'string', 'max:50'],
            'nin' => ['nullable', 'string', 'max:20'],
            'bvn' => ['nullable', 'string', 'max:20'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'next_of_kin_name' => ['nullable', 'string', 'max:255'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:32'],
            'next_of_kin_relationship' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:active,dormant,deceased,closed'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'address_valid_from' => ['nullable', 'date'],
            'address_valid_to' => ['nullable', 'date'],
            'register_id' => ['required', 'integer', 'exists:registers,id'],
            'shareholder_category_code' => [
                'nullable',
                'string',
                'max:10',
                Rule::exists('shareholder_categories', 'code')->where(
                    fn ($query) => $query->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'shareholder_no' => ['nullable', 'string', 'max:30'],
            'chn' => ['nullable', 'string', 'max:50'],
            'cscs_account_no' => ['nullable', 'string', 'max:50'],
            'residency_status' => ['nullable', 'in:resident,non_resident'],
            'kyc_level' => ['nullable', 'in:basic,standard,enhanced'],
            'register_account_status' => ['nullable', 'in:active,suspended,closed'],
            'share_class_id' => ['required', 'integer', 'exists:share_classes,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'holding_mode' => ['nullable', 'in:demat,paper'],
            'source_type' => ['nullable', 'in:allotment,bonus,rights,transfer_in,demat_in,certificate_deposit'],
            'lot_ref' => ['nullable', 'string', 'max:64'],
            'acquired_at' => ['nullable', 'date'],
        ];

        if ($this->hasMandate($row)) {
            $rules['bank_name'] = ['required', 'string', 'max:150'];
            $rules['account_name'] = ['required', 'string', 'max:255'];
            $rules['bank_account_number'] = ['required', 'string', 'max:20'];
            $rules['mandate_bvn'] = ['nullable', 'string', 'max:20'];
            $rules['mandate_status'] = ['nullable', 'in:pending,verified,active,rejected,revoked'];
        } else {
            $rules['bank_name'] = ['nullable', 'string', 'max:150'];
            $rules['account_name'] = ['nullable', 'string', 'max:255'];
            $rules['bank_account_number'] = ['nullable', 'string', 'max:20'];
            $rules['mandate_bvn'] = ['nullable', 'string', 'max:20'];
            $rules['mandate_status'] = ['nullable', 'in:pending,verified,active,rejected,revoked'];
        }

        $validator = Validator::make($row, $rules);
        $validator->after(function ($validator) use ($row) {
            if (($row['holder_type'] ?? null) === 'individual' && empty($row['last_name'])) {
                $validator->errors()->add('last_name', 'Last name is required for individual shareholders.');
            }

            if (! empty($row['shareholder_category_code']) && ! empty($row['holder_type'])) {
                $category = ShareholderCategory::query()
                    ->where('code', $row['shareholder_category_code'])
                    ->first();
                if ($category && ! $category->isCompatibleWith($row['holder_type'])) {
                    $validator->errors()->add(
                        'shareholder_category_code',
                        "Category {$category->code} requires holder type {$category->default_holder_type}."
                    );
                }
            }
        });
        $validator->validate();

        return $validator->validated();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fullName(array $data): string
    {
        return trim(implode(' ', array_filter([
            $data['first_name'] ?? null,
            $data['middle_name'] ?? null,
            $data['last_name'] ?? null,
        ])));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasMandate(array $row): bool
    {
        foreach (['bank_name', 'account_name', 'bank_account_number', 'mandate_bvn', 'mandate_status'] as $field) {
            if (! empty($row[$field])) {
                return true;
            }
        }

        return false;
    }

    private function transactionTypeForSource(string $sourceType): string
    {
        return match ($sourceType) {
            'allotment' => 'allot',
            'certificate_deposit' => 'demat_in',
            default => $sourceType,
        };
    }

    /**
     * @param array<string, string|null> $row
     * @return array<string, string|null>
     */
    private function normalizeEmptyValues(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $row[$key] = null;
            }
        }

        return $row;
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    private function markFailed(ShareholderImportRow $importRow, array $errors): void
    {
        $firstField = array_key_first($errors);
        $firstMessage = $firstField ? ($errors[$firstField][0] ?? 'Row failed validation.') : 'Row failed validation.';

        $importRow->update([
            'status' => 'failed',
            'errors' => $errors,
            'error_message' => $firstMessage,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary(): array
    {
        return [
            'total_rows' => 0,
            'posted_rows' => 0,
            'failed_rows' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function result(ShareholderImportBatch $batch, array $summary): array
    {
        $failedRows = ShareholderImportRow::where('batch_id', $batch->id)
            ->where('status', 'failed')
            ->orderBy('row_number')
            ->limit(25)
            ->get(['row_number', 'status', 'error_message', 'errors']);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'summary' => $summary,
            'failed_rows_preview' => $failedRows,
        ];
    }
}

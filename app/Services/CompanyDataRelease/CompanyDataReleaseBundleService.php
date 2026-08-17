<?php

namespace App\Services\CompanyDataRelease;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRecord;
use Closure;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class CompanyDataReleaseBundleService
{
    public const FORMAT = 'projectt-company-data-release-v1';

    /** @return array<string, mixed> */
    public function export(LegacyMigrationBatch $batch, string $outputDirectory, ?string $name = null): array
    {
        $batch->loadMissing(['register.company', 'shareClass']);
        if ($batch->status !== LegacyMigrationBatch::PUBLISHED) {
            throw new RuntimeException('Only a fully published and reconciled workstation batch can be exported.');
        }
        if (! $batch->approval_snapshot_hash) {
            throw new RuntimeException('The workstation batch does not have an approved snapshot hash.');
        }
        $publishedRows = LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'PUBLISHED')->count();
        if ($publishedRows !== (int) $batch->expected_rows) {
            throw new RuntimeException("The workstation batch has {$publishedRows} published rows; {$batch->expected_rows} were expected.");
        }

        $issuerCode = (string) $batch->register->company->issuer_code;
        $registerCode = (string) $batch->register->register_code;
        $shareClassCode = (string) $batch->shareClass->class_code;
        $releaseId = hash('sha256', implode('|', [
            self::FORMAT,
            $batch->package_key,
            $batch->package_version,
            $batch->source_sha256,
            $batch->approval_snapshot_hash,
            $issuerCode,
            $registerCode,
            $shareClassCode,
        ]));
        $name ??= strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $issuerCode.'-'.$registerCode.'-'.substr($releaseId, 0, 12)));
        $this->assertSafeName($name);
        $outputDirectory = $this->absolutePath($outputDirectory);
        $this->ensureDirectory($outputDirectory);

        $temporaryRoot = sys_get_temp_dir().'/projectt-company-export-'.bin2hex(random_bytes(8));
        $bundleRoot = $temporaryRoot.'/'.$name;
        $this->ensureDirectory($bundleRoot);

        try {
            $recordsPath = $bundleRoot.'/records.jsonl.gz';
            $handle = gzopen($recordsPath, 'wb9');
            if ($handle === false) {
                throw new RuntimeException('Could not create the compressed company record stream.');
            }

            $summary = [
                'rows' => 0,
                'quantity' => '0.000000',
                'categories' => [],
                'holder_types' => [],
                'holding_modes' => [],
            ];
            try {
                LegacyMigrationRecord::where('batch_id', $batch->id)
                    ->where('status', 'PUBLISHED')
                    ->orderBy('id')
                    ->lazyById(2000)
                    ->each(function (LegacyMigrationRecord $record) use ($handle, &$summary): void {
                        $normalized = $record->normalized_data;
                        $payload = [
                            'source_row_number' => (int) $record->source_row_number,
                            'source_key_hash' => $record->source_key_hash,
                            'source_account_number' => $record->source_account_number,
                            'source_row_hash' => $record->row_hash,
                            'idempotency_key' => $record->idempotency_key,
                            'target_account_no' => $record->target_account_no,
                            'target_email' => $record->target_email,
                            'target_phone' => $record->target_phone,
                            'holder_type' => $record->holder_type,
                            'category_code' => $record->category_code,
                            'quantity' => FixedScaleDecimal::normalize((string) $record->quantity),
                            'holding_mode' => $record->holding_mode,
                            'full_name' => $normalized['full_name'],
                            'address_line1' => $normalized['address_line1'],
                            'state' => $normalized['state'],
                            'country' => $normalized['country'],
                            'status' => $normalized['status'],
                        ];
                        $payload['payload_sha256'] = hash('sha256', $this->encode($payload));
                        $line = $this->encode($payload)."\n";
                        if (gzwrite($handle, $line) !== strlen($line)) {
                            throw new RuntimeException('Could not write the complete company release record stream.');
                        }

                        $summary['rows']++;
                        $summary['quantity'] = FixedScaleDecimal::add($summary['quantity'], $payload['quantity']);
                        $this->addSummary($summary['categories'], $payload['category_code'], $payload['quantity']);
                        $this->addSummary($summary['holder_types'], $payload['holder_type'], $payload['quantity']);
                        $this->addSummary($summary['holding_modes'], $payload['holding_mode'], $payload['quantity']);
                    });
            } finally {
                gzclose($handle);
            }

            $this->sortSummary($summary);
            if ($summary['rows'] !== (int) $batch->expected_rows) {
                throw new RuntimeException('Exported record count does not match the approved workstation batch.');
            }
            if (! FixedScaleDecimal::equals($summary['quantity'], (string) $batch->expected_quantity)) {
                throw new RuntimeException('Exported unit quantity does not match the approved workstation batch.');
            }

            $recordsHash = hash_file('sha256', $recordsPath);
            $manifest = [
                'format' => self::FORMAT,
                'format_version' => '1.0.0',
                'bundle_release_id' => $releaseId,
                'bundle_name' => $name,
                'created_at_utc' => gmdate(DATE_ATOM),
                'source' => [
                    'filename' => $batch->source_filename,
                    'sha256' => $batch->source_sha256,
                    'package_key' => $batch->package_key,
                    'package_version' => $batch->package_version,
                    'approved_snapshot_sha256' => $batch->approval_snapshot_hash,
                ],
                'target' => [
                    'issuer_code' => $issuerCode,
                    'register_code' => $registerCode,
                    'share_class_code' => $shareClassCode,
                ],
                'controls' => [
                    'contact_policy' => 'unique_deterministic_unverified_placeholders',
                    'contacts_verified' => false,
                    'contacts_suppressed' => true,
                    'requires_empty_share_class' => true,
                ],
                'summary' => $summary,
                'records' => [
                    'filename' => 'records.jsonl.gz',
                    'sha256' => $recordsHash,
                    'compressed_bytes' => filesize($recordsPath),
                    'encoding' => 'json-lines',
                    'compression' => 'gzip',
                ],
                'workstation_evidence' => [
                    'batch_public_id' => $batch->public_id,
                    'batch_attempt_no' => (int) $batch->attempt_no,
                    'reconciliation' => $batch->reconciliation,
                ],
            ];
            $manifestPath = $bundleRoot.'/manifest.json';
            file_put_contents($manifestPath, $this->encodePretty($manifest)."\n");
            $manifestHash = hash_file('sha256', $manifestPath);

            $tarPath = $outputDirectory.'/'.$name.'.tar';
            $archivePath = $tarPath.'.gz';
            $externalManifestPath = $outputDirectory.'/'.$name.'.manifest.json';
            $checksumPath = $outputDirectory.'/'.$name.'.sha256';
            foreach ([$tarPath, $archivePath, $externalManifestPath, $checksumPath] as $target) {
                if (file_exists($target)) {
                    throw new RuntimeException("Refusing to overwrite an existing company release: {$target}");
                }
            }

            $archive = new PharData($tarPath);
            $archive->buildFromDirectory($temporaryRoot);
            unset($archive);
            $this->gzipFile($tarPath, $archivePath);
            unlink($tarPath);

            $artifactHash = hash_file('sha256', $archivePath);
            $external = [
                'format' => self::FORMAT,
                'bundle_release_id' => $releaseId,
                'bundle_name' => $name,
                'artifact_filename' => basename($archivePath),
                'artifact_sha256' => $artifactHash,
                'artifact_bytes' => filesize($archivePath),
                'manifest_sha256' => $manifestHash,
                'records_sha256' => $recordsHash,
                'summary' => $summary,
            ];
            file_put_contents($externalManifestPath, $this->encodePretty($external)."\n");
            file_put_contents($checksumPath, $artifactHash.'  '.basename($archivePath).PHP_EOL);
            foreach ([$archivePath, $externalManifestPath, $checksumPath] as $releaseFile) {
                chmod($releaseFile, 0600);
            }

            return $external + [
                'archive_path' => $archivePath,
                'external_manifest_path' => $externalManifestPath,
                'checksum_path' => $checksumPath,
            ];
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot, 'projectt-company-export-');
        }
    }

    /** @return array<string, mixed> */
    public function inspect(string $archivePath): array
    {
        return $this->consume($archivePath, fn (array $bundle): array => $bundle['inspection']);
    }

    /** @template T @param Closure(array<string,mixed>):T $callback @return T */
    public function consume(string $archivePath, Closure $callback): mixed
    {
        $archivePath = $this->absolutePath($archivePath);
        if (! is_file($archivePath) || ! str_ends_with($archivePath, '.tar.gz')) {
            throw new RuntimeException('The company release archive is missing or is not a .tar.gz file.');
        }
        $artifactHash = hash_file('sha256', $archivePath);
        $temporaryRoot = sys_get_temp_dir().'/projectt-company-verify-'.bin2hex(random_bytes(8));
        $this->ensureDirectory($temporaryRoot);

        try {
            [$manifestPath, $recordsPath] = $this->extractDeclaredTarFiles($archivePath, $temporaryRoot);

            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertManifest($manifest, $recordsPath);
            $inspection = $this->scanRecords($recordsPath, $manifest);

            return $callback([
                'archive_path' => $archivePath,
                'artifact_sha256' => $artifactHash,
                'artifact_size' => filesize($archivePath),
                'manifest' => $manifest,
                'manifest_sha256' => hash_file('sha256', $manifestPath),
                'records_path' => $recordsPath,
                'inspection' => $inspection,
            ]);
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot, 'projectt-company-verify-');
        }
    }

    /** @return array{0:string,1:string} */
    private function extractDeclaredTarFiles(string $archivePath, string $temporaryRoot): array
    {
        $input = gzopen($archivePath, 'rb');
        if ($input === false) {
            throw new RuntimeException('Could not open the compressed company release archive.');
        }
        $files = [];
        try {
            while (true) {
                $header = $this->gzReadExact($input, 512);
                if ($header === null) {
                    throw new RuntimeException('The company release TAR archive is truncated.');
                }
                if ($header === str_repeat("\0", 512)) {
                    if ($this->gzReadExact($input, 512) !== str_repeat("\0", 512)) {
                        throw new RuntimeException('The company release TAR end marker is invalid.');
                    }
                    break;
                }
                $storedChecksum = octdec(trim(substr($header, 148, 8), " \0"));
                $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
                $calculatedChecksum = array_sum(unpack('C*', $checksumHeader));
                if ($storedChecksum !== $calculatedChecksum) {
                    throw new RuntimeException('The company release contains an invalid TAR header checksum.');
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $path = $prefix === '' ? $name : $prefix.'/'.$name;
                $type = substr($header, 156, 1);
                $sizeValue = trim(substr($header, 124, 12), " \0");
                if ($sizeValue !== '' && ! preg_match('/\A[0-7]+\z/', $sizeValue)) {
                    throw new RuntimeException('The company release contains an invalid TAR entry size.');
                }
                $size = $sizeValue === '' ? 0 : octdec($sizeValue);
                if (! in_array($type, ["\0", '0'], true)
                    || ! preg_match('/\A[a-zA-Z0-9._-]+\/(manifest\.json|records\.jsonl\.gz)\z/', $path)
                    || isset($files[$path])) {
                    throw new RuntimeException('The company release contains an undeclared, duplicate, or unsafe TAR entry.');
                }
                if ($size > (int) config('company_data_releases.maximum_uncompressed_record_bytes')) {
                    throw new RuntimeException('A company release archive entry exceeds the configured safety limit.');
                }

                $topLevelDirectory = explode('/', $path, 2)[0];
                $bundleRoot = $temporaryRoot.'/'.$topLevelDirectory;
                $this->ensureDirectory($bundleRoot);
                $destination = $bundleRoot.'/'.basename($path);
                $output = fopen($destination, 'xb');
                if ($output === false) {
                    throw new RuntimeException('Could not create a verified company release file.');
                }
                try {
                    $remaining = $size;
                    while ($remaining > 0) {
                        $chunk = gzread($input, min(1048576, $remaining));
                        if ($chunk === false || $chunk === '') {
                            throw new RuntimeException('The company release TAR entry is truncated.');
                        }
                        if (fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('Could not write a complete company release file.');
                        }
                        $remaining -= strlen($chunk);
                    }
                } finally {
                    fclose($output);
                }
                $padding = (512 - ($size % 512)) % 512;
                if ($padding > 0 && $this->gzReadExact($input, $padding) === null) {
                    throw new RuntimeException('The company release TAR padding is truncated.');
                }
                $files[$path] = $destination;
            }
        } finally {
            gzclose($input);
        }

        if (count($files) !== 2) {
            throw new RuntimeException('The company release must contain exactly two declared files.');
        }
        $topLevelDirectories = array_values(array_unique(array_map(
            fn (string $path): string => explode('/', $path, 2)[0],
            array_keys($files)
        )));
        if (count($topLevelDirectories) !== 1) {
            throw new RuntimeException('The company release must contain exactly one top-level directory.');
        }
        $root = $topLevelDirectories[0];
        if (! isset($files[$root.'/manifest.json'], $files[$root.'/records.jsonl.gz'])) {
            throw new RuntimeException('The company release is missing a required file.');
        }

        return [$files[$root.'/manifest.json'], $files[$root.'/records.jsonl.gz']];
    }

    /** @param resource $handle */
    private function gzReadExact($handle, int $length): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length && ! gzeof($handle)) {
            $chunk = gzread($handle, $length - strlen($buffer));
            if ($chunk === false) {
                throw new RuntimeException('Could not read the compressed company release archive.');
            }
            $buffer .= $chunk;
        }

        return strlen($buffer) === $length ? $buffer : null;
    }

    private function gzipFile(string $source, string $destination): void
    {
        $input = fopen($source, 'rb');
        $output = gzopen($destination, 'wb9');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }
            throw new RuntimeException('Could not create the compressed company release archive.');
        }
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Could not read the company release TAR archive.');
                }
                if ($chunk !== '' && gzwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not write the complete compressed company release archive.');
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }
    }

    /** @return \Generator<int, array<string,mixed>> */
    public function records(string $recordsPath): \Generator
    {
        $handle = gzopen($recordsPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the company release record stream.');
        }
        try {
            $lineNumber = 0;
            while (! gzeof($handle)) {
                $line = gzgets($handle);
                if ($line === false || trim($line) === '') {
                    continue;
                }
                $lineNumber++;
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $this->assertRecord($record, $lineNumber);
                yield $lineNumber => $record;
            }
        } finally {
            gzclose($handle);
        }
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function scanRecords(string $recordsPath, array $manifest): array
    {
        $summary = ['rows' => 0, 'quantity' => '0.000000', 'categories' => [], 'holder_types' => [], 'holding_modes' => []];
        $uncompressedBytes = 0;
        foreach ($this->records($recordsPath) as $record) {
            $uncompressedBytes += strlen($this->encode($record)) + 1;
            if ($uncompressedBytes > (int) config('company_data_releases.maximum_uncompressed_record_bytes')) {
                throw new RuntimeException('The uncompressed company release exceeds the configured safety limit.');
            }
            $summary['rows']++;
            $summary['quantity'] = FixedScaleDecimal::add($summary['quantity'], $record['quantity']);
            $this->addSummary($summary['categories'], $record['category_code'], $record['quantity']);
            $this->addSummary($summary['holder_types'], $record['holder_type'], $record['quantity']);
            $this->addSummary($summary['holding_modes'], $record['holding_mode'], $record['quantity']);
        }
        $this->sortSummary($summary);
        if ($summary !== $manifest['summary']) {
            throw new RuntimeException('The record stream totals differ from the signed company release manifest.');
        }

        return $summary + [
            'artifact_records_sha256' => hash_file('sha256', $recordsPath),
            'uncompressed_record_bytes' => $uncompressedBytes,
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifest(array $manifest, string $recordsPath): void
    {
        if (($manifest['format'] ?? null) !== self::FORMAT || ($manifest['format_version'] ?? null) !== '1.0.0') {
            throw new RuntimeException('Unsupported company release format.');
        }
        foreach (['bundle_release_id', 'bundle_name', 'source', 'target', 'controls', 'summary', 'records'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new RuntimeException("Company release manifest is missing {$key}.");
            }
        }
        foreach ([$manifest['bundle_release_id'], $manifest['source']['sha256'] ?? '', $manifest['source']['approved_snapshot_sha256'] ?? '', $manifest['records']['sha256'] ?? ''] as $hash) {
            if (! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                throw new RuntimeException('Company release manifest contains an invalid SHA-256 value.');
            }
        }
        foreach (['issuer_code', 'register_code', 'share_class_code'] as $key) {
            if (! is_string($manifest['target'][$key] ?? null) || trim($manifest['target'][$key]) === '') {
                throw new RuntimeException("Company release target {$key} is missing.");
            }
        }
        if (($manifest['records']['filename'] ?? null) !== 'records.jsonl.gz'
            || ($manifest['records']['compression'] ?? null) !== 'gzip'
            || (int) ($manifest['records']['compressed_bytes'] ?? -1) !== filesize($recordsPath)
            || ! hash_equals($manifest['records']['sha256'], hash_file('sha256', $recordsPath))) {
            throw new RuntimeException('Company release record-file metadata is invalid.');
        }
        if (($manifest['controls']['contacts_verified'] ?? true) !== false
            || ($manifest['controls']['contacts_suppressed'] ?? false) !== true
            || ($manifest['controls']['requires_empty_share_class'] ?? false) !== true) {
            throw new RuntimeException('Company release contact or empty-target controls are not safe.');
        }
    }

    /** @param array<string,mixed> $record */
    private function assertRecord(array $record, int $lineNumber): void
    {
        $required = [
            'source_row_number', 'source_key_hash', 'source_account_number', 'source_row_hash',
            'idempotency_key', 'target_account_no', 'target_email', 'target_phone',
            'holder_type', 'category_code', 'quantity', 'holding_mode', 'full_name',
            'address_line1', 'state', 'country', 'status', 'payload_sha256',
        ];
        if (array_keys($record) !== $required) {
            throw new RuntimeException("Record {$lineNumber} has an unexpected structure or field order.");
        }
        if ((int) $record['source_row_number'] !== $lineNumber) {
            throw new RuntimeException("Record {$lineNumber} has a non-sequential source row number.");
        }
        foreach (['source_key_hash', 'source_row_hash', 'idempotency_key', 'payload_sha256'] as $field) {
            if (! is_string($record[$field]) || ! preg_match('/\A[a-f0-9]{64}\z/', $record[$field])) {
                throw new RuntimeException("Record {$lineNumber} contains an invalid {$field}.");
            }
        }
        $payloadHash = $record['payload_sha256'];
        unset($record['payload_sha256']);
        if (! hash_equals($payloadHash, hash('sha256', $this->encode($record)))) {
            throw new RuntimeException("Record {$lineNumber} payload checksum is invalid.");
        }
        if (! in_array($record['holder_type'], ['individual', 'corporate'], true)
            || ! in_array($record['holding_mode'], ['paper', 'demat'], true)
            || ! in_array($record['status'], ['active', 'dormant', 'deceased', 'closed'], true)) {
            throw new RuntimeException("Record {$lineNumber} contains an unsupported status or type.");
        }
        foreach (['source_account_number', 'target_account_no', 'target_email', 'target_phone', 'category_code', 'full_name', 'address_line1', 'country'] as $field) {
            if (! is_string($record[$field]) || trim($record[$field]) === '') {
                throw new RuntimeException("Record {$lineNumber} contains an empty {$field}.");
            }
        }
        if (strlen($record['source_account_number']) > 30 || strlen($record['target_account_no']) > 20
            || strlen($record['target_email']) > 255 || strlen($record['target_phone']) > 32
            || strlen($record['full_name']) > 255 || strlen($record['address_line1']) > 255
            || strlen((string) ($record['state'] ?? '')) > 100 || strlen($record['country']) > 100) {
            throw new RuntimeException("Record {$lineNumber} exceeds a target field length.");
        }
        if (! FixedScaleDecimal::isPositive((string) $record['quantity'])) {
            throw new RuntimeException("Record {$lineNumber} has a non-positive quantity.");
        }
    }

    /** @param array<string,array{rows:int,quantity:string}> $summary */
    private function addSummary(array &$summary, string $key, string $quantity): void
    {
        $summary[$key] ??= ['rows' => 0, 'quantity' => '0.000000'];
        $summary[$key]['rows']++;
        $summary[$key]['quantity'] = FixedScaleDecimal::add($summary[$key]['quantity'], $quantity);
    }

    /** @param array<string,mixed> $summary */
    private function sortSummary(array &$summary): void
    {
        foreach (['categories', 'holder_types', 'holding_modes'] as $key) {
            ksort($summary[$key], SORT_STRING);
        }
    }

    /** @return array<int,string> */
    private function relativeFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Symbolic links are not permitted in company releases.');
            }
            if ($item->isFile()) {
                $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            }
        }

        return $files;
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function encodePretty(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function assertSafeName(string $name): void
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}\z/', $name)) {
            throw new RuntimeException('Company release name contains unsafe characters.');
        }
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create directory: {$directory}");
        }
    }

    private function removeTemporaryDirectory(string $directory, string $prefix): void
    {
        if (! is_dir($directory) || ! str_starts_with($directory, sys_get_temp_dir().'/'.$prefix)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}

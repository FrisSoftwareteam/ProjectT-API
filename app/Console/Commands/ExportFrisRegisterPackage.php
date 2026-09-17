<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;
use ZipArchive;

class ExportFrisRegisterPackage extends Command
{
    protected $signature = 'fris:export-register-package
        {register_code : FRIS register code to export}
        {path=FRIS.sqlite : Path to the FRIS SQLite database}
        {--output=storage/app/fris-packages : Output directory}
        {--limit=0 : Optional row limit for pilot packages}';

    protected $description = 'Export one FRIS register from SQLite into a portable ZIP package';

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

        $outputDir = base_path((string) $this->option('output'));
        File::ensureDirectoryExists($outputDir);
        $workDir = $outputDir.'/register_'.$registerCode.'_'.now()->format('Ymd_His');
        File::ensureDirectoryExists($workDir);

        $limit = max(0, (int) $this->option('limit'));
        $profiles = $this->writeCsv($pdo, $workDir.'/profiles.csv', $this->profileSql($limit), ['register_code' => $registerCode]);
        $units = $this->writeCsv($pdo, $workDir.'/units.csv', $this->unitSql($limit), ['register_code' => $registerCode]);
        $manifest = [
            'format' => 'projectt-fris-register-package-v1',
            'register_code' => $registerCode,
            'company_name' => $company['company_name'],
            'exported_at' => now()->toIso8601String(),
            'source_filename' => basename($path),
            'limit' => $limit,
            'profiles' => $profiles,
            'units' => $units,
        ];
        File::put($workDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $zipPath = $outputDir.'/fris_register_'.$registerCode.'_'.now()->format('Ymd_His').'.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Unable to create package ZIP: '.$zipPath);

            return self::FAILURE;
        }
        foreach (['manifest.json', 'profiles.csv', 'units.csv'] as $file) {
            $zip->addFile($workDir.'/'.$file, $file);
        }
        $zip->close();
        File::deleteDirectory($workDir);

        $this->info('FRIS register package created:');
        $this->line($zipPath);
        $this->line('Profiles: '.$profiles['rows'].' rows');
        $this->line('Units: '.$units['rows'].' rows');

        return self::SUCCESS;
    }

    private function profileSql(int $limit): string
    {
        $sql = 'select rowid as source_row_number, * from profiles where register_code = :register_code order by account_number';

        return $limit > 0 ? $sql.' limit '.$limit : $sql;
    }

    private function unitSql(int $limit): string
    {
        $sql = 'select rowid as source_row_number, * from units where regcode = :register_code order by id';

        return $limit > 0 ? $sql.' limit '.$limit : $sql;
    }

    /** @param array<string, mixed> $bindings @return array{rows:int,sha256:string,size_bytes:int} */
    private function writeCsv(PDO $pdo, string $path, string $sql, array $bindings): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV for writing: '.$path);
        }

        $rows = 0;
        $headersWritten = false;
        while ($row = $stmt->fetch()) {
            if (! $headersWritten) {
                fputcsv($handle, array_keys($row));
                $headersWritten = true;
            }
            fputcsv($handle, $row);
            $rows++;
        }
        fclose($handle);

        return [
            'rows' => $rows,
            'sha256' => hash_file('sha256', $path),
            'size_bytes' => filesize($path),
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
}

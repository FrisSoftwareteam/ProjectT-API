<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

class ProfileFrisSqlite extends Command
{
    protected $signature = 'fris:profile
        {path=FRIS.sqlite : Path to the FRIS SQLite database}
        {--quick : Skip heavier orphan and per-register unit scans}
        {--output=outputs/fris_profile : Output directory for JSON and Markdown reports}';

    protected $description = 'Profile the FRIS SQLite source database without mutating Project T data';

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
            $pdo->exec('PRAGMA temp_store = MEMORY');
        } catch (Throwable $exception) {
            $this->error('Unable to open FRIS SQLite file: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Profiling FRIS SQLite source: '.$path);
        $quick = (bool) $this->option('quick');

        $report = [
            'source' => [
                'path' => $path,
                'sha256' => $quick ? 'skipped in quick mode' : hash_file('sha256', $path),
                'size_bytes' => filesize($path),
                'profiled_at' => now()->toIso8601String(),
                'quick_mode' => $quick,
            ],
            'tables' => [],
            'columns' => [],
            'quality' => [],
            'distributions' => [],
            'registers' => [],
        ];

        foreach (['companies', 'profiles', 'units', 'combined_view'] as $table) {
            $report['tables'][$table] = [
                'rows' => $quick && $table !== 'companies'
                    ? 'skipped in quick mode'
                    : $this->scalar($pdo, "select count(*) from {$table}"),
            ];
            $report['columns'][$table] = $this->all($pdo, "pragma table_info({$table})");
        }

        if (! $quick) {
            $report['quality']['profile_duplicate_keys'] = $this->scalar($pdo, <<<'SQL'
                select count(*) from (
                    select account_number, register_code
                    from profiles
                    group by account_number, register_code
                    having count(*) > 1
                )
                SQL);
        }
        $profileSource = $quick ? '(select * from profiles limit 10000)' : 'profiles';
        $report['quality']['profile_quality_basis'] = $quick ? 'first 10000 rows' : 'all rows';
        $report['quality']['profiles_missing_name'] = $this->scalar($pdo, "select count(*) from {$profileSource} where names is null or trim(names) = ''");
        $report['quality']['profiles_missing_address'] = $this->scalar($pdo, "select count(*) from {$profileSource} where address is null or trim(address) = ''");
        $report['quality']['profiles_missing_email'] = $this->scalar($pdo, "select count(*) from {$profileSource} where mail is null or trim(mail) = ''");
        $report['quality']['profiles_missing_mobile'] = $this->scalar($pdo, "select count(*) from {$profileSource} where mobile is null or trim(mobile) = ''");
        $report['quality']['profiles_missing_bank_account'] = $this->scalar($pdo, "select count(*) from {$profileSource} where bankac is null or trim(bankac) = ''");

        $report['registers']['companies'] = $quick
            ? $this->all($pdo, 'select register_code, company_name from companies order by register_code')
            : $this->all($pdo, <<<'SQL'
                select c.register_code, c.company_name, count(p.account_number) profile_rows, coalesce(sum(p.holdings), 0) profile_holdings
                from companies c
                left join profiles p on p.register_code = c.register_code
                group by c.register_code, c.company_name
                order by c.register_code
                SQL);

        if (! $quick) {
            $this->line('Running heavier unit reconciliation scans...');
            $report['quality']['unit_duplicate_cert_keys'] = $this->scalar($pdo, <<<'SQL'
                select count(*) from (
                    select regcode, acctno, cert_number
                    from units
                    where cert_number is not null
                    group by regcode, acctno, cert_number
                    having count(*) > 1
                )
                SQL);
            $report['quality']['units_missing_cert_number'] = $this->scalar($pdo, 'select count(*) from units where cert_number is null');
            $report['quality']['units_missing_issue_date'] = $this->scalar($pdo, "select count(*) from units where issue_dt is null or trim(issue_dt) = ''");
            $report['quality']['units_non_positive_units'] = $this->scalar($pdo, 'select count(*) from units where units is null or units <= 0');
            $report['quality']['orphan_units_without_profile'] = $this->scalar($pdo, <<<'SQL'
                select count(*)
                from units u
                left join profiles p on p.account_number = u.acctno and p.register_code = u.regcode
                where p.account_number is null
                SQL);
            $report['quality']['profiles_without_units'] = $this->scalar($pdo, <<<'SQL'
                select count(*)
                from profiles p
                left join units u on u.acctno = p.account_number and u.regcode = p.register_code
                where u.id is null
                SQL);
            $report['registers']['unit_totals'] = $this->all($pdo, <<<'SQL'
                select c.register_code, c.company_name, count(u.id) unit_rows, coalesce(sum(u.units), 0) unit_total
                from companies c
                left join units u on u.regcode = c.register_code
                group by c.register_code, c.company_name
                order by c.register_code
                SQL);
            $report['distributions']['unit_status'] = $this->all($pdo, 'select status, count(*) rows, sum(units) total_units from units group by status order by rows desc');
            $report['distributions']['unit_verif'] = $this->all($pdo, 'select verif, count(*) rows from units group by verif order by rows desc');
            $report['distributions']['unit_claimed'] = $this->all($pdo, 'select claimed, count(*) rows from units group by claimed order by rows desc');
            $report['distributions']['unit_stop'] = $this->all($pdo, 'select stop, count(*) rows from units group by stop order by rows desc');
        }

        $outputDir = base_path((string) $this->option('output'));
        File::ensureDirectoryExists($outputDir);
        $jsonPath = $outputDir.'/fris_profile_'.now()->format('Ymd_His').'.json';
        $mdPath = $outputDir.'/fris_profile_'.now()->format('Ymd_His').'.md';
        File::put($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        File::put($mdPath, $this->markdown($report));

        $this->info('FRIS profile written:');
        $this->line($jsonPath);
        $this->line($mdPath);

        return self::SUCCESS;
    }

    private function scalar(PDO $pdo, string $sql): int|float|string|null
    {
        return $pdo->query($sql)->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    private function all(PDO $pdo, string $sql): array
    {
        return $pdo->query($sql)->fetchAll();
    }

    /** @param array<string, mixed> $report */
    private function markdown(array $report): string
    {
        $lines = [
            '# FRIS SQLite Profile',
            '',
            '- Source: `'.$report['source']['path'].'`',
            '- SHA-256: `'.$report['source']['sha256'].'`',
            '- Size bytes: '.$report['source']['size_bytes'],
            '- Profiled at: '.$report['source']['profiled_at'],
            '- Quick mode: '.($report['source']['quick_mode'] ? 'yes' : 'no'),
            '',
            '## Row Counts',
            '',
        ];

        foreach ($report['tables'] as $table => $data) {
            $lines[] = '- `'.$table.'`: '.$data['rows'];
        }

        $lines[] = '';
        $lines[] = '## Quality Signals';
        $lines[] = '';
        foreach ($report['quality'] as $key => $value) {
            $lines[] = '- `'.$key.'`: '.$value;
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}

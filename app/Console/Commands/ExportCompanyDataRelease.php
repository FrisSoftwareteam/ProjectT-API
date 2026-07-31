<?php

namespace App\Console\Commands;

use App\Models\LegacyMigrationBatch;
use App\Services\CompanyDataRelease\CompanyDataReleaseBundleService;
use Illuminate\Console\Command;

class ExportCompanyDataRelease extends Command
{
    protected $signature = 'company-release:export
        {batch : Published workstation legacy-migration batch ID}
        {--output=migration-releases : Ignored local output directory}
        {--name= : Optional safe bundle name}';

    protected $description = 'Export a published workstation batch as a normalized production company bundle';

    public function handle(CompanyDataReleaseBundleService $bundles): int
    {
        $batch = LegacyMigrationBatch::findOrFail((int) $this->argument('batch'));
        $result = $bundles->export($batch, (string) $this->option('output'), $this->option('name') ?: null);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

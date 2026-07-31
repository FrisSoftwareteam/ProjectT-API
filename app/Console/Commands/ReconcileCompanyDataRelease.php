<?php

namespace App\Console\Commands;

use App\Services\CompanyDataRelease\CompanyDataReleaseService;
use Illuminate\Console\Command;

class ReconcileCompanyDataRelease extends Command
{
    protected $signature = 'company-release:reconcile {release : Release database ID, public ID or bundle release ID}';

    protected $description = 'Reconcile a production company release ledger against all target records and units';

    public function handle(CompanyDataReleaseService $releases): int
    {
        $result = $releases->reconcile($releases->findRelease((string) $this->argument('release')));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $result['result'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }
}

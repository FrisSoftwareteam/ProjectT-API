<?php

namespace App\Console\Commands;

use App\Services\CompanyDataRelease\CompanyDataReleaseService;
use Illuminate\Console\Command;

class ShowCompanyDataRelease extends Command
{
    protected $signature = 'company-release:status {release : Release database ID, public ID or bundle release ID}';

    protected $description = 'Show a production company release, approvals, events and record status totals';

    public function handle(CompanyDataReleaseService $releases): int
    {
        $release = $releases->findRelease((string) $this->argument('release'));
        $recordStatuses = $release->records()->selectRaw('status, COUNT(*) aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $this->line(json_encode([
            'release' => $release->toArray(),
            'record_statuses' => $recordStatuses,
            'approvals' => $release->approvals()->orderBy('id')->get()->toArray(),
            'events' => $release->events()->orderBy('id')->get()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

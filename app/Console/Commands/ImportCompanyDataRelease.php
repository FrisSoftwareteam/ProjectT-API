<?php

namespace App\Console\Commands;

use App\Services\CompanyDataRelease\CompanyDataReleaseService;
use Illuminate\Console\Command;
use RuntimeException;

class ImportCompanyDataRelease extends Command
{
    protected $signature = 'company-release:import
        {release : Release database ID, public ID or bundle release ID}
        {--actor= : Active checker/importer administrator email}
        {--resume : Resume an intentionally verified interrupted import}';

    protected $description = 'Transactionally import or resume an independently approved company bundle';

    public function handle(CompanyDataReleaseService $releases): int
    {
        $actorEmail = trim((string) $this->option('actor'));
        if ($actorEmail === '') {
            throw new RuntimeException('--actor is required.');
        }
        $actor = $releases->actor($actorEmail);
        $release = $releases->import(
            $releases->findRelease((string) $this->argument('release')),
            $actor->id,
            (bool) $this->option('resume')
        );
        $this->line(json_encode($release->only(['id', 'public_id', 'status', 'imported_rows', 'imported_quantity', 'imported_at']), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\CompanyDataRelease\CompanyDataReleaseService;
use Illuminate\Console\Command;
use RuntimeException;

class RollbackCompanyDataRelease extends Command
{
    protected $signature = 'company-release:rollback
        {release : Release database ID, public ID or bundle release ID}
        {--actor= : Active rollback-authority administrator email}
        {--comment= : Controlled rollback reason}';

    protected $description = 'Roll back a company release only while no downstream business activity exists';

    public function handle(CompanyDataReleaseService $releases): int
    {
        $actorEmail = trim((string) $this->option('actor'));
        $comment = trim((string) $this->option('comment'));
        if ($actorEmail === '' || strlen($comment) < 20) {
            throw new RuntimeException('--actor is required and --comment must contain at least 20 characters.');
        }
        $actor = $releases->actor($actorEmail);
        $release = $releases->rollback($releases->findRelease((string) $this->argument('release')), $actor->id, $comment);
        $this->line(json_encode($release->only(['id', 'public_id', 'status', 'rolled_back_rows', 'rolled_back_at']), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}

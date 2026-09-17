<?php

namespace App\Console\Commands;

use App\Services\CompanyDataRelease\CompanyDataReleaseService;
use Illuminate\Console\Command;
use RuntimeException;

class ApproveCompanyDataRelease extends Command
{
    protected $signature = 'company-release:approve
        {release : Release database ID, public ID or bundle release ID}
        {--actor= : Active independent checker administrator email}
        {--comment= : Independent approval comment}';

    protected $description = 'Independently approve an unchanged verified production company bundle';

    public function handle(CompanyDataReleaseService $releases): int
    {
        $actorEmail = trim((string) $this->option('actor'));
        $comment = trim((string) $this->option('comment'));
        if ($actorEmail === '' || strlen($comment) < 20) {
            throw new RuntimeException('--actor is required and --comment must contain at least 20 characters.');
        }
        $actor = $releases->actor($actorEmail);
        $release = $releases->approve($releases->findRelease((string) $this->argument('release')), $actor->id, $comment);
        $this->line(json_encode($release->only(['id', 'public_id', 'status', 'approved_by', 'approved_at']), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}

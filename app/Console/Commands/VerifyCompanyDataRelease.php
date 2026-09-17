<?php

namespace App\Console\Commands;

use App\Services\CompanyDataRelease\CompanyDataReleaseService;
use Illuminate\Console\Command;
use RuntimeException;

class VerifyCompanyDataRelease extends Command
{
    protected $signature = 'company-release:verify
        {bundle : Absolute or application-relative .tar.gz bundle path}
        {--sha256= : Expected SHA-256 supplied by the workstation release manifest}
        {--actor= : Active maker administrator email}
        {--comment= : Verification and submission comment}';

    protected $description = 'Verify a company bundle against production and submit it for independent approval';

    public function handle(CompanyDataReleaseService $releases): int
    {
        $actorEmail = trim((string) $this->option('actor'));
        $expectedSha256 = strtolower(trim((string) $this->option('sha256')));
        $comment = trim((string) $this->option('comment'));
        if ($actorEmail === '' || $expectedSha256 === '' || strlen($comment) < 20) {
            throw new RuntimeException('--actor and --sha256 are required; --comment must contain at least 20 characters.');
        }
        $actor = $releases->actor($actorEmail);
        $release = $releases->verify((string) $this->argument('bundle'), $expectedSha256, $actor->id, $comment);
        $this->line(json_encode($release->only(['id', 'public_id', 'bundle_release_id', 'status', 'expected_rows', 'expected_quantity', 'verified_by']), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}

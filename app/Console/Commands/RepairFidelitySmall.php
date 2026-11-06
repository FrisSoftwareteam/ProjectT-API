<?php

namespace App\Console\Commands;

use App\Services\FidelitySmallRepairService;
use Illuminate\Console\Command;
use Throwable;

class RepairFidelitySmall extends Command
{
    protected $signature = 'cscs:repair-fidelity-small {--apply} {--actor= : Administrator ID} {--restore-missing} {--expected-final-total= : Independently reconciled final share-class total} {--share-class-id= : Explicit active share-class ID for register 87 when it has more than one}';

    protected $description = 'Preview or repair only the three source-verified Fidelity test accounts; never posts CSCS transactions';

    public function handle(FidelitySmallRepairService $repair): int
    {
        try {
            $report = $repair->run($this->option('actor') ? (int) $this->option('actor') : null, (bool) $this->option('apply'), (bool) $this->option('restore-missing'), $this->option('expected-final-total'), $this->option('share-class-id') ? (int) $this->option('share-class-id') : null);
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}

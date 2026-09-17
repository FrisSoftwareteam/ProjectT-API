<?php

namespace App\Jobs;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationEvent;
use App\Services\LegacyMigration\LegacyMigrationStagingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class StageLegacyMigrationBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public int $uniqueFor = 7500;

    public function __construct(public readonly int $batchId, public readonly int $actorId)
    {
        $this->onQueue(config('legacy_migrations.queue', 'legacy-migrations'));
    }

    public function uniqueId(): string
    {
        return 'legacy-stage-'.$this->batchId;
    }

    public function handle(LegacyMigrationStagingService $service): void
    {
        $service->stage($this->batchId, $this->actorId);
    }

    public function failed(?Throwable $exception): void
    {
        $changed = LegacyMigrationBatch::whereKey($this->batchId)->where('status', LegacyMigrationBatch::STAGING)->update([
            'status' => LegacyMigrationBatch::FAILED,
            'failure_reason' => 'The staging worker stopped before completion. The batch may be safely staged again.',
        ]);
        if ($changed) {
            LegacyMigrationEvent::create(['batch_id' => $this->batchId, 'event_type' => 'STAGING_WORKER_FAILED', 'from_status' => LegacyMigrationBatch::STAGING, 'to_status' => LegacyMigrationBatch::FAILED, 'actor_id' => $this->actorId]);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationEvent;
use App\Services\LegacyMigration\LegacyMigrationRollbackService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RollbackLegacyMigrationBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public int $uniqueFor = 7500;

    public function __construct(public readonly int $batchId, public readonly int $actorId, public readonly string $comment)
    {
        $this->onQueue(config('legacy_migrations.queue', 'legacy-migrations'));
    }

    public function uniqueId(): string
    {
        return 'legacy-rollback-'.$this->batchId;
    }

    public function handle(LegacyMigrationRollbackService $service): void
    {
        $service->rollback($this->batchId, $this->actorId, $this->comment);
    }

    public function failed(?Throwable $exception): void
    {
        $changed = LegacyMigrationBatch::whereKey($this->batchId)->where('status', LegacyMigrationBatch::ROLLING_BACK)->update([
            'status' => LegacyMigrationBatch::ROLLBACK_BLOCKED,
            'failure_reason' => 'The rollback worker stopped between transactional chunks. Resolve the cause before resuming rollback.',
        ]);
        if ($changed) {
            LegacyMigrationEvent::create(['batch_id' => $this->batchId, 'event_type' => 'ROLLBACK_WORKER_FAILED', 'from_status' => LegacyMigrationBatch::ROLLING_BACK, 'to_status' => LegacyMigrationBatch::ROLLBACK_BLOCKED, 'actor_id' => $this->actorId]);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationEvent;
use App\Models\LegacyMigrationRecord;
use App\Services\LegacyMigration\LegacyMigrationPublishingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishLegacyMigrationBatchJob implements ShouldBeUnique, ShouldQueue
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
        return 'legacy-publish-'.$this->batchId;
    }

    public function handle(LegacyMigrationPublishingService $service): void
    {
        $service->publish($this->batchId, $this->actorId);
    }

    public function failed(?Throwable $exception): void
    {
        $batch = LegacyMigrationBatch::find($this->batchId);
        if ($batch && $batch->status === LegacyMigrationBatch::PUBLISHING) {
            $batch->update([
                'status' => LegacyMigrationBatch::PUBLISHING_FAILED,
                'published_rows' => LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'PUBLISHED')->count(),
                'failure_reason' => 'The publishing worker stopped between transactional chunks. Publishing may be safely retried.',
            ]);
            LegacyMigrationEvent::create(['batch_id' => $batch->id, 'event_type' => 'PUBLISHING_WORKER_FAILED', 'from_status' => LegacyMigrationBatch::PUBLISHING, 'to_status' => LegacyMigrationBatch::PUBLISHING_FAILED, 'actor_id' => $this->actorId]);
        }
    }
}

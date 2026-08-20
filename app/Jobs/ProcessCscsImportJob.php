<?php

namespace App\Jobs;

use App\Models\CscsUploadBatch;
use App\Models\CscsWorkflowEvent;
use App\Services\AdminNotificationService;
use App\Services\CscsImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCscsImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $batchId)
    {
        $this->timeout = (int) config('cscs.import_job_timeout', 3600);
        $this->uniqueFor = $this->timeout + 300;
        $this->onQueue(config('cscs.queue', 'cscs'));
    }

    public function uniqueId(): string
    {
        return 'cscs-import-'.$this->batchId;
    }

    public function handle(CscsImportService $service, ?AdminNotificationService $notifications = null): void
    {
        $service->processStagedImport($this->batchId);
        $batch = CscsUploadBatch::find($this->batchId);
        if ($batch?->workflow_status === 'DRAFT_REVIEW' && $notifications) {
            $notifications->sendToRoles(
                [],
                'CSCS_DRAFT_READY',
                'CSCS draft ready for review',
                "CSCS batch #{$this->batchId} has completed validation and is ready for reconciliation.",
                'cscs_upload_batch',
                $this->batchId,
                "CSCS batch #{$this->batchId}",
                "/cscs/uploads/{$this->batchId}",
                null,
                array_filter([$batch->uploaded_by])
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $updated = CscsUploadBatch::whereKey($this->batchId)
            ->where('workflow_status', 'PROCESSING')
            ->update([
                'status' => 'failed',
                'workflow_status' => 'PROCESSING_FAILED',
                'failure_reason' => 'The CSCS import worker stopped before processing completed. Review the secured application logs.',
            ]);
        if ($updated > 0 && ($batch = CscsUploadBatch::find($this->batchId))) {
            $summary = $batch->summary ?? [];
            $summary['processing_stage'] = 'FAILED';
            $batch->update(['summary' => $summary]);
            CscsWorkflowEvent::create([
                'batch_id' => $batch->id,
                'event_type' => 'PROCESSING_FAILED',
                'from_status' => 'PROCESSING',
                'to_status' => 'PROCESSING_FAILED',
                'actor_id' => $batch->uploaded_by,
                'comment' => 'The import worker stopped before processing completed.',
                'metadata' => [],
                'created_at' => now(),
            ]);
        }
    }
}

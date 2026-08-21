<?php

namespace App\Jobs;

use App\Models\AdminUser;
use App\Models\CscsUploadBatch;
use App\Services\AdminNotificationService;
use App\Services\CscsImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PostCscsBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $batchId,
        public readonly int $actorId,
        public readonly ?string $comment = null
    ) {
        $this->onQueue(config('cscs.queue', 'cscs'));
    }

    public function uniqueId(): string
    {
        return 'cscs-post-'.$this->batchId;
    }

    public function handle(CscsImportService $service, AdminNotificationService $notifications): void
    {
        $actor = AdminUser::findOrFail($this->actorId);
        $service->post($this->batchId, $actor, $this->comment);
        $notifications->sendToRoles(
            ['Reconciliation', 'Internal Audit', 'Compliance', 'Admin', 'Super Admin'],
            'CSCS_POSTED',
            'CSCS batch posted',
            "CSCS batch #{$this->batchId} was posted and verified.",
            'cscs_upload_batch',
            $this->batchId,
            "CSCS batch #{$this->batchId}",
            "/cscs/uploads/{$this->batchId}",
            $this->actorId
        );
    }

    public function failed(?Throwable $exception): void
    {
        $batch = CscsUploadBatch::find($this->batchId);
        if ($batch && ! in_array($batch->workflow_status, ['POSTED', 'STALE'], true)) {
            $batch->update([
                'workflow_status' => 'POSTING_FAILED',
                'failure_reason' => 'The controlled posting job failed. Review the secured application logs before retrying.',
            ]);
            app(AdminNotificationService::class)->sendToRoles(
                ['Reconciliation', 'Internal Audit', 'Compliance', 'Admin', 'Super Admin'],
                'CSCS_POSTING_FAILED',
                'CSCS posting failed',
                "CSCS batch #{$this->batchId} requires review before retrying.",
                'cscs_upload_batch',
                $this->batchId,
                "CSCS batch #{$this->batchId}",
                "/cscs/uploads/{$this->batchId}",
                $this->actorId
            );
        }
    }
}

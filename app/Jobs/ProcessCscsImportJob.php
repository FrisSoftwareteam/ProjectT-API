<?php

namespace App\Jobs;

use App\Models\CscsUploadBatch;
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

    public function handle(CscsImportService $service): void
    {
        $service->processStagedImport($this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        CscsUploadBatch::whereKey($this->batchId)
            ->where('workflow_status', 'PROCESSING')
            ->update([
                'status' => 'failed',
                'workflow_status' => 'PROCESSING_FAILED',
                'failure_reason' => 'The CSCS import worker stopped before processing completed. Review the secured application logs.',
            ]);
    }
}

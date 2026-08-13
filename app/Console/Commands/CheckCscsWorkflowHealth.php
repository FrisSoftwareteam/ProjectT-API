<?php

namespace App\Console\Commands;

use App\Models\CscsUploadBatch;
use Illuminate\Console\Command;

class CheckCscsWorkflowHealth extends Command
{
    protected $signature = 'cscs:health {--processing-minutes=60} {--posting-minutes=30}';

    protected $description = 'Detect CSCS batches that appear stuck in processing or posting';

    public function handle(): int
    {
        $processing = CscsUploadBatch::where('workflow_status', 'PROCESSING')
            ->where('updated_at', '<=', now()->subMinutes(max(1, (int) $this->option('processing-minutes'))))
            ->pluck('id');
        $posting = CscsUploadBatch::whereIn('workflow_status', ['POSTING_QUEUED', 'POSTING'])
            ->where('updated_at', '<=', now()->subMinutes(max(1, (int) $this->option('posting-minutes'))))
            ->pluck('id');

        if ($processing->isEmpty() && $posting->isEmpty()) {
            $this->info('CSCS workflow health check passed.');

            return self::SUCCESS;
        }

        $this->error('Potentially stuck CSCS batches detected.');
        $this->line('Processing: '.$processing->implode(', '));
        $this->line('Posting: '.$posting->implode(', '));

        return self::FAILURE;
    }
}

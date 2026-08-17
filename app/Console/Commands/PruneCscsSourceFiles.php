<?php

namespace App\Console\Commands;

use App\Models\CscsUploadBatch;
use App\Models\CscsWorkflowEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneCscsSourceFiles extends Command
{
    protected $signature = 'cscs:prune-source-files {--dry-run : Report eligible files without deleting them}';

    protected $description = 'Remove expired private CSCS source files while retaining hashes, metadata, rows, snapshots, and audit events';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('cscs.retention_days', 2555)));
        $filesPurged = 0;

        CscsUploadBatch::whereIn('workflow_status', ['POSTED', 'REJECTED', 'CANCELLED', 'PROCESSING_FAILED'])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($batches) use (&$filesPurged): void {
                foreach ($batches as $batch) {
                    $files = $batch->uploaded_files ?? [];
                    $changed = false;
                    foreach ($files as &$file) {
                        if (($file['retained'] ?? true) === false || empty($file['path'])) {
                            continue;
                        }
                        $filesPurged++;
                        if (! $this->option('dry-run')) {
                            Storage::delete($file['path']);
                            $file['retained'] = false;
                            $file['purged_at'] = now()->toIso8601String();
                            $changed = true;
                        }
                    }
                    unset($file);

                    if ($changed) {
                        $batch->update(['uploaded_files' => $files]);
                        CscsWorkflowEvent::create([
                            'batch_id' => $batch->id,
                            'event_type' => 'SOURCE_FILES_PURGED',
                            'from_status' => $batch->workflow_status,
                            'to_status' => $batch->workflow_status,
                            'actor_id' => null,
                            'comment' => 'Private source files reached the configured retention period; integrity metadata was retained.',
                            'metadata' => ['retention_days' => (int) config('cscs.retention_days', 2555)],
                            'created_at' => now(),
                        ]);
                    }
                }
            });

        $this->info(($this->option('dry-run') ? 'Eligible' : 'Purged')." CSCS source files: {$filesPurged}");

        return self::SUCCESS;
    }
}

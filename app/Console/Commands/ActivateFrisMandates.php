<?php

namespace App\Console\Commands;

use App\Models\FrisMigrationBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ActivateFrisMandates extends Command
{
    protected $signature = 'fris:activate-mandates
        {batch_id : Published FRIS migration batch ID}
        {--dry-run : Show counts without changing mandate status}';

    protected $description = 'Activate bank mandates imported from a published FRIS batch';

    public function handle(): int
    {
        $batch = FrisMigrationBatch::find((int) $this->argument('batch_id'));
        if ($batch === null || $batch->published_at === null) {
            $this->error('Published FRIS batch not found.');

            return self::FAILURE;
        }

        $shareholderIds = DB::table('fris_migration_profiles')
            ->where('batch_id', $batch->id)
            ->whereNotNull('shareholder_id')
            ->pluck('shareholder_id');

        $pending = DB::table('shareholder_bank_mandates')
            ->whereIn('shareholder_id', $shareholderIds)
            ->where('status', 'pending')
            ->count();
        $active = DB::table('shareholder_bank_mandates')
            ->whereIn('shareholder_id', $shareholderIds)
            ->where('status', 'active')
            ->count();

        $this->line('pending_mandates: '.$pending);
        $this->line('active_mandates: '.$active);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::table('shareholder_bank_mandates')
            ->whereIn('shareholder_id', $shareholderIds)
            ->where('status', 'pending')
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);

        $this->info('FRIS mandates activated for batch '.$batch->id.'.');

        return self::SUCCESS;
    }
}

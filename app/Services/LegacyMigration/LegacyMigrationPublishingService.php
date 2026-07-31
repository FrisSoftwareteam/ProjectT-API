<?php

namespace App\Services\LegacyMigration;

use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRecord;
use App\Models\ShareholderCategory;
use App\Services\CapitalValidationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class LegacyMigrationPublishingService
{
    public function __construct(
        private readonly LegacyMigrationWorkflowService $workflow,
        private readonly CapitalValidationService $capitalValidation
    ) {}

    public function publish(int $batchId, int $actorId): void
    {
        $batch = LegacyMigrationBatch::findOrFail($batchId);
        if ($batch->status !== LegacyMigrationBatch::PUBLISHING) {
            throw ValidationException::withMessages(['status' => ['The batch is not authorized for publishing.']]);
        }
        if (! hash_equals((string) $batch->approval_snapshot_hash, $this->workflow->snapshotHash($batch))) {
            $batch->update(['status' => LegacyMigrationBatch::PUBLISHING_FAILED, 'failure_reason' => 'Approved snapshot changed before publishing.']);
            throw ValidationException::withMessages(['snapshot' => ['The approved snapshot changed before publishing.']]);
        }

        try {
            LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'VALID')->orderBy('id')
                ->chunkById(config('legacy_migrations.chunk_size', 1000), fn (Collection $records) => $this->publishChunk($batch, $records));
            $published = LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'PUBLISHED')->count();
            if ($published !== (int) $batch->expected_rows) {
                throw new \RuntimeException("Published {$published} of {$batch->expected_rows} approved rows.");
            }
            $this->capitalValidation->syncOutstandingUnits((int) $batch->register_id);
            $this->capitalValidation->assertConstantBalanced((int) $batch->register_id);
            $batch->update(['status' => LegacyMigrationBatch::PUBLISHED, 'published_rows' => $published, 'published_at' => now(), 'failure_reason' => null]);
            $this->workflow->event($batch, 'PUBLISHED', LegacyMigrationBatch::PUBLISHING, LegacyMigrationBatch::PUBLISHED, $actorId, null, ['rows' => $published]);
        } catch (Throwable $exception) {
            $published = LegacyMigrationRecord::where('batch_id', $batch->id)->where('status', 'PUBLISHED')->count();
            $batch->update(['status' => LegacyMigrationBatch::PUBLISHING_FAILED, 'published_rows' => $published, 'failure_reason' => $exception->getMessage()]);
            $this->workflow->event($batch, 'PUBLISHING_FAILED', LegacyMigrationBatch::PUBLISHING, LegacyMigrationBatch::PUBLISHING_FAILED, $actorId, null, ['published_rows' => $published]);
            throw $exception;
        }
    }

    private function publishChunk(LegacyMigrationBatch $batch, Collection $records): void
    {
        DB::transaction(function () use ($batch, $records) {
            $records = LegacyMigrationRecord::whereIn('id', $records->pluck('id'))->lockForUpdate()->where('status', 'VALID')->get();
            if ($records->isEmpty()) {
                return;
            }

            $this->assertNoTargetCollisions($records);
            $categories = ShareholderCategory::whereIn('code', $records->pluck('category_code')->unique())->where('is_active', true)->pluck('id', 'code');
            if ($categories->count() !== $records->pluck('category_code')->unique()->count()) {
                throw new \RuntimeException('A required shareholder category is missing or inactive.');
            }
            $now = now();
            DB::table('shareholders')->insert($records->map(function ($record) use ($now) {
                $data = $record->normalized_data;

                return [
                    'account_no' => $record->target_account_no, 'holder_type' => $record->holder_type,
                    'full_name' => $data['full_name'], 'email' => $record->target_email, 'email_is_verified' => false,
                    'phone' => $record->target_phone, 'phone_is_verified' => false, 'contact_suppressed' => true,
                    'status' => $data['status'], 'created_at' => $now, 'updated_at' => $now,
                ];
            })->all());
            $shareholders = DB::table('shareholders')->whereIn('account_no', $records->pluck('target_account_no'))->pluck('id', 'account_no');

            DB::table('shareholder_addresses')->insert($records->map(function ($record) use ($shareholders, $now) {
                $data = $record->normalized_data;

                return [
                    'shareholder_id' => $shareholders[$record->target_account_no], 'address_line1' => $data['address_line1'],
                    'state' => $data['state'], 'country' => $data['country'], 'is_primary' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            })->all());
            $addresses = DB::table('shareholder_addresses')->whereIn('shareholder_id', $shareholders->values())->pluck('id', 'shareholder_id');

            DB::table('shareholder_register_accounts')->insert($records->map(function ($record) use ($batch, $categories, $shareholders, $now) {
                return [
                    'shareholder_id' => $shareholders[$record->target_account_no], 'register_id' => $batch->register_id,
                    'shareholder_category_id' => $categories[$record->category_code], 'shareholder_no' => $record->source_account_number,
                    'kyc_level' => 'basic', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ];
            })->all());
            $sras = DB::table('shareholder_register_accounts')->where('register_id', $batch->register_id)
                ->whereIn('shareholder_id', $shareholders->values())->pluck('id', 'shareholder_id');

            DB::table('share_positions')->insert($records->map(function ($record) use ($batch, $shareholders, $sras, $now) {
                $shareholderId = $shareholders[$record->target_account_no];

                return [
                    'sra_id' => $sras[$shareholderId], 'share_class_id' => $batch->share_class_id,
                    'quantity' => $record->quantity, 'holding_mode' => $record->holding_mode,
                    'last_updated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            })->all());
            $positions = DB::table('share_positions')->where('share_class_id', $batch->share_class_id)
                ->whereIn('sra_id', $sras->values())->pluck('id', 'sra_id');

            foreach ($records as $record) {
                $shareholderId = $shareholders[$record->target_account_no];
                $sraId = $sras[$shareholderId];
                $record->update([
                    'status' => 'PUBLISHED', 'shareholder_id' => $shareholderId,
                    'address_id' => $addresses[$shareholderId], 'sra_id' => $sraId,
                    'position_id' => $positions[$sraId], 'published_at' => $now,
                ]);
            }
        });
    }

    private function assertNoTargetCollisions(Collection $records): void
    {
        $collision = DB::table('shareholders')->whereIn('account_no', $records->pluck('target_account_no'))
            ->orWhereIn('email', $records->pluck('target_email'))->orWhereIn('phone', $records->pluck('target_phone'))->exists();
        if ($collision) {
            throw new \RuntimeException('A target shareholder identifier collision was detected during publishing.');
        }
    }
}

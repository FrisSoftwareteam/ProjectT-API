<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CompanyDataRelease;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRecord;
use App\Services\CompanyDataRelease\CompanyDataReleaseBundleService;
use App\Services\CompanyDataRelease\CompanyDataReleaseService;
use App\Services\CompanyDataRelease\FixedScaleDecimal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CompanyDataReleaseWorkflowTest extends TestCase
{
    private string $outputDirectory;

    private object $releaseMigration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->releaseMigration = require database_path('migrations/2026_07_31_000001_create_company_data_release_tables.php');
        $this->releaseMigration->up();
        config()->set('company_data_releases.chunk_size', 1);
        config()->set('company_data_releases.maximum_uncompressed_record_bytes', 1000000);
        $this->outputDirectory = sys_get_temp_dir().'/projectt-company-release-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDirectory)) {
            foreach (glob($this->outputDirectory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->outputDirectory);
        }
        $this->releaseMigration->down();
        foreach (array_reverse([
            'admin_users', 'companies', 'registers', 'share_classes', 'shareholder_categories',
            'shareholders', 'shareholder_addresses', 'shareholder_register_accounts', 'share_positions',
            'legacy_migration_batches', 'legacy_migration_records', 'share_transactions',
        ]) as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_approved_bundle_is_checksum_verified_imported_reconciled_and_rolled_back(): void
    {
        [$maker, $checker, $batch] = $this->fixtures();
        $bundle = app(CompanyDataReleaseBundleService::class)->export($batch, $this->outputDirectory, 'fidelity-test-v1');
        $inspection = app(CompanyDataReleaseBundleService::class)->inspect($bundle['archive_path']);
        $this->assertSame(2, $inspection['rows']);
        $this->assertSame('4000.000000', $inspection['quantity']);

        $service = app(CompanyDataReleaseService::class);
        try {
            $service->verify($bundle['archive_path'], str_repeat('0', 64), $maker->id, 'Submit a deliberately mismatched checksum');
            $this->fail('A bundle with the wrong transfer checksum was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('workstation SHA-256', $exception->getMessage());
        }

        $release = $service->verify(
            $bundle['archive_path'],
            $bundle['artifact_sha256'],
            $maker->id,
            'Verified against the workstation checksum and submitted'
        );
        $this->assertSame(CompanyDataRelease::PENDING_APPROVAL, $release->status);

        try {
            $service->approve($release, $maker->id, 'Maker self approval must be rejected');
            $this->fail('The release maker approved their own bundle.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('maker cannot approve', $exception->getMessage());
        }

        $release = $service->approve($release, $checker->id, 'Independent checker approved the immutable bundle');
        $this->assertSame(CompanyDataRelease::APPROVED, $release->status);
        $release = $service->import($release, $checker->id);
        $this->assertSame(CompanyDataRelease::IMPORTED, $release->status);
        $this->assertDatabaseCount('shareholders', 2);
        $this->assertDatabaseCount('shareholder_addresses', 2);
        $this->assertDatabaseCount('shareholder_register_accounts', 2);
        $this->assertDatabaseCount('share_positions', 2);
        $this->assertDatabaseHas('shareholders', [
            'contact_suppressed' => true,
            'email_is_verified' => false,
            'phone_is_verified' => false,
        ]);
        $this->assertSame('4000.000000', FixedScaleDecimal::normalize((string) DB::table('registers')->where('id', 1)->value('total_units_outstanding')));
        $this->assertSame('PASS', $service->reconcile($release)['result']);

        $firstLineage = DB::table('company_data_release_records')->where('release_id', $release->id)->orderBy('id')->first();
        DB::table('shareholders')->where('id', $firstLineage->shareholder_id)->update(['updated_at' => now()->addMinute()]);
        try {
            $service->rollback($release, $checker->id, 'Rollback must be blocked after imported holder data changes');
            $this->fail('Rollback deleted imported data that had already been changed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed after release import', $exception->getMessage());
        }
        $release->refresh();
        $this->assertSame(CompanyDataRelease::ROLLBACK_BLOCKED, $release->status);
        DB::table('shareholders')->where('id', $firstLineage->shareholder_id)->update(['updated_at' => $firstLineage->imported_at]);

        $release = $service->rollback($release, $checker->id, 'Controlled rollback before any downstream activity exists');
        $this->assertSame(CompanyDataRelease::ROLLED_BACK, $release->status);
        $this->assertDatabaseCount('shareholders', 0);
        $this->assertDatabaseCount('share_positions', 0);
        $this->assertDatabaseCount('company_data_release_records', 2);
        $this->assertDatabaseHas('company_data_release_records', ['status' => 'ROLLED_BACK']);
        $this->assertDatabaseCount('company_data_release_approvals', 2);
        $this->assertGreaterThanOrEqual(5, $release->events()->count());
    }

    /** @return array{0:AdminUser,1:AdminUser,2:LegacyMigrationBatch} */
    private function fixtures(): array
    {
        $maker = AdminUser::create(['email' => 'maker@release.test', 'first_name' => 'Release', 'last_name' => 'Maker', 'is_active' => true]);
        $checker = AdminUser::create(['email' => 'checker@release.test', 'first_name' => 'Release', 'last_name' => 'Checker', 'is_active' => true]);
        DB::table('companies')->insert(['id' => 1, 'issuer_code' => 'FIDELITYBK', 'name' => 'Fidelity', 'status' => 'active']);
        DB::table('registers')->insert([
            'id' => 1, 'company_id' => 1, 'register_code' => '87', 'name' => 'Fidelity Register',
            'capital_behaviour_type' => 'constant', 'paid_up_capital' => '4000.000000',
            'total_units_outstanding' => '0.000000', 'remaining_outstanding_units' => '0.000000',
            'status' => 'active',
        ]);
        DB::table('share_classes')->insert(['id' => 1, 'register_id' => 1, 'class_code' => 'ORDINARY', 'name' => 'Ordinary']);
        DB::table('shareholder_categories')->insert([
            ['id' => 1, 'code' => 'I', 'name' => 'Individual', 'is_active' => true],
            ['id' => 2, 'code' => 'V', 'name' => 'Foreign', 'is_active' => true],
        ]);
        $batch = LegacyMigrationBatch::create([
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'package_key' => 'test_fidelity', 'package_version' => '1.0.0',
            'register_id' => 1, 'share_class_id' => 1, 'source_filename' => 'fidelity.json',
            'source_sha256' => str_repeat('a', 64), 'source_size' => 100, 'status' => 'PUBLISHED',
            'attempt_no' => 1, 'expected_rows' => 2, 'expected_quantity' => '4000.000000',
            'staged_rows' => 2, 'valid_rows' => 2, 'error_rows' => 0, 'published_rows' => 2,
            'staged_quantity' => '4000.000000', 'config_snapshot' => ['test' => true],
            'reconciliation' => ['result' => 'PASS'], 'approval_snapshot_hash' => str_repeat('b', 64),
            'created_by' => $maker->id,
        ]);
        $this->legacyRecord($batch, 1, '1001', 'individual', 'I', '1250.000000');
        $this->legacyRecord($batch, 2, '1002', 'corporate', 'V', '2750.000000');

        return [$maker, $checker, $batch];
    }

    private function legacyRecord(LegacyMigrationBatch $batch, int $row, string $account, string $holderType, string $category, string $quantity): void
    {
        $hash = hash('sha256', '87|'.$account);
        LegacyMigrationRecord::create([
            'batch_id' => $batch->id,
            'source_row_number' => $row,
            'source_key_hash' => $hash,
            'row_hash' => hash('sha256', 'row-'.$row),
            'idempotency_key' => hash('sha256', 'test|87|'.$account),
            'source_account_number' => $account,
            'target_account_no' => 'L'.strtoupper(substr($hash, 0, 19)),
            'target_email' => 'legacy-'.substr($hash, 0, 24).'@invalid.projectt.local',
            'target_phone' => 'LEG'.strtoupper(substr($hash, 0, 29)),
            'holder_type' => $holderType,
            'category_code' => $category,
            'quantity' => $quantity,
            'holding_mode' => 'paper',
            'status' => 'PUBLISHED',
            'normalized_data' => [
                'full_name' => 'Release Holder '.$row,
                'address_line1' => $row.' Test Road',
                'state' => 'Lagos',
                'country' => 'Nigeria',
                'status' => 'active',
                'contact_verified' => false,
            ],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('first_name');
            $t->string('last_name');
            $t->boolean('is_active');
            $t->timestamps();
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('issuer_code');
            $t->string('name');
            $t->string('status');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('registers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('register_code');
            $t->string('name');
            $t->string('capital_behaviour_type')->nullable();
            $t->decimal('paid_up_capital', 28, 6)->nullable();
            $t->decimal('total_units_outstanding', 28, 6)->nullable();
            $t->decimal('remaining_outstanding_units', 28, 6)->nullable();
            $t->string('status');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('share_classes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('register_id');
            $t->string('class_code');
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('shareholder_categories', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->boolean('is_active');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('shareholders', function (Blueprint $t) {
            $t->id();
            $t->string('account_no')->unique();
            $t->string('holder_type');
            $t->string('full_name');
            $t->string('email')->unique();
            $t->boolean('email_is_verified');
            $t->string('phone')->unique();
            $t->boolean('phone_is_verified');
            $t->boolean('contact_suppressed');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('shareholder_addresses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shareholder_id')->index();
            $t->string('address_line1');
            $t->string('state')->nullable();
            $t->string('country');
            $t->boolean('is_primary');
            $t->timestamps();
        });
        Schema::create('shareholder_register_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shareholder_id');
            $t->unsignedBigInteger('register_id');
            $t->unsignedBigInteger('shareholder_category_id');
            $t->string('shareholder_no');
            $t->string('kyc_level');
            $t->string('status');
            $t->timestamps();
            $t->unique(['shareholder_id', 'register_id']);
        });
        Schema::create('share_positions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sra_id');
            $t->unsignedBigInteger('share_class_id');
            $t->decimal('quantity', 28, 6);
            $t->string('holding_mode');
            $t->timestamp('last_updated_at');
            $t->timestamps();
            $t->unique(['sra_id', 'share_class_id']);
        });
        Schema::create('legacy_migration_batches', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id');
            $t->string('package_key');
            $t->string('package_version');
            $t->unsignedBigInteger('register_id');
            $t->unsignedBigInteger('share_class_id');
            $t->string('source_filename');
            $t->char('source_sha256', 64);
            $t->unsignedBigInteger('source_size');
            $t->string('status');
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedInteger('attempt_no')->default(1);
            $t->unsignedBigInteger('expected_rows');
            $t->decimal('expected_quantity', 28, 6);
            $t->unsignedBigInteger('staged_rows')->default(0);
            $t->unsignedBigInteger('valid_rows')->default(0);
            $t->unsignedBigInteger('error_rows')->default(0);
            $t->unsignedBigInteger('published_rows')->default(0);
            $t->unsignedBigInteger('rolled_back_rows')->default(0);
            $t->decimal('staged_quantity', 28, 6)->default(0);
            $t->json('config_snapshot');
            $t->json('reconciliation')->nullable();
            $t->char('approval_snapshot_hash', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('legacy_migration_records', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('source_row_number');
            $t->char('source_key_hash', 64);
            $t->char('row_hash', 64);
            $t->char('idempotency_key', 64);
            $t->string('source_account_number');
            $t->string('target_account_no');
            $t->string('target_email');
            $t->string('target_phone');
            $t->string('holder_type');
            $t->string('category_code');
            $t->decimal('quantity', 28, 6);
            $t->string('holding_mode');
            $t->string('status');
            $t->json('normalized_data');
            $t->json('errors')->nullable();
            $t->unsignedBigInteger('shareholder_id')->nullable();
            $t->unsignedBigInteger('address_id')->nullable();
            $t->unsignedBigInteger('sra_id')->nullable();
            $t->unsignedBigInteger('position_id')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('rolled_back_at')->nullable();
            $t->timestamps();
        });
        Schema::create('share_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sra_id');
        });
    }
}

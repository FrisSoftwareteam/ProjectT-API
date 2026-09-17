<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\LegacyMigrationBatch;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\ShareholderCategory;
use App\Services\LegacyMigration\LegacyMigrationStagingService;
use App\Services\LegacyMigration\LegacyMigrationWorkflowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacyMigrationWorkflowTest extends TestCase
{
    private string $sourcePath;

    private object $controlMigration;

    private AdminUser $maker;

    private AdminUser $checker;

    private Register $register;

    private ShareClass $shareClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCoreSchema();
        $this->controlMigration = require database_path('migrations/2026_07_29_000002_create_legacy_migration_control_tables.php');
        $this->controlMigration->up();
        (require database_path('migrations/2026_07_30_000003_allow_repeat_legacy_migration_attempts.php'))->up();
        $this->createDownstreamTables();

        $this->sourcePath = tempnam(sys_get_temp_dir(), 'legacy-migration-test-');
        file_put_contents($this->sourcePath, json_encode(['TEST REGISTER' => [
            ['NAME' => 'Test Individual', 'ADDRESS' => 'One Test Road', 'account_no' => 1001, 'reg_code' => 'T1', 'SumOfno_of_units' => 1250, 'HOLDERS TYPE' => 'I', 'TEMP TYPE' => 'A', 'state name' => 'Lagos'],
            ['NAME' => 'Test Foreign Company', 'ADDRESS' => 'Two Test Road', 'account_no' => 1002, 'reg_code' => 'T1', 'SumOfno_of_units' => 2750, 'HOLDERS TYPE' => 'V', 'TEMP TYPE' => 'C', 'state name' => 'London'],
        ]], JSON_THROW_ON_ERROR));
        config()->set('legacy_migrations.queue', 'default');
        config()->set('queue.default', 'sync');
        config()->set('legacy_migrations.chunk_size', 1);
        config()->set('legacy_migrations.packages.test_package', [
            'name' => 'Test package', 'version' => '1.0.0', 'source_path' => $this->sourcePath,
            'source_filename' => 'test.json', 'source_sha256' => hash_file('sha256', $this->sourcePath),
            'source_register_code' => 'T1', 'expected_rows' => 2, 'expected_quantity' => '4000.000000',
            'holding_mode' => 'paper', 'contact_policy' => 'unique_deterministic_unverified_placeholders',
            'status' => 'active', 'category_holder_types' => ['I' => 'individual'],
            'foreign_temp_types' => ['C' => 'corporate'],
        ]);

        $this->maker = $this->admin('maker@migration.test');
        $this->checker = $this->admin('checker@migration.test');
        $this->register = Register::create(['company_id' => 1, 'register_code' => 'TARGET', 'name' => 'Target', 'status' => 'active']);
        $this->shareClass = ShareClass::create(['register_id' => $this->register->id, 'class_code' => 'ORD', 'name' => 'Ordinary']);
        ShareholderCategory::create(['code' => 'I', 'name' => 'Individual', 'default_holder_type' => 'individual', 'is_active' => true]);
        ShareholderCategory::create(['code' => 'V', 'name' => 'Foreign', 'default_holder_type' => null, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        @unlink($this->sourcePath);
        foreach (array_reverse(['share_transactions', 'share_lots', 'sra_guardians', 'sra_joint_holders', 'sra_proxies', 'shareholder_cautions', 'dividend_entitlements']) as $table) {
            Schema::dropIfExists($table);
        }
        $this->controlMigration->down();
        foreach (array_reverse(['admin_users', 'registers', 'share_classes', 'shareholder_categories', 'shareholders', 'shareholder_addresses', 'shareholder_register_accounts', 'share_positions']) as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_full_workflow_is_idempotent_maker_checked_and_reversible(): void
    {
        $workflow = app(LegacyMigrationWorkflowService::class);
        $batch = $workflow->create('test_package', $this->register->id, $this->shareClass->id, $this->maker->id);
        $sameBatch = $workflow->create('test_package', $this->register->id, $this->shareClass->id, $this->maker->id);
        $this->assertSame($batch->id, $sameBatch->id);

        app(LegacyMigrationStagingService::class)->stage($batch->id, $this->maker->id);
        $this->assertDatabaseHas('legacy_migration_batches', ['id' => $batch->id, 'status' => 'STAGED', 'valid_rows' => 2, 'error_rows' => 0]);
        $this->assertDatabaseHas('legacy_migration_records', ['batch_id' => $batch->id, 'category_code' => 'V', 'holder_type' => 'corporate', 'holding_mode' => 'paper']);

        $reconciled = $workflow->reconcile($batch->id, $this->maker->id, 'Test reconciliation');
        $this->assertSame('PASS', $reconciled['reconciliation']['result']);
        $workflow->submit($batch->id, $this->maker->id, 'Ready for independent review');

        try {
            $workflow->approve($batch->id, $this->maker->id, 'Maker self approval must be rejected');
            $this->fail('The maker approved their own batch.');
        } catch (ValidationException) {
            $this->assertSame(LegacyMigrationBatch::PENDING_APPROVAL, $batch->fresh()->status);
        }

        $workflow->approve($batch->id, $this->checker->id, 'Independent review completed');
        $approvedSnapshot = $batch->fresh()->approval_snapshot_hash;
        $workflow->dispatchPublishing($batch->id, $this->checker->id);
        $this->assertSame(LegacyMigrationBatch::PUBLISHED, $batch->fresh()->status);
        $this->assertSame($approvedSnapshot, $workflow->snapshotHash($batch->fresh()));
        $this->assertDatabaseCount('shareholders', 2);
        $this->assertDatabaseCount('share_positions', 2);
        $this->assertDatabaseHas('shareholders', ['contact_suppressed' => true, 'email_is_verified' => false, 'phone_is_verified' => false]);
        $this->assertDatabaseHas('share_positions', ['holding_mode' => 'paper', 'quantity' => 2750]);
        $this->assertSame('4000.000000', $this->register->fresh()->total_units_outstanding);

        $workflow->dispatchRollback($batch->id, $this->checker->id, 'Rollback during the controlled pre-opening verification window');
        $this->assertSame(LegacyMigrationBatch::ROLLED_BACK, $batch->fresh()->status);
        $this->assertDatabaseCount('shareholders', 0);
        $this->assertDatabaseCount('share_positions', 0);
        $this->assertSame('0.000000', $this->register->fresh()->total_units_outstanding);
        $this->assertDatabaseCount('legacy_migration_records', 2);
        $this->assertGreaterThanOrEqual(8, $batch->events()->count());

        $nextAttempt = $workflow->create('test_package', $this->register->id, $this->shareClass->id, $this->maker->id);
        $sameNextAttempt = $workflow->create('test_package', $this->register->id, $this->shareClass->id, $this->maker->id);
        $this->assertNotSame($batch->id, $nextAttempt->id);
        $this->assertSame(2, $nextAttempt->attempt_no);
        $this->assertSame($nextAttempt->id, $sameNextAttempt->id);
        $this->assertDatabaseCount('legacy_migration_records', 2);
        $this->assertDatabaseHas('legacy_migration_events', [
            'batch_id' => $nextAttempt->id,
            'event_type' => 'BATCH_CREATED',
        ]);
    }

    public function test_approved_snapshot_change_blocks_publishing(): void
    {
        $workflow = app(LegacyMigrationWorkflowService::class);
        $batch = $workflow->create('test_package', $this->register->id, $this->shareClass->id, $this->maker->id);
        app(LegacyMigrationStagingService::class)->stage($batch->id, $this->maker->id);
        $workflow->reconcile($batch->id, $this->maker->id);
        $workflow->submit($batch->id, $this->maker->id);
        $workflow->approve($batch->id, $this->checker->id, 'Independent review completed');
        DB::table('legacy_migration_records')->where('batch_id', $batch->id)->limit(1)->update(['row_hash' => str_repeat('f', 64)]);

        $this->expectException(ValidationException::class);
        $workflow->dispatchPublishing($batch->id, $this->checker->id);
    }

    public function test_rollback_is_blocked_after_downstream_activity(): void
    {
        $workflow = app(LegacyMigrationWorkflowService::class);
        $batch = $workflow->create('test_package', $this->register->id, $this->shareClass->id, $this->maker->id);
        app(LegacyMigrationStagingService::class)->stage($batch->id, $this->maker->id);
        $workflow->reconcile($batch->id, $this->maker->id);
        $workflow->submit($batch->id, $this->maker->id);
        $workflow->approve($batch->id, $this->checker->id, 'Independent review completed');
        $workflow->dispatchPublishing($batch->id, $this->checker->id);
        $sraId = DB::table('legacy_migration_records')->where('batch_id', $batch->id)->value('sra_id');
        DB::table('share_transactions')->insert(['sra_id' => $sraId]);

        try {
            $workflow->dispatchRollback($batch->id, $this->checker->id, 'Attempt rollback after a downstream transaction was created');
            $this->fail('Rollback proceeded despite downstream activity.');
        } catch (\RuntimeException) {
            $this->assertSame(LegacyMigrationBatch::ROLLBACK_BLOCKED, $batch->fresh()->status);
            $this->assertDatabaseCount('shareholders', 2);
            $this->assertDatabaseCount('share_positions', 2);
        }
    }

    public function test_rollback_is_blocked_after_dividend_entitlement_activity(): void
    {
        $workflow = app(LegacyMigrationWorkflowService::class);
        $batch = $workflow->create('test_package', $this->register->id, $this->shareClass->id, $this->maker->id);
        app(LegacyMigrationStagingService::class)->stage($batch->id, $this->maker->id);
        $workflow->reconcile($batch->id, $this->maker->id);
        $workflow->submit($batch->id, $this->maker->id);
        $workflow->approve($batch->id, $this->checker->id, 'Independent review completed');
        $workflow->dispatchPublishing($batch->id, $this->checker->id);
        $sraId = DB::table('legacy_migration_records')->where('batch_id', $batch->id)->value('sra_id');
        DB::table('dividend_entitlements')->insert(['register_account_id' => $sraId]);

        try {
            $workflow->dispatchRollback($batch->id, $this->checker->id, 'Attempt rollback after a dividend entitlement was created');
            $this->fail('Rollback proceeded despite dividend activity.');
        } catch (\RuntimeException) {
            $this->assertSame(LegacyMigrationBatch::ROLLBACK_BLOCKED, $batch->fresh()->status);
            $this->assertDatabaseCount('shareholders', 2);
            $this->assertDatabaseCount('share_positions', 2);
        }
    }

    private function admin(string $email): AdminUser
    {
        return AdminUser::create(['email' => $email, 'first_name' => 'Test', 'last_name' => 'User', 'is_active' => true]);
    }

    private function createCoreSchema(): void
    {
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('first_name');
            $t->string('last_name');
            $t->boolean('is_active');
            $t->timestamps();
        });
        Schema::create('registers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('register_code');
            $t->string('name');
            $t->string('status');
            $t->string('capital_behaviour_type')->nullable();
            $t->decimal('paid_up_capital', 28, 6)->nullable();
            $t->decimal('total_units_outstanding', 28, 6)->nullable();
            $t->decimal('remaining_outstanding_units', 28, 6)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('share_classes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('register_id');
            $t->string('class_code');
            $t->string('name')->nullable();
            $t->string('currency')->default('NGN');
            $t->decimal('par_value', 18, 6)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('shareholder_categories', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('default_holder_type')->nullable();
            $t->boolean('requires_joint_holders')->default(false);
            $t->boolean('requires_review')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('shareholders', function (Blueprint $t) {
            $t->id();
            $t->string('account_no')->unique();
            $t->string('holder_type');
            $t->string('full_name');
            $t->string('email')->unique();
            $t->string('phone')->unique();
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('shareholder_addresses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shareholder_id');
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
            $t->unsignedBigInteger('shareholder_category_id')->nullable();
            $t->string('shareholder_no');
            $t->string('residency_status')->default('resident');
            $t->string('kyc_level');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('share_positions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sra_id');
            $t->unsignedBigInteger('share_class_id');
            $t->decimal('quantity', 28, 6);
            $t->string('holding_mode');
            $t->timestamp('last_updated_at');
            $t->timestamps();
        });
    }

    private function createDownstreamTables(): void
    {
        foreach (['share_transactions', 'share_lots', 'sra_guardians', 'sra_joint_holders', 'sra_proxies', 'shareholder_cautions'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sra_id');
            });
        }

        Schema::create('dividend_entitlements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('register_account_id');
        });
    }
}

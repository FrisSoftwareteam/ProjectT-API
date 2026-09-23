<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DividendDeclaration;
use App\Models\Register;
use App\Models\ShareClass;
use App\Models\Shareholder;
use App\Models\ShareholderRegisterAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DividendDeclarationApprovalApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('register_code')->nullable();
            $table->string('unit_precision_type')->default('decimal');
            $table->unsignedTinyInteger('decimal_precision')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('share_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('register_id');
            $table->string('class_code');
            $table->string('currency')->default('NGN');
            $table->decimal('par_value', 18, 6)->nullable();
            $table->decimal('withholding_tax_rate', 8, 4)->default(10);
            $table->boolean('is_caution_class')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('dividend_declarations', function (Blueprint $table) {
            $table->id();
            $table->string('dividend_declaration_no')->nullable();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('register_id');
            $table->string('period_label');
            $table->string('description')->nullable();
            $table->string('initiator')->nullable();
            $table->decimal('rate_per_share', 18, 6)->nullable();
            $table->date('announcement_date')->nullable();
            $table->date('record_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->boolean('exclude_caution_accounts')->default(true);
            $table->boolean('require_active_bank_mandate')->default(false);
            $table->string('status')->default('DRAFT');
            $table->unsignedTinyInteger('current_approval_step')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamps();
        });

        Schema::create('dividend_declaration_share_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->unsignedBigInteger('share_class_id');
            $table->timestamps();
        });

        Schema::create('shareholders', function (Blueprint $table) {
            $table->id();
            $table->string('account_no')->unique();
            $table->string('holder_type');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('shareholder_register_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
            $table->unsignedBigInteger('register_id');
            $table->string('shareholder_no')->nullable();
            $table->string('chn')->nullable();
            $table->string('cscs_account_no')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('share_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sra_id');
            $table->unsignedBigInteger('share_class_id');
            $table->decimal('quantity', 28, 6)->default(0);
            $table->string('holding_mode')->default('demat');
            $table->timestamp('last_updated_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('shareholder_cautions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
            $table->unsignedBigInteger('sra_id')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_bank_mandates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('dividend_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->unsignedTinyInteger('step_no');
            $table->string('role_code');
            $table->string('decision');
            $table->unsignedBigInteger('actor_id');
            $table->string('comment')->nullable();
            $table->timestamp('acted_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('dividend_approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->string('role_code');
            $table->unsignedBigInteger('reliever_user_id');
            $table->unsignedBigInteger('assigned_by');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('dividend_workflow_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dividend_declaration_id');
            $table->string('event_type');
            $table->unsignedBigInteger('actor_id');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('dividend_workflow_events');
        Schema::dropIfExists('dividend_approval_delegations');
        Schema::dropIfExists('dividend_approval_actions');
        Schema::dropIfExists('shareholder_bank_mandates');
        Schema::dropIfExists('shareholder_cautions');
        Schema::dropIfExists('share_positions');
        Schema::dropIfExists('shareholder_register_accounts');
        Schema::dropIfExists('shareholders');
        Schema::dropIfExists('dividend_declaration_share_classes');
        Schema::dropIfExists('dividend_declarations');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('share_classes');
        Schema::dropIfExists('registers');
        Schema::dropIfExists('companies');

        parent::tearDown();
    }

    public function test_prelist_can_be_searched_by_shareholder_name_or_account_number(): void
    {
        [$register, $shareClass] = $this->createRegisterWithShareClass();
        $declaration = $this->createDeclaration($register, $shareClass, ['status' => 'SUBMITTED', 'current_approval_step' => 3]);
        $this->createAccountWithPosition($register, $shareClass, [
            'full_name' => 'Amadu Pinock Gimba',
            'shareholder_no' => 'SH-001',
        ]);
        $this->createAccountWithPosition($register, $shareClass, [
            'full_name' => 'Aminat Aminu Kano',
            'shareholder_no' => 'SH-002',
        ]);

        $this->withoutMiddleware()
            ->getJson("/api/admin/dividend-declarations/{$declaration->id}/preview?search=Aminat")
            ->assertOk()
            ->assertJsonPath('data.entitlements.0.shareholder_name', 'Aminat Aminu Kano')
            ->assertJsonCount(1, 'data.entitlements');

        $this->withoutMiddleware()
            ->getJson("/api/admin/dividend-declarations/{$declaration->id}/preview?search=SH-001")
            ->assertOk()
            ->assertJsonPath('data.entitlements.0.shareholder_name', 'Amadu Pinock Gimba')
            ->assertJsonCount(1, 'data.entitlements');

        $this->withoutMiddleware()
            ->getJson("/api/admin/dividend-declarations/{$declaration->id}/preview?search=no-such-shareholder")
            ->assertOk()
            ->assertJsonCount(0, 'data.entitlements');

        // Simulates encodeURIComponent('Amadu Pinock Gimba') decoded server-side with spaces intact.
        $this->withoutMiddleware()
            ->getJson("/api/admin/dividend-declarations/{$declaration->id}/preview?search=".rawurlencode('Amadu Pinock Gimba'))
            ->assertOk()
            ->assertJsonPath('data.entitlements.0.shareholder_name', 'Amadu Pinock Gimba')
            ->assertJsonCount(1, 'data.entitlements');
    }

    public function test_prelist_remains_available_and_shows_eligibility_while_awaiting_approval(): void
    {
        [$register, $shareClass] = $this->createRegisterWithShareClass();
        $declaration = $this->createDeclaration($register, $shareClass, ['status' => 'SUBMITTED', 'current_approval_step' => 3]);
        $this->createAccountWithPosition($register, $shareClass, ['full_name' => 'Regular Holder']);

        $this->withoutMiddleware()
            ->getJson("/api/admin/dividend-declarations/{$declaration->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.entitlements.0.is_payable', true)
            ->assertJsonPath('data.entitlements.0.ineligibility_reason', 'NONE')
            ->assertJsonStructure([
                'data' => ['entitlements' => [['gross_amount', 'tax_amount', 'net_amount', 'tax_rate']]],
            ]);
    }

    public function test_prelist_flags_cautioned_shareholder_via_shareholder_cautions_table(): void
    {
        [$register, $shareClass] = $this->createRegisterWithShareClass();
        $declaration = $this->createDeclaration($register, $shareClass, ['status' => 'SUBMITTED', 'current_approval_step' => 3]);

        // Note: shareholders.status stays 'active' — only the shareholder_cautions row marks this account cautioned.
        $account = $this->createAccountWithPosition($register, $shareClass, ['full_name' => 'Cautioned Holder']);
        \DB::table('shareholder_cautions')->insert([
            'shareholder_id' => $account->shareholder_id,
            'sra_id' => $account->id,
            'removed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withoutMiddleware()
            ->getJson("/api/admin/dividend-declarations/{$declaration->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.entitlements.0.is_payable', false)
            ->assertJsonPath('data.entitlements.0.ineligibility_reason', 'CAUTION_ACCOUNT');
    }

    public function test_accounts_approver_cannot_approve_step_three_before_audit(): void
    {
        [$register, $shareClass] = $this->createRegisterWithShareClass();
        $declaration = $this->createDeclaration($register, $shareClass, ['status' => 'SUBMITTED', 'current_approval_step' => 3]);
        $accountsUser = $this->createAdminWithRole('accounts@example.com', 'Accounts');

        $this->withoutMiddleware()
            ->actingAs($accountsUser, 'sanctum')
            ->postJson("/api/admin/dividend-declarations/{$declaration->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Accounts cannot approve until Audit has approved this declaration');

        $this->assertDatabaseMissing('dividend_approval_actions', [
            'dividend_declaration_id' => $declaration->id,
            'role_code' => 'ACCOUNTS',
        ]);
    }

    public function test_accounts_approver_can_approve_after_audit_has_approved(): void
    {
        [$register, $shareClass] = $this->createRegisterWithShareClass();
        $declaration = $this->createDeclaration($register, $shareClass, ['status' => 'SUBMITTED', 'current_approval_step' => 3]);
        $auditUser = $this->createAdminWithRole('audit@example.com', 'Audit');
        $accountsUser = $this->createAdminWithRole('accounts@example.com', 'Accounts');

        $this->withoutMiddleware()
            ->actingAs($auditUser, 'sanctum')
            ->postJson("/api/admin/dividend-declarations/{$declaration->id}/approve")
            ->assertOk();

        $this->withoutMiddleware()
            ->actingAs($accountsUser, 'sanctum')
            ->postJson("/api/admin/dividend-declarations/{$declaration->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.declaration.status', 'APPROVED');
    }

    /**
     * @return array{0: Register, 1: ShareClass}
     */
    private function createRegisterWithShareClass(): array
    {
        $companyId = \DB::table('companies')->insertGetId(['name' => 'ABC Transport PLC', 'created_at' => now(), 'updated_at' => now()]);

        $register = Register::query()->create([
            'company_id' => $companyId,
            'register_code' => 'ABC001',
            'unit_precision_type' => 'decimal',
            'decimal_precision' => 2,
            'status' => 'active',
        ]);

        $shareClass = ShareClass::query()->create([
            'register_id' => $register->id,
            'class_code' => 'ORD',
            'currency' => 'NGN',
            'withholding_tax_rate' => 10,
        ]);

        return [$register, $shareClass];
    }

    private function createDeclaration(Register $register, ShareClass $shareClass, array $overrides = []): DividendDeclaration
    {
        $declaration = DividendDeclaration::query()->create(array_merge([
            'dividend_declaration_no' => 'DIV-'.uniqid(),
            'company_id' => $register->company_id,
            'register_id' => $register->id,
            'period_label' => 'FY2025',
            'initiator' => 'operations',
            'rate_per_share' => 2.5,
            'record_date' => now()->subDay()->format('Y-m-d'),
            'exclude_caution_accounts' => true,
            'require_active_bank_mandate' => false,
            'status' => 'DRAFT',
        ], $overrides));

        \DB::table('dividend_declaration_share_classes')->insert([
            'dividend_declaration_id' => $declaration->id,
            'share_class_id' => $shareClass->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $declaration;
    }

    private function createAccountWithPosition(Register $register, ShareClass $shareClass, array $overrides = []): ShareholderRegisterAccount
    {
        $suffix = uniqid();

        $shareholder = Shareholder::query()->create([
            'account_no' => "ACC-{$suffix}",
            'holder_type' => 'individual',
            'full_name' => $overrides['full_name'] ?? "Holder {$suffix}",
            'first_name' => 'Holder',
            'last_name' => $suffix,
            'email' => "{$suffix}@example.com",
            'phone' => "0800{$suffix}",
            'status' => 'active',
        ]);

        $account = ShareholderRegisterAccount::query()->create([
            'shareholder_id' => $shareholder->id,
            'register_id' => $register->id,
            'shareholder_no' => $overrides['shareholder_no'] ?? "SH-{$suffix}",
            'status' => 'active',
        ]);

        \DB::table('share_positions')->insert([
            'sra_id' => $account->id,
            'share_class_id' => $shareClass->id,
            'quantity' => 1000,
            'holding_mode' => 'demat',
            'last_updated_at' => now()->subDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $account;
    }

    private function createAdminWithRole(string $email, string $roleName): AdminUser
    {
        $admin = AdminUser::query()->create([
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'User',
            'is_active' => true,
        ]);

        $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
        $admin->assignRole($role);

        return $admin;
    }
}

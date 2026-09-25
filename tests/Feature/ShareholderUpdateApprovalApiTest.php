<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiActivity;
use App\Models\AdminUser;
use App\Models\Shareholder;
use App\Models\ShareholderMandate;
use App\Notifications\ShareholderChangeRequestNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PT-184: every shareholder-record write (personal info, bank mandate,
 * identification, profile picture) now goes through the maker-checker
 * ShareholderChangeRequest flow instead of writing directly. This covers
 * the parts not already exercised by ShareholderChangeRequestApiTest
 * (personal info/address, already gated before this ticket): bank mandate
 * approval (with its own permission), the closed PUT bypass, the
 * duplicate-pending guard, and notifications.
 */
class ShareholderUpdateApprovalApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->boolean('is_active')->default(true);
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

        Schema::create('shareholder_bank_mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('bvn')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
            $table->string('request_type');
            $table->json('payload_old');
            $table->json('payload_new');
            $table->string('reason')->nullable();
            $table->string('status')->default('submitted');
            $table->string('control_no', 40);
            $table->unsignedBigInteger('submitted_by');
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('shareholder_change_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('change_request_id');
            $table->unsignedInteger('level_no');
            $table->string('decision');
            $table->unsignedBigInteger('decided_by');
            $table->timestamp('decided_at')->useCurrent();
            $table->string('remarks')->nullable();
        });

        Schema::create('shareholder_cautions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->string('address_line1');
            $table->boolean('is_primary')->default(true);
            $table->timestamps();
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
        Schema::dropIfExists('shareholder_change_approvals');
        Schema::dropIfExists('shareholder_change_requests');
        Schema::dropIfExists('shareholder_addresses');
        Schema::dropIfExists('shareholder_cautions');
        Schema::dropIfExists('shareholder_bank_mandates');
        Schema::dropIfExists('shareholders');
        Schema::dropIfExists('admin_users');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Bank mandate
    // -----------------------------------------------------------------

    public function test_add_mandate_submits_a_pending_request_and_does_not_create_the_mandate(): void
    {
        $maker = $this->createAdmin('maker@example.com');
        $shareholder = $this->createShareholder('one');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/mandates", $this->mandatePayload())
            ->assertStatus(202)
            ->assertJsonPath('data.request_type', 'bank_mandate')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.payload_new.account_number', '0123456789')
            ->assertJsonPath('data.payload_new.mandate_id', null);

        $this->assertDatabaseMissing('shareholder_bank_mandates', ['shareholder_id' => $shareholder->id]);
    }

    public function test_approving_a_new_mandate_request_creates_the_mandate(): void
    {
        $maker = $this->createAdmin('maker@example.com');
        $approver = $this->createAdminWithPermission('mandate-approver@example.com', 'shareholder_change_requests.approve_mandate');
        $shareholder = $this->createShareholder('one');

        $submit = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/mandates", $this->mandatePayload());
        $changeRequestId = $submit->json('data.id');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($approver, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequestId}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.change_request.status', 'applied')
            ->assertJsonPath('data.mandate.account_number', '0123456789');

        $this->assertDatabaseHas('shareholder_bank_mandates', [
            'shareholder_id' => $shareholder->id,
            'account_number' => '0123456789',
        ]);
    }

    public function test_updating_an_existing_mandate_applies_to_the_same_row_on_approval(): void
    {
        $maker = $this->createAdmin('maker@example.com');
        $approver = $this->createAdminWithPermission('mandate-approver@example.com', 'shareholder_change_requests.approve_mandate');
        $shareholder = $this->createShareholder('one');
        $mandate = ShareholderMandate::query()->create([
            'shareholder_id' => $shareholder->id,
            'bank_name' => 'Old Bank',
            'account_name' => 'Old Name',
            'account_number' => '0000000000',
            'status' => 'active',
        ]);

        $submit = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->putJson(
                "/api/shareholders/{$shareholder->id}/mandates/{$mandate->id}",
                $this->mandatePayload(['bank_name' => 'New Bank'])
            );
        $submit->assertStatus(202)->assertJsonPath('data.payload_new.mandate_id', $mandate->id);
        $changeRequestId = $submit->json('data.id');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($approver, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequestId}/approve", [])
            ->assertOk();

        $this->assertDatabaseHas('shareholder_bank_mandates', [
            'id' => $mandate->id,
            'bank_name' => 'New Bank',
        ]);
        $this->assertDatabaseCount('shareholder_bank_mandates', 1);
    }

    public function test_approving_a_mandate_change_requires_the_mandate_specific_permission(): void
    {
        $maker = $this->createAdmin('maker@example.com');
        // Has the GENERAL approve permission, but not approve_mandate.
        $generalApprover = $this->createAdminWithPermission('general-approver@example.com', 'shareholder_change_requests.approve');
        $shareholder = $this->createShareholder('one');

        $submit = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/mandates", $this->mandatePayload());
        $changeRequestId = $submit->json('data.id');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($generalApprover, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequestId}/approve", [])
            ->assertForbidden();

        $this->assertDatabaseMissing('shareholder_bank_mandates', ['shareholder_id' => $shareholder->id]);
    }

    public function test_rejecting_a_mandate_change_leaves_no_mandate_behind(): void
    {
        $maker = $this->createAdmin('maker@example.com');
        $approver = $this->createAdminWithPermission('mandate-approver@example.com', 'shareholder_change_requests.approve_mandate');
        $shareholder = $this->createShareholder('one');

        $submit = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/mandates", $this->mandatePayload());
        $changeRequestId = $submit->json('data.id');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($approver, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequestId}/reject", ['remarks' => 'Could not verify account'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseMissing('shareholder_bank_mandates', ['shareholder_id' => $shareholder->id]);
    }

    public function test_cannot_submit_a_second_pending_mandate_change_while_one_is_already_pending(): void
    {
        $maker = $this->createAdmin('maker@example.com');
        $shareholder = $this->createShareholder('one');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/mandates", $this->mandatePayload())
            ->assertStatus(202);

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/mandates", $this->mandatePayload(['bank_name' => 'Another Bank']))
            ->assertStatus(422);

        $this->assertDatabaseCount('shareholder_change_requests', 1);
    }

    // -----------------------------------------------------------------
    // Closed bypass: PUT /shareholders/{id}
    // -----------------------------------------------------------------

    public function test_direct_shareholder_update_now_submits_a_pending_request_instead_of_applying(): void
    {
        $maker = $this->createAdmin('maker@example.com');
        $shareholder = $this->createShareholder('one');
        $originalEmail = $shareholder->email;

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->putJson("/api/shareholders/{$shareholder->id}", [
                'holder_type' => 'individual',
                'first_name' => $shareholder->first_name,
                'email' => 'new.email@example.com',
                'phone' => $shareholder->phone,
                'status' => 'active',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.payload_new.email', 'new.email@example.com');

        $shareholder->refresh();
        $this->assertSame($originalEmail, $shareholder->email);
    }

    // -----------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------

    public function test_submitting_a_change_request_notifies_general_approvers(): void
    {
        Notification::fake();

        $maker = $this->createAdmin('maker@example.com');
        $approver = $this->createAdminWithPermission('approver@example.com', 'shareholder_change_requests.approve');
        $shareholder = $this->createShareholder('one');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->putJson("/api/shareholders/{$shareholder->id}", [
                'holder_type' => 'individual',
                'first_name' => $shareholder->first_name,
                'email' => 'notify.me@example.com',
                'phone' => $shareholder->phone,
                'status' => 'active',
            ])
            ->assertStatus(202);

        Notification::assertSentTo($approver, ShareholderChangeRequestNotification::class);
        Notification::assertNotSentTo($maker, ShareholderChangeRequestNotification::class);
    }

    public function test_mandate_change_notifies_mandate_approvers_not_general_approvers(): void
    {
        Notification::fake();

        $maker = $this->createAdmin('maker@example.com');
        $generalApprover = $this->createAdminWithPermission('general-approver@example.com', 'shareholder_change_requests.approve');
        $mandateApprover = $this->createAdminWithPermission('mandate-approver@example.com', 'shareholder_change_requests.approve_mandate');
        $shareholder = $this->createShareholder('one');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/mandates", $this->mandatePayload())
            ->assertStatus(202);

        Notification::assertSentTo($mandateApprover, ShareholderChangeRequestNotification::class);
        Notification::assertNotSentTo($generalApprover, ShareholderChangeRequestNotification::class);
    }

    public function test_deciding_a_change_request_notifies_the_submitter(): void
    {
        Notification::fake();

        $maker = $this->createAdmin('maker@example.com');
        $approver = $this->createAdminWithPermission('approver@example.com', 'shareholder_change_requests.approve');
        $shareholder = $this->createShareholder('one');

        $submit = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->putJson("/api/shareholders/{$shareholder->id}", [
                'holder_type' => 'individual',
                'first_name' => $shareholder->first_name,
                'email' => 'decide.me@example.com',
                'phone' => $shareholder->phone,
                'status' => 'active',
            ]);
        $changeRequestId = $submit->json('data.id');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($approver, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequestId}/approve", [])
            ->assertOk();

        Notification::assertSentTo($maker, ShareholderChangeRequestNotification::class);
    }

    private function mandatePayload(array $overrides = []): array
    {
        return array_merge([
            'bank_name' => 'First Bank of Nigeria PLC',
            'account_name' => 'Confirmed Account Name',
            'account_number' => '0123456789',
        ], $overrides);
    }

    private function createAdmin(string $email): AdminUser
    {
        return AdminUser::query()->create([
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'is_active' => true,
        ]);
    }

    private function createAdminWithPermission(string $email, string $permission): AdminUser
    {
        $actor = $this->createAdmin($email);
        $actor->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));

        return $actor;
    }

    private function createShareholder(string $suffix): Shareholder
    {
        return Shareholder::query()->create([
            'account_no' => "ACCOUNT-{$suffix}",
            'holder_type' => 'individual',
            'first_name' => 'First',
            'last_name' => 'Last',
            'full_name' => "Shareholder {$suffix}",
            'email' => "{$suffix}@example.com",
            'phone' => "0800{$suffix}0000",
            'status' => 'active',
        ]);
    }
}

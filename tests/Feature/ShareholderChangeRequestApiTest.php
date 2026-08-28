<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiActivity;
use App\Models\AdminUser;
use App\Models\Shareholder;
use App\Models\ShareholderChangeRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShareholderChangeRequestApiTest extends TestCase
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
            $table->date('date_of_birth')->nullable();
            $table->string('sex')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('next_of_kin_relationship')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
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
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Nigeria');
            $table->boolean('is_primary')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->string('request_type');
            $table->json('payload_old');
            $table->json('payload_new');
            $table->string('reason')->nullable();
            $table->string('status')->default('submitted');
            $table->string('control_no', 40);
            $table->foreignId('submitted_by');
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('shareholder_change_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id');
            $table->unsignedInteger('level_no');
            $table->string('decision');
            $table->foreignId('decided_by');
            $table->timestamp('decided_at')->useCurrent();
            $table->string('remarks')->nullable();
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
        Schema::dropIfExists('shareholder_cautions');
        Schema::dropIfExists('shareholder_addresses');
        Schema::dropIfExists('shareholders');
        Schema::dropIfExists('admin_users');

        parent::tearDown();
    }

    public function test_submit_creates_pending_change_request_with_correct_snapshot(): void
    {
        $actor = $this->createAdminWithPermission('maker@example.com', 'shareholder_change_requests.create');
        $shareholder = $this->createShareholder('one');

        $response = $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/change-requests", [
                'email' => 'new.email@example.com',
                'phone' => '08012345678',
                'reason' => 'Shareholder requested update via call center',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.request_type', 'profile_update')
            ->assertJsonPath('data.payload_old.email', $shareholder->email)
            ->assertJsonPath('data.payload_old.phone', $shareholder->phone)
            ->assertJsonPath('data.payload_new.email', 'new.email@example.com')
            ->assertJsonPath('data.payload_new.phone', '08012345678')
            ->assertJsonPath('data.submitted_by', $actor->id);

        $this->assertMatchesRegularExpression(
            '/^CR-\d{8}-\d{6}$/',
            $response->json('data.control_no')
        );
    }

    public function test_submit_infers_request_type_from_fields_submitted(): void
    {
        $actor = $this->createAdminWithPermission('maker@example.com', 'shareholder_change_requests.create');

        $cases = [
            ['payload' => ['email' => 'x1@example.com'], 'expected' => 'email_change'],
            ['payload' => ['phone' => '08010000001'], 'expected' => 'phone_change'],
            ['payload' => ['first_name' => 'Ada', 'last_name' => 'Lovelace'], 'expected' => 'name_change'],
            ['payload' => ['email' => 'x2@example.com', 'phone' => '08010000002'], 'expected' => 'profile_update'],
        ];

        foreach ($cases as $i => $case) {
            $shareholder = $this->createShareholder("type{$i}");

            $this->withoutMiddleware(LogApiActivity::class)
                ->actingAs($actor, 'sanctum')
                ->postJson("/api/shareholders/{$shareholder->id}/change-requests", $case['payload'])
                ->assertCreated()
                ->assertJsonPath('data.request_type', $case['expected']);
        }
    }

    public function test_submit_address_change_infers_address_change_type_and_snapshots_existing_address(): void
    {
        $actor = $this->createAdminWithPermission('maker@example.com', 'shareholder_change_requests.create');
        $shareholder = $this->createShareholder('addr');
        $this->createAddress($shareholder, [
            'address_line1' => '12, Marina Road',
            'city' => 'Lagos Island',
            'state' => 'Lagos',
            'country' => 'Nigeria',
        ]);

        $response = $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/change-requests", [
                'address' => [
                    'address_line1' => '2 Aminu Kano Crescent',
                    'city' => 'Ikoyi',
                    'state' => 'Lagos State',
                    'country' => 'Nigeria',
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.request_type', 'address_change')
            ->assertJsonPath('data.payload_old.address.address_line1', '12, Marina Road')
            ->assertJsonPath('data.payload_old.address.city', 'Lagos Island')
            ->assertJsonPath('data.payload_new.address.address_line1', '2 Aminu Kano Crescent')
            ->assertJsonPath('data.payload_new.address.city', 'Ikoyi');
    }

    public function test_submit_address_change_requires_address_line1(): void
    {
        $actor = $this->createAdminWithPermission('maker@example.com', 'shareholder_change_requests.create');
        $shareholder = $this->createShareholder('addr2');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/change-requests", [
                'address' => ['city' => 'Ikoyi'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address.address_line1');
    }

    public function test_submit_requires_at_least_one_eligible_field(): void
    {
        $actor = $this->createAdminWithPermission('maker@example.com', 'shareholder_change_requests.create');
        $shareholder = $this->createShareholder('one');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/change-requests", ['reason' => 'no fields'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');
    }

    public function test_submit_rejects_email_already_used_by_another_shareholder(): void
    {
        $actor = $this->createAdminWithPermission('maker@example.com', 'shareholder_change_requests.create');
        $shareholder = $this->createShareholder('one');
        $other = $this->createShareholder('other');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/change-requests", [
                'email' => $other->email,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_submit_requires_permission(): void
    {
        $actor = $this->createAdmin('nopermission@example.com');
        $shareholder = $this->createShareholder('one');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/change-requests", [
                'email' => 'new.email@example.com',
            ])
            ->assertForbidden();
    }

    public function test_index_lists_only_pending_statuses_by_default(): void
    {
        $actor = $this->createAdminWithPermission('checker@example.com', 'shareholder_change_requests.view');
        $shareholder = $this->createShareholder('one');

        $pending = $this->createChangeRequest($shareholder, $actor, 'submitted');
        $this->createChangeRequest($shareholder, $actor, 'applied');
        $this->createChangeRequest($shareholder, $actor, 'rejected');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->getJson('/api/shareholder-change-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $pending->id);
    }

    public function test_index_search_matches_shareholder_name_and_account_number(): void
    {
        $actor = $this->createAdminWithPermission('checker@example.com', 'shareholder_change_requests.view');
        $shareholder = Shareholder::query()->create([
            'account_no' => 'ACC-999',
            'holder_type' => 'individual',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'full_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '08011110000',
            'status' => 'active',
        ]);
        $this->createChangeRequest($shareholder, $actor, 'submitted');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->getJson('/api/shareholder-change-requests?search=Lovelace')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->getJson('/api/shareholder-change-requests?search=ACC-999')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_show_returns_existing_and_proposed_values_with_approval_history(): void
    {
        $actor = $this->createAdminWithPermission('checker@example.com', 'shareholder_change_requests.view');
        $shareholder = $this->createShareholder('one');
        $changeRequest = $this->createChangeRequest($shareholder, $actor, 'submitted');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->getJson("/api/shareholder-change-requests/{$changeRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.existing_values', $changeRequest->payload_old)
            ->assertJsonPath('data.proposed_values', $changeRequest->payload_new)
            ->assertJsonCount(0, 'data.approval_history');
    }

    public function test_approve_applies_changes_to_shareholder_and_marks_applied(): void
    {
        $actor = $this->createAdminWithPermission('approver@example.com', 'shareholder_change_requests.approve');
        $shareholder = $this->createShareholder('one');
        $changeRequest = ShareholderChangeRequest::factory()->create([
            'shareholder_id' => $shareholder->id,
            'payload_old' => ['email' => $shareholder->email],
            'payload_new' => ['email' => 'approved.email@example.com'],
            'status' => 'submitted',
            'submitted_by' => $actor->id,
        ]);

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequest->id}/approve", [
                'remarks' => 'Verified caller identity via BVN match',
            ])
            ->assertOk()
            ->assertJsonPath('data.change_request.status', 'applied')
            ->assertJsonPath('data.shareholder.email', 'approved.email@example.com');

        $this->assertDatabaseHas('shareholders', [
            'id' => $shareholder->id,
            'email' => 'approved.email@example.com',
        ]);

        $this->assertDatabaseHas('shareholder_change_approvals', [
            'change_request_id' => $changeRequest->id,
            'decision' => 'approved',
            'level_no' => 1,
        ]);
    }

    public function test_approve_address_change_updates_primary_address(): void
    {
        $actor = $this->createAdminWithPermission('approver@example.com', 'shareholder_change_requests.approve');
        $shareholder = $this->createShareholder('addrapprove');
        $address = $this->createAddress($shareholder, ['address_line1' => 'Old Address, Lagos']);

        $changeRequest = ShareholderChangeRequest::factory()->create([
            'shareholder_id' => $shareholder->id,
            'request_type' => 'address_change',
            'payload_old' => ['address' => ['address_line1' => 'Old Address, Lagos']],
            'payload_new' => ['address' => ['address_line1' => '2 Aminu Kano Crescent, Ikoyi']],
            'status' => 'submitted',
            'submitted_by' => $actor->id,
        ]);

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequest->id}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.change_request.status', 'applied');

        $this->assertDatabaseHas('shareholder_addresses', [
            'id' => $address->id,
            'address_line1' => '2 Aminu Kano Crescent, Ikoyi',
        ]);
    }

    public function test_approve_address_change_fails_cleanly_when_no_primary_address_exists(): void
    {
        $actor = $this->createAdminWithPermission('approver@example.com', 'shareholder_change_requests.approve');
        $shareholder = $this->createShareholder('addrnoaddr');

        $changeRequest = ShareholderChangeRequest::factory()->create([
            'shareholder_id' => $shareholder->id,
            'request_type' => 'address_change',
            'payload_old' => ['address' => ['address_line1' => null]],
            'payload_new' => ['address' => ['address_line1' => '2 Aminu Kano Crescent, Ikoyi']],
            'status' => 'submitted',
            'submitted_by' => $actor->id,
        ]);

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequest->id}/approve", [])
            ->assertStatus(422);

        $this->assertDatabaseHas('shareholder_change_requests', [
            'id' => $changeRequest->id,
            'status' => 'submitted',
        ]);
    }

    public function test_index_filters_by_date_range(): void
    {
        $actor = $this->createAdminWithPermission('checker@example.com', 'shareholder_change_requests.view');
        $shareholder = $this->createShareholder('daterange');

        $inRange = $this->createChangeRequest($shareholder, $actor, 'submitted');
        $inRange->forceFill(['submitted_at' => '2026-08-05 10:00:00'])->save();

        $outOfRange = $this->createChangeRequest($shareholder, $actor, 'submitted');
        $outOfRange->forceFill(['submitted_at' => '2026-07-01 10:00:00'])->save();

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->getJson('/api/shareholder-change-requests?date_from=2026-08-01&date_to=2026-08-10')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $inRange->id);
    }

    public function test_reject_leaves_shareholder_unchanged_and_requires_remarks(): void
    {
        $actor = $this->createAdminWithPermission('approver@example.com', 'shareholder_change_requests.approve');
        $shareholder = $this->createShareholder('one');
        $changeRequest = ShareholderChangeRequest::factory()->create([
            'shareholder_id' => $shareholder->id,
            'payload_old' => ['email' => $shareholder->email],
            'payload_new' => ['email' => 'rejected.email@example.com'],
            'status' => 'submitted',
            'submitted_by' => $actor->id,
        ]);

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequest->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequest->id}/reject", [
                'remarks' => 'Caller could not verify BVN',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('shareholders', [
            'id' => $shareholder->id,
            'email' => $shareholder->email,
        ]);

        $this->assertDatabaseHas('shareholder_change_approvals', [
            'change_request_id' => $changeRequest->id,
            'decision' => 'rejected',
        ]);
    }

    public function test_approve_requires_permission(): void
    {
        $actor = $this->createAdmin('nopermission@example.com');
        $shareholder = $this->createShareholder('one');
        $changeRequest = ShareholderChangeRequest::factory()->create([
            'shareholder_id' => $shareholder->id,
            'status' => 'submitted',
            'submitted_by' => $actor->id,
        ]);

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequest->id}/approve", [])
            ->assertForbidden();
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
        $actor->givePermissionTo(Permission::create(['name' => $permission, 'guard_name' => 'web']));

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

    private function createChangeRequest(Shareholder $shareholder, AdminUser $actor, string $status): ShareholderChangeRequest
    {
        return ShareholderChangeRequest::factory()->create([
            'shareholder_id' => $shareholder->id,
            'status' => $status,
            'submitted_by' => $actor->id,
        ]);
    }

    private function createAddress(Shareholder $shareholder, array $overrides = []): \App\Models\ShareholderAddress
    {
        return \App\Models\ShareholderAddress::query()->create(array_merge([
            'shareholder_id' => $shareholder->id,
            'address_line1' => '1 Test Street',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'country' => 'Nigeria',
            'is_primary' => true,
        ], $overrides));
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiActivity;
use App\Models\AdminUser;
use App\Models\Shareholder;
use App\Models\ShareholderChangeRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Profile picture changes no longer write to shareholders.profile_picture
 * directly (PT-184): the upload endpoint now stages the file and submits a
 * pending ShareholderChangeRequest. The live column only changes once that
 * request is approved.
 */
class ShareholderProfilePictureUploadTest extends TestCase
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
            $table->string('account_no', 20)->unique();
            $table->enum('holder_type', ['individual', 'corporate']);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone', 32)->unique();
            $table->date('date_of_birth')->nullable();
            $table->string('sex')->nullable();
            $table->string('rc_number', 50)->nullable();
            $table->string('nin', 20)->nullable();
            $table->string('bvn', 20)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone', 32)->nullable();
            $table->string('next_of_kin_relationship', 100)->nullable();
            $table->enum('status', ['active', 'dormant', 'deceased', 'closed'])->default('active');
            $table->string('profile_picture')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_register_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->foreignId('register_id')->nullable();
            $table->string('shareholder_no')->nullable();
            $table->string('chn')->nullable();
            $table->string('cscs_account_no')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->string('address_line1');
            $table->timestamps();
        });

        Schema::create('shareholder_bank_mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->string('bank_name')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->string('id_type')->nullable();
            $table->string('id_value')->nullable();
            $table->timestamps();
        });

        Schema::create('share_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sra_id');
            $table->foreignId('share_class_id')->nullable();
            $table->decimal('quantity', 20, 6)->default(0);
            $table->timestamps();
        });

        Schema::create('share_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sra_id');
            $table->foreignId('share_class_id')->nullable();
            $table->string('lot_ref')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_cautions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shareholder_id');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shareholder_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
            $table->string('request_type');
            $table->json('payload_old');
            $table->json('payload_new');
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('resubmitted_from_id')->nullable();
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
        Schema::dropIfExists('share_lots');
        Schema::dropIfExists('share_positions');
        Schema::dropIfExists('shareholder_identities');
        Schema::dropIfExists('shareholder_bank_mandates');
        Schema::dropIfExists('shareholder_addresses');
        Schema::dropIfExists('shareholder_register_accounts');
        Schema::dropIfExists('shareholders');
        Schema::dropIfExists('admin_users');

        parent::tearDown();
    }

    public function test_upload_submits_a_pending_change_request_without_touching_the_live_picture(): void
    {
        Storage::fake('public');

        $actor = $this->createAdmin();
        $shareholder = $this->createShareholder([
            'profile_picture' => '/storage/profile-pictures/shareholders/1/old.jpg',
        ]);
        Storage::disk('public')->put('profile-pictures/shareholders/1/old.jpg', 'old-picture');

        $response = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/profile-picture", [
                'profile_picture' => UploadedFile::fake()->image('shareholder.png')->size(256),
            ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request_type', 'profile_picture_change')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.payload_old.profile_picture', $shareholder->profile_picture);

        $pendingUrl = $response->json('data.payload_new.profile_picture');
        $this->assertIsString($pendingUrl);
        $this->assertStringContainsString('/pending/', $pendingUrl);

        // Live record and the old file are both untouched until approval.
        $shareholder->refresh();
        $this->assertSame('/storage/profile-pictures/shareholders/1/old.jpg', $shareholder->profile_picture);
        Storage::disk('public')->assertExists('profile-pictures/shareholders/1/old.jpg');
        Storage::disk('public')->assertExists($this->storagePathFromUrl($pendingUrl));
    }

    public function test_shareholder_profile_picture_upload_requires_image_file(): void
    {
        Storage::fake('public');

        $actor = $this->createAdmin();
        $shareholder = $this->createShareholder();

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/profile-picture", [
                'profile_picture' => UploadedFile::fake()->create('shareholder.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('profile_picture');
    }

    public function test_approving_the_picture_change_applies_it_and_deletes_the_old_file(): void
    {
        Storage::fake('public');

        $maker = $this->createAdmin();
        $approver = $this->createApprover();
        $shareholder = $this->createShareholder([
            'profile_picture' => '/storage/profile-pictures/shareholders/1/old.jpg',
        ]);
        Storage::disk('public')->put('profile-pictures/shareholders/1/old.jpg', 'old-picture');

        $submit = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/profile-picture", [
                'profile_picture' => UploadedFile::fake()->image('new.png')->size(256),
            ]);
        $changeRequestId = $submit->json('data.id');
        $pendingUrl = $submit->json('data.payload_new.profile_picture');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($approver, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequestId}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.change_request.status', 'applied')
            ->assertJsonPath('data.shareholder.profile_picture', $pendingUrl);

        $shareholder->refresh();
        $this->assertSame($pendingUrl, $shareholder->profile_picture);
        Storage::disk('public')->assertMissing('profile-pictures/shareholders/1/old.jpg');
        Storage::disk('public')->assertExists($this->storagePathFromUrl($pendingUrl));
    }

    public function test_rejecting_the_picture_change_deletes_the_pending_file_and_leaves_the_live_picture(): void
    {
        Storage::fake('public');

        $maker = $this->createAdmin();
        $approver = $this->createApprover();
        $shareholder = $this->createShareholder([
            'profile_picture' => '/storage/profile-pictures/shareholders/1/old.jpg',
        ]);
        Storage::disk('public')->put('profile-pictures/shareholders/1/old.jpg', 'old-picture');

        $submit = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->postJson("/api/shareholders/{$shareholder->id}/profile-picture", [
                'profile_picture' => UploadedFile::fake()->image('new.png')->size(256),
            ]);
        $changeRequestId = $submit->json('data.id');
        $pendingUrl = $submit->json('data.payload_new.profile_picture');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($approver, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequestId}/reject", ['remarks' => 'Blurry photo'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $shareholder->refresh();
        $this->assertSame('/storage/profile-pictures/shareholders/1/old.jpg', $shareholder->profile_picture);
        Storage::disk('public')->assertExists('profile-pictures/shareholders/1/old.jpg');
        Storage::disk('public')->assertMissing($this->storagePathFromUrl($pendingUrl));
    }

    public function test_delete_submits_a_pending_deletion_without_touching_the_live_picture(): void
    {
        Storage::fake('public');

        $actor = $this->createAdmin();
        $shareholder = $this->createShareholder([
            'profile_picture' => '/storage/profile-pictures/shareholders/1/old.jpg',
        ]);
        Storage::disk('public')->put('profile-pictures/shareholders/1/old.jpg', 'old-picture');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->deleteJson("/api/shareholders/{$shareholder->id}/profile-picture", ['reason' => 'Shareholder requested removal'])
            ->assertStatus(202)
            ->assertJsonPath('data.request_type', 'profile_picture_change')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.payload_old.profile_picture', $shareholder->profile_picture)
            ->assertJsonPath('data.payload_new.profile_picture', null);

        $shareholder->refresh();
        $this->assertNotNull($shareholder->profile_picture);
        Storage::disk('public')->assertExists('profile-pictures/shareholders/1/old.jpg');
    }

    public function test_delete_is_rejected_when_shareholder_has_no_picture(): void
    {
        $actor = $this->createAdmin();
        $shareholder = $this->createShareholder();

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->deleteJson("/api/shareholders/{$shareholder->id}/profile-picture")
            ->assertStatus(422);
    }

    public function test_approving_a_deletion_clears_the_live_picture_and_removes_the_file(): void
    {
        Storage::fake('public');

        $maker = $this->createAdmin();
        $approver = $this->createApprover();
        $shareholder = $this->createShareholder([
            'profile_picture' => '/storage/profile-pictures/shareholders/1/old.jpg',
        ]);
        Storage::disk('public')->put('profile-pictures/shareholders/1/old.jpg', 'old-picture');

        $submit = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->deleteJson("/api/shareholders/{$shareholder->id}/profile-picture");
        $changeRequestId = $submit->json('data.id');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($approver, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequestId}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.change_request.status', 'applied')
            ->assertJsonPath('data.shareholder.profile_picture', null);

        $shareholder->refresh();
        $this->assertNull($shareholder->profile_picture);
        Storage::disk('public')->assertMissing('profile-pictures/shareholders/1/old.jpg');
    }

    public function test_rejecting_a_deletion_leaves_the_live_picture_untouched(): void
    {
        Storage::fake('public');

        $maker = $this->createAdmin();
        $approver = $this->createApprover();
        $shareholder = $this->createShareholder([
            'profile_picture' => '/storage/profile-pictures/shareholders/1/old.jpg',
        ]);
        Storage::disk('public')->put('profile-pictures/shareholders/1/old.jpg', 'old-picture');

        $submit = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($maker, 'sanctum')
            ->deleteJson("/api/shareholders/{$shareholder->id}/profile-picture");
        $changeRequestId = $submit->json('data.id');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($approver, 'sanctum')
            ->postJson("/api/shareholder-change-requests/{$changeRequestId}/reject", ['remarks' => 'Not authorized to remove'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $shareholder->refresh();
        $this->assertSame('/storage/profile-pictures/shareholders/1/old.jpg', $shareholder->profile_picture);
        Storage::disk('public')->assertExists('profile-pictures/shareholders/1/old.jpg');
    }

    public function test_create_shareholder_with_a_picture_submits_it_as_pending(): void
    {
        Storage::fake('public');

        $actor = $this->createAdmin();

        $response = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->post('/api/shareholders', [
                'holder_type' => 'individual',
                'first_name' => 'New',
                'last_name' => 'Holder',
                'email' => 'new.holder@example.com',
                'phone' => '08099999999',
                'status' => 'active',
                'profile_picture' => UploadedFile::fake()->image('avatar.png')->size(256),
            ]);

        $response->assertCreated();
        $shareholderId = $response->json('id');
        $this->assertIsInt($shareholderId);
        $this->assertNull($response->json('profile_picture'));
        $this->assertSame('submitted', $response->json('pending_profile_picture.status'));
        $this->assertSame('profile_picture_change', $response->json('pending_profile_picture.request_type'));

        $this->assertDatabaseHas('shareholder_change_requests', [
            'shareholder_id' => $shareholderId,
            'request_type' => 'profile_picture_change',
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('shareholders', [
            'id' => $shareholderId,
            'profile_picture' => null,
        ]);
    }

    public function test_create_shareholder_without_a_picture_still_works(): void
    {
        $actor = $this->createAdmin();

        $response = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->postJson('/api/shareholders', [
                'holder_type' => 'individual',
                'first_name' => 'No',
                'last_name' => 'Picture',
                'email' => 'no.picture@example.com',
                'phone' => '08088888888',
                'status' => 'active',
            ]);

        $response->assertCreated();
        $this->assertNull($response->json('profile_picture'));
        $this->assertArrayNotHasKey('pending_profile_picture', $response->json());
    }

    public function test_shareholder_profile_picture_is_returned_on_search_and_show(): void
    {
        $actor = $this->createAdmin();
        $shareholder = $this->createShareholder([
            'first_name' => 'Ada',
            'full_name' => 'Ada Lovelace',
            'profile_picture' => '/storage/profile-pictures/shareholders/1/profile.jpg',
        ]);

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->getJson('/api/shareholders?search=Ada')
            ->assertOk()
            ->assertJsonPath('data.0.id', $shareholder->id)
            ->assertJsonPath('data.0.profile_picture', $shareholder->profile_picture);

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->getJson("/api/shareholders/{$shareholder->id}")
            ->assertOk()
            ->assertJsonPath('id', $shareholder->id)
            ->assertJsonPath('profile_picture', $shareholder->profile_picture);
    }

    private function createAdmin(): AdminUser
    {
        return AdminUser::query()->create([
            'email' => uniqid('admin', true).'@example.com',
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'is_active' => true,
        ]);
    }

    private function createApprover(): AdminUser
    {
        $approver = $this->createAdmin();
        $approver->givePermissionTo(Permission::firstOrCreate([
            'name' => 'shareholder_change_requests.approve',
            'guard_name' => 'web',
        ]));

        return $approver;
    }

    private function createShareholder(array $attributes = []): Shareholder
    {
        return Shareholder::query()->create(array_merge([
            'account_no' => uniqid('SH'),
            'holder_type' => 'individual',
            'first_name' => 'Test',
            'last_name' => 'Shareholder',
            'full_name' => 'Test Shareholder',
            'email' => uniqid('shareholder', true).'@example.com',
            'phone' => '080'.random_int(10000000, 99999999),
            'status' => 'active',
        ], $attributes));
    }

    private function storagePathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertIsString($path);

        return substr(ltrim($path, '/'), strlen('storage/'));
    }
}

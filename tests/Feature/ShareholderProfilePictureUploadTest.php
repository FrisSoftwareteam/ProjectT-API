<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiActivity;
use App\Models\AdminUser;
use App\Models\Shareholder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TestCase;

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
    }

    protected function tearDown(): void
    {
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

    public function test_admin_can_upload_and_replace_shareholder_profile_picture(): void
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
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Shareholder profile picture uploaded successfully');

        $shareholder->refresh();
        $this->assertIsString($shareholder->profile_picture);
        $this->assertStringStartsWith('/storage/profile-pictures/shareholders/'.$shareholder->id.'/', $shareholder->profile_picture);
        $response->assertJsonPath('data.profile_picture', $shareholder->profile_picture);

        Storage::disk('public')->assertMissing('profile-pictures/shareholders/1/old.jpg');
        Storage::disk('public')->assertExists($this->storagePathFromUrl($shareholder->profile_picture));
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

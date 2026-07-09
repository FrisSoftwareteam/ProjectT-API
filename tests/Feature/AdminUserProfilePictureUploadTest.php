<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Http\Middleware\LogApiActivity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TestCase;

class AdminUserProfilePictureUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('microsoft_id')->unique()->nullable();
            $table->string('email')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('department')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('profile_picture')->nullable();
            $table->json('microsoft_data')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('admin_users');

        parent::tearDown();
    }

    public function test_admin_can_upload_profile_picture_for_user(): void
    {
        Storage::fake('public');

        $actor = $this->createAdmin('actor@example.com');
        $target = $this->createAdmin('target@example.com', [
            'profile_picture' => '/storage/profile-pictures/admin-users/2/old.jpg',
        ]);
        Storage::disk('public')->put('profile-pictures/admin-users/2/old.jpg', 'old-picture');

        $response = $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/admin/users/{$target->id}/profile-picture", [
                'profile_picture' => UploadedFile::fake()->image('avatar.png')->size(256),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Profile picture uploaded successfully');

        $target->refresh();
        $this->assertIsString($target->profile_picture);
        $this->assertStringStartsWith('/storage/profile-pictures/admin-users/'.$target->id.'/', $target->profile_picture);

        Storage::disk('public')->assertMissing('profile-pictures/admin-users/2/old.jpg');
        Storage::disk('public')->assertExists($this->storagePathFromUrl($target->profile_picture));
    }

    public function test_profile_picture_upload_requires_image_file(): void
    {
        Storage::fake('public');

        $actor = $this->createAdmin('actor@example.com');
        $target = $this->createAdmin('target@example.com');

        $this->withoutMiddleware([PermissionMiddleware::class, LogApiActivity::class])
            ->actingAs($actor, 'sanctum')
            ->postJson("/api/admin/users/{$target->id}/profile-picture", [
                'profile_picture' => UploadedFile::fake()->create('avatar.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('profile_picture');
    }

    private function createAdmin(string $email, array $attributes = []): AdminUser
    {
        return AdminUser::query()->create(array_merge([
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'is_active' => true,
        ], $attributes));
    }

    private function storagePathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertIsString($path);

        return substr(ltrim($path, '/'), strlen('storage/'));
    }
}

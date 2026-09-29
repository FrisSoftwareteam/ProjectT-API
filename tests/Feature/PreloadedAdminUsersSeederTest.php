<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Database\Seeders\PreloadedAdminUsersSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreloadedAdminUsersSeederTest extends TestCase
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

    public function test_it_preloads_the_spreadsheet_users_and_is_safe_to_rerun(): void
    {
        $existingUser = AdminUser::query()->create([
            'microsoft_id' => 'existing-microsoft-id',
            'email' => 'YAYA.LAWAL@firstregistrarsnigeria.com',
            'first_name' => 'Existing',
            'last_name' => 'Name',
            'is_active' => false,
        ]);

        $this->seed(PreloadedAdminUsersSeeder::class);
        $this->seed(PreloadedAdminUsersSeeder::class);

        $this->assertDatabaseCount('admin_users', 81);
        $this->assertDatabaseHas('admin_users', [
            'email' => 'oluwaseyiadetola@firstregistrarsnigeria.com',
            'first_name' => 'Oluwaseyi',
            'last_name' => 'Adetola',
            'microsoft_id' => null,
            'is_active' => true,
        ]);

        $existingUser->refresh();
        $this->assertSame('existing-microsoft-id', $existingUser->microsoft_id);
        $this->assertSame('Existing', $existingUser->first_name);
        $this->assertFalse($existingUser->is_active);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MicrosoftAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2025_09_26_031752_create_admin_users_table.php'))->up();
        (require database_path('migrations/2025_09_26_031952_create_permission_tables.php'))->up();
        (require database_path('migrations/2025_09_26_033629_create_personal_access_tokens_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('admin_users');

        parent::tearDown();
    }

    public function test_successful_microsoft_login_links_a_preloaded_user_and_preserves_permissions(): void
    {
        $adminUser = AdminUser::query()->create([
            'email' => 'preloaded.user@firstregistrarsnigeria.com',
            'first_name' => 'Preloaded',
            'last_name' => 'User',
            'is_active' => true,
        ]);
        $permission = Permission::create(['name' => 'shareholders.view', 'guard_name' => 'web']);
        $adminUser->givePermissionTo($permission);

        $this->mockMicrosoftUser(
            'microsoft-user-1',
            'PRELOADED.USER@firstregistrarsnigeria.com'
        );

        $this->getJson('/api/auth/microsoft/callback?redirect_uri=http://frontend.test/callback')
            ->assertRedirect()
            ->assertRedirectContains('status=success');

        $adminUser->refresh();

        $this->assertSame('microsoft-user-1', $adminUser->microsoft_id);
        $this->assertSame('Preloaded', $adminUser->first_name);
        $this->assertTrue($adminUser->hasPermissionTo('shareholders.view'));
        $this->assertDatabaseCount('admin_users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_successful_microsoft_login_still_creates_a_user_that_was_not_preloaded(): void
    {
        $this->mockMicrosoftUser(
            'microsoft-user-2',
            'new.user@firstregistrarsnigeria.com',
            ['givenName' => 'New', 'surname' => 'User', 'jobTitle' => 'Operations']
        );

        $this->getJson('/api/auth/microsoft/callback?redirect_uri=http://frontend.test/callback')
            ->assertRedirect()
            ->assertRedirectContains('status=success');

        $this->assertDatabaseHas('admin_users', [
            'microsoft_id' => 'microsoft-user-2',
            'email' => 'new.user@firstregistrarsnigeria.com',
            'first_name' => 'New',
            'last_name' => 'User',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_existing_microsoft_linked_user_continues_to_log_in(): void
    {
        $adminUser = AdminUser::query()->create([
            'microsoft_id' => 'microsoft-user-3',
            'email' => 'linked.user@firstregistrarsnigeria.com',
            'first_name' => 'Linked',
            'last_name' => 'User',
            'is_active' => true,
        ]);

        $this->mockMicrosoftUser('microsoft-user-3', 'linked.user@firstregistrarsnigeria.com');

        $this->getJson('/api/auth/microsoft/callback?redirect_uri=http://frontend.test/callback')
            ->assertRedirect()
            ->assertRedirectContains('status=success');

        $this->assertDatabaseCount('admin_users', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => AdminUser::class,
            'tokenable_id' => $adminUser->id,
        ]);
    }

    public function test_failed_microsoft_authentication_cannot_log_in_a_preloaded_user(): void
    {
        $adminUser = AdminUser::query()->create([
            'email' => 'preloaded.user@firstregistrarsnigeria.com',
            'first_name' => 'Preloaded',
            'last_name' => 'User',
            'is_active' => true,
        ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andThrow(new RuntimeException('Microsoft authentication failed'));
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($provider);

        $this->getJson('/api/auth/microsoft/callback?response_type=json')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->assertNull($adminUser->fresh()->microsoft_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * @param  array<string, mixed>  $rawOverrides
     */
    private function mockMicrosoftUser(string $id, string $email, array $rawOverrides = []): void
    {
        $raw = array_merge([
            'id' => $id,
            'mail' => $email,
            'givenName' => 'Microsoft',
            'surname' => 'User',
            'jobTitle' => null,
        ], $rawOverrides);

        $microsoftUser = (new SocialiteUser)->setRaw($raw)->map([
            'id' => $id,
            'name' => trim(($raw['givenName'] ?? '').' '.($raw['surname'] ?? '')),
            'email' => $email,
        ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($microsoftUser);
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($provider);
    }
}

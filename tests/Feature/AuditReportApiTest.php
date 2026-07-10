<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiActivity;
use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditReportApiTest extends TestCase
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

        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('action', 255);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
        Schema::dropIfExists('user_activity_logs');
        Schema::dropIfExists('admin_users');

        parent::tearDown();
    }

    public function test_authorized_admin_can_filter_audit_report(): void
    {
        $actor = $this->createAdmin('reporter@example.com');
        $actor->givePermissionTo(Permission::create(['name' => 'reports.audit', 'guard_name' => 'web']));

        $operationsUser = $this->createAdmin('ops@example.com');
        $operationsUser->assignRole(Role::create(['name' => 'Operations', 'guard_name' => 'web']));

        $auditUser = $this->createAdmin('audit@example.com');
        $auditUser->assignRole(Role::create(['name' => 'Audit', 'guard_name' => 'web']));

        DB::table('user_activity_logs')->insert([
            'user_id' => $operationsUser->id,
            'action' => 'api_shareholders_updated',
            'metadata' => json_encode([
                'path' => 'api/shareholders/15',
                'route' => 'api/shareholders/{shareholder}',
                'route_parameters' => ['shareholder' => 15],
                'shareholder_id' => 15,
                'shareholder_no' => 'SH-000015',
            ]),
            'created_at' => '2026-07-01 10:15:30',
            'updated_at' => '2026-07-01 10:15:30',
        ]);

        DB::table('user_activity_logs')->insert([
            'user_id' => $auditUser->id,
            'action' => 'api_companies_updated',
            'metadata' => json_encode(['path' => 'api/admin/companies/1']),
            'created_at' => '2026-07-02 10:15:30',
            'updated_at' => '2026-07-02 10:15:30',
        ]);

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($actor, 'sanctum')
            ->getJson('/api/reports/audit?role=Operations&shareholder_id=15&activity_category=shareholders&date_from=2026-07-01&date_to=2026-07-01')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.user.email', 'ops@example.com')
            ->assertJsonPath('data.data.0.roles.0', 'Operations')
            ->assertJsonPath('data.data.0.activity_type', 'api_shareholders_updated')
            ->assertJsonPath('data.data.0.activity_category', 'shareholders')
            ->assertJsonPath('data.data.0.shareholder_reference', 'SH-000015')
            ->assertJsonPath('data.data.0.date', '2026-07-01')
            ->assertJsonPath('data.data.0.time', '10:15:30');
    }

    public function test_audit_report_requires_reports_audit_permission(): void
    {
        $user = $this->createAdmin('viewer@example.com');

        $this->withoutMiddleware(LogApiActivity::class)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/reports/audit')
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
}

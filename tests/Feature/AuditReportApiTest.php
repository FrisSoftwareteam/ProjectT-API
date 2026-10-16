<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiActivity;
use App\Models\AdminUser;
use App\Models\UserActivityLog;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

    public function test_shareholder_filter_matches_exact_numeric_and_string_ids_and_both_transfer_and_merge_participants(): void
    {
        $actor = $this->createAdmin('exact@example.com');
        $actor->givePermissionTo(Permission::create(['name' => 'reports.audit', 'guard_name' => 'web']));
        foreach ([
            ['shareholder_id' => 15],
            ['shareholder_id' => '15'],
            ['route_parameters' => ['shareholder' => '15']],
            ['from_shareholder_id' => 15, 'to_shareholder_id' => 20],
            ['from_shareholder_id' => 20, 'to_shareholder_id' => 15],
            ['primary_shareholder_id' => 15],
            ['duplicate_shareholder_id' => 15],
            ['shareholder_ids' => [15, 20]],
            ['shareholder_id' => 150],
            ['shareholder_id' => '150'],
            ['route_parameters' => ['shareholder' => 150]],
            ['shareholder_ids' => [150]],
        ] as $metadata) {
            UserActivityLog::create(['user_id' => $actor->id, 'action' => 'test', 'metadata' => $metadata]);
        }
        $this->actingAs($actor, 'sanctum')->getJson('/api/reports/audit?shareholder_id=15')
            ->assertOk()->assertJsonPath('data.total', 8);
    }

    public function test_historical_related_record_logs_are_found_without_confusing_record_ids_with_shareholder_ids(): void
    {
        Schema::create('shareholder_register_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
        });
        Schema::create('shareholder_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
        });
        try {
            DB::table('shareholder_register_accounts')->insert(['id' => 90, 'shareholder_id' => 15]);
            DB::table('shareholder_change_requests')->insert(['id' => 60, 'shareholder_id' => 15]);
            $actor = $this->createAdmin('related@example.com');
            $actor->givePermissionTo(Permission::create(['name' => 'reports.audit', 'guard_name' => 'web']));
            foreach ([['route_parameters' => ['sra_id' => '90']], ['route_parameters' => ['changeRequest' => 60]], ['route_parameters' => ['sra_id' => 15]], ['shareholder_ids' => [999], 'route_parameters' => ['sra_id' => 90]]] as $metadata) {
                UserActivityLog::create(['user_id' => $actor->id, 'action' => 'test', 'metadata' => $metadata]);
            }
            $this->actingAs($actor, 'sanctum')->getJson('/api/reports/audit?shareholder_id=15&user_id='.$actor->id)
                ->assertOk()->assertJsonPath('data.total', 2);
        } finally {
            Schema::dropIfExists('shareholder_change_requests');
            Schema::dropIfExists('shareholder_register_accounts');
        }
    }

    public function test_new_related_changes_capture_before_after_and_enrich_explicit_log_without_duplicates(): void
    {
        Schema::create('shareholder_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shareholder_id');
            $table->string('city');
            $table->string('secret_token')->nullable();
        });
        try {
            DB::table('shareholder_addresses')->insert(['id' => 1, 'shareholder_id' => 15, 'city' => 'Lagos', 'secret_token' => 'sensitive']);
            $actor = $this->createAdmin('changes@example.com');
            $actor->givePermissionTo(Permission::create(['name' => 'reports.audit', 'guard_name' => 'web']));
            Route::middleware(['api', 'auth:sanctum', LogApiActivity::class])
                ->put('/api/audit-test/address', function () use ($actor) {
                    $address = new class extends Model
                    {
                        protected $table = 'shareholder_addresses';

                        public $timestamps = false;
                    };
                    $address = $address->findOrFail(1);
                    $address->city = 'Abuja';
                    $address->secret_token = 'new-sensitive';
                    $address->save();
                    app(ActivityLogService::class)->log($actor->id, 'address_updated', ['reason' => 'Customer instruction']);

                    return response()->json(['success' => true]);
                });
            $this->actingAs($actor, 'sanctum')->putJson('/api/audit-test/address')->assertOk();
            $this->assertDatabaseCount('user_activity_logs', 1);
            $this->getJson('/api/reports/audit?shareholder_id=15')
                ->assertOk()->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.data.0.shareholder_ids', [15])
                ->assertJsonPath('data.data.0.changes.0.before.city', 'Lagos')
                ->assertJsonPath('data.data.0.changes.0.after.city', 'Abuja')
                ->assertJsonPath('data.data.0.metadata.reason', 'Customer instruction')
                ->assertJsonPath('data.data.0.request.method', 'PUT')
                ->assertDontSee('sensitive');
        } finally {
            Schema::dropIfExists('shareholder_addresses');
        }
    }

    public function test_actor_identity_and_role_are_preserved_after_admin_changes(): void
    {
        $actor = $this->createAdmin('original@example.com');
        $actor->assignRole(Role::create(['name' => 'Operations', 'guard_name' => 'web']));
        app(ActivityLogService::class)->log($actor->id, 'shareholder_updated', ['shareholder_id' => 15]);
        $actor->update(['email' => 'renamed@example.com']);
        $actor->syncRoles([]);
        $viewer = $this->createAdmin('viewer-audit@example.com');
        $viewer->givePermissionTo(Permission::create(['name' => 'reports.audit', 'guard_name' => 'web']));
        $this->actingAs($viewer, 'sanctum')->getJson('/api/reports/audit?shareholder_id=15&role=Operations')
            ->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.user.email', 'original@example.com')
            ->assertJsonPath('data.data.0.roles', ['Operations']);
    }

    public function test_failed_requests_do_not_record_successful_change_history(): void
    {
        $actor = $this->createAdmin('failed@example.com');
        Route::middleware(['api', 'auth:sanctum', LogApiActivity::class])
            ->put('/api/audit-test/failure/{shareholder}', fn () => response()->json(['message' => 'Rejected'], 422));
        $this->actingAs($actor, 'sanctum')->putJson('/api/audit-test/failure/15')->assertStatus(422);
        $this->assertDatabaseCount('user_activity_logs', 0);
    }

    public function test_role_filter_is_consistent_on_both_endpoints_and_pagination_preserves_it(): void
    {
        $viewer = $this->createAdmin('role-viewer@example.com');
        foreach (['reports.audit', 'user_activity_logs.view'] as $permission) {
            $viewer->givePermissionTo(Permission::create(['name' => $permission, 'guard_name' => 'web']));
        }
        $operations = $this->createAdmin('role-ops@example.com');
        $operations->assignRole(Role::create(['name' => 'Operations', 'guard_name' => 'web']));
        $audit = $this->createAdmin('role-audit@example.com');
        $audit->assignRole(Role::create(['name' => 'Audit', 'guard_name' => 'web']));
        foreach ([
            [$operations->id, []],
            [$audit->id, ['actor' => ['roles' => ['Operations']]]],
            [$operations->id, ['actor' => ['roles' => ['Audit']]]],
            [$audit->id, []],
            [$operations->id, ['actor' => ['roles' => []]]],
            [$operations->id, ['actor' => ['name' => 'Legacy actor without roles']]],
        ] as [$actorId, $metadata]) {
            UserActivityLog::create(['user_id' => $actorId, 'action' => 'test', 'metadata' => $metadata]);
        }
        foreach (['/api/reports/audit' => 'data.', '/api/user-activity-logs' => ''] as $endpoint => $prefix) {
            $response = $this->actingAs($viewer, 'sanctum')->getJson($endpoint.'?role=Operations&per_page=1')
                ->assertOk()->assertJsonPath($prefix.'total', 3);
            $next = $response->json($prefix.'next_page_url');
            parse_str(parse_url($next, PHP_URL_QUERY), $parameters);
            $this->assertSame('Operations', $parameters['role']);
            $this->assertSame('1', $parameters['per_page']);
            $this->getJson($endpoint.'?'.http_build_query($parameters))->assertOk()->assertJsonPath($prefix.'total', 3);
            $this->getJson($endpoint.'?role=Nonexistent')->assertOk()->assertJsonPath($prefix.'total', 0);
        }
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

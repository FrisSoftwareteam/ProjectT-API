<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ships the shareholder-change-approval permissions and roles as part of
 * `php artisan migrate` instead of a separate `db:seed` step, so a normal
 * deploy is enough on its own: Super Admin doesn't end up missing
 * approve_mandate just because nobody remembered to re-run the seeder.
 *
 * Safe to run repeatedly (firstOrCreate / permission checks throughout) and
 * safe on an environment that already ran RolesAndPermissionsSeeder by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'shareholder_change_requests.view',
            'shareholder_change_requests.create',
            'shareholder_change_requests.approve',
            'shareholder_change_requests.approve_mandate',
        ];

        // Also needed by the two new roles below; created defensively in case
        // this runs on an environment that never ran the base permissions seeder.
        $supportingPermissions = ['shareholders.view', 'notifications.view'];

        foreach ([...$permissions, ...$supportingPermissions] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdmin = Role::where(['name' => 'Super Admin', 'guard_name' => 'web'])->first();
        if ($superAdmin) {
            foreach ($permissions as $permission) {
                if (! $superAdmin->hasPermissionTo($permission)) {
                    $superAdmin->givePermissionTo($permission);
                }
            }
        }

        $admin = Role::where(['name' => 'Admin', 'guard_name' => 'web'])->first();
        if ($admin) {
            foreach ($permissions as $permission) {
                if (! $admin->hasPermissionTo($permission)) {
                    $admin->givePermissionTo($permission);
                }
            }
        }

        Role::firstOrCreate(['name' => 'Shareholder Change Approver', 'guard_name' => 'web'])
            ->syncPermissions([
                'shareholder_change_requests.view',
                'shareholder_change_requests.approve',
                'shareholders.view',
                'notifications.view',
            ]);

        Role::firstOrCreate(['name' => 'Bank Mandate Approver', 'guard_name' => 'web'])
            ->syncPermissions([
                'shareholder_change_requests.view',
                'shareholder_change_requests.approve_mandate',
                'shareholders.view',
                'notifications.view',
            ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately not reverting: dropping permissions/roles here could strip
        // access someone granted by hand after this ran. Manage removal manually
        // if this ever needs to be undone.
    }
};

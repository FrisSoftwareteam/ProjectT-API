<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CHN updates are now a real feature (previously just a reserved,
 * never-implemented request_type). Mirrors bank mandate's split: edit_chn
 * (submit, added in the previous migration) is separate from approve_chn
 * (decide), since CHN also affects CSCS reconciliation and dividend payment
 * matching — not something general approvers should sign off on by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'shareholder_change_requests.approve_chn', 'guard_name' => 'web']);

        foreach (['Super Admin', 'Admin'] as $roleName) {
            $role = Role::where(['name' => $roleName, 'guard_name' => 'web'])->first();
            if ($role && ! $role->hasPermissionTo('shareholder_change_requests.approve_chn')) {
                $role->givePermissionTo('shareholder_change_requests.approve_chn');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately not reverting — see the earlier permission-seeding
        // migrations for why.
    }
};

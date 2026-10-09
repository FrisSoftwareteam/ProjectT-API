<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PT-191: splits the broad personal-info editing permission into one
 * permission per information category, so an admin can grant "Name" without
 * granting "Address", etc. Ships as a migration (not just the seeder) so a
 * normal `php artisan migrate` is enough — same reasoning as the
 * shareholder-change-approval permissions migration before this one.
 *
 * Holding shareholder_change_requests.create or shareholders.edit (the
 * existing blanket permissions) still grants every category, so nobody who
 * already has broad access loses anything — these are a narrower,
 * additional way in, not a replacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'shareholder_change_requests.edit_name',
            'shareholder_change_requests.edit_address',
            'shareholder_change_requests.edit_date_of_birth',
            'shareholder_change_requests.edit_gender',
            'shareholder_change_requests.edit_email',
            'shareholder_change_requests.edit_phone',
            'shareholder_change_requests.edit_identification',
            // No CHN-update endpoint exists yet (nothing currently lets anyone
            // change an existing register account's CHN at all) — this
            // permission exists so it's ready and assignable, matching the
            // ticket's "add a separate permission for CHN details", but it
            // has nothing to gate until that endpoint is built.
            'shareholder_change_requests.edit_chn',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (['Super Admin', 'Admin'] as $roleName) {
            $role = Role::where(['name' => $roleName, 'guard_name' => 'web'])->first();
            if (! $role) {
                continue;
            }

            foreach ($permissions as $permission) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately not reverting: removing permissions here could strip
        // access someone granted by hand after this ran.
    }
};

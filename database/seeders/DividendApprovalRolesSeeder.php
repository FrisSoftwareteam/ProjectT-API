<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DividendApprovalRolesSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'dividends.view',
            'dividends.submit',
            'dividends.approve.it',
            'dividends.approve.oversight_ops',
            'dividends.approve.oversight_mf',
            'dividends.approve.accounts',
            'dividends.approve.audit',
            'dividends.reject',
            'dividends.query',
            'dividends.go_live',
            'dividends.delegate',
            'dividends.export',
            'dividends.reissue',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $roleMap = [
            'Head of IT' => [
                'dividends.view',
                'dividends.approve.it',
                'dividends.reject',
                'dividends.query',
            ],
            'Operations Approval Role' => [
                'dividends.view',
                'dividends.approve.oversight_ops',
                'dividends.reject',
                'dividends.query',
            ],
            'Mutual Funds Approval Role' => [
                'dividends.view',
                'dividends.approve.oversight_mf',
                'dividends.reject',
                'dividends.query',
            ],
            'Accounts' => [
                'dividends.view',
                'dividends.approve.accounts',
                'dividends.reject',
                'dividends.query',
                'dividends.export',
            ],
            'Audit' => [
                'dividends.view',
                'dividends.approve.audit',
                'dividends.reject',
                'dividends.query',
                'dividends.export',
            ],
            'Dividend Workflow Admin' => [
                'dividends.view',
                'dividends.submit',
                'dividends.go_live',
                'dividends.delegate',
                'dividends.export',
                'dividends.reissue',
            ],
        ];

        foreach ($roleMap as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);
            $role->syncPermissions($rolePermissions);
        }

        $this->command?->info('✓ Dividend approval roles seeded');
    }
}

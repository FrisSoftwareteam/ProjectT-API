<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Seeding roles and permissions...');

        // Create permissions (using firstOrCreate to avoid duplicates)
        $permissions = [
            // User Management
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.activate',
            'users.deactivate',

            // Shareholder Management
            'shareholders.view',
            'shareholders.create',
            'shareholders.edit',
            'shareholders.delete',
            'shareholders.export',

            // Share Management
            'shares.view',
            'shares.create',
            'shares.edit',
            'shares.delete',
            'shares.transfer',
            'shares.export',

            // Shareholder Mandate Management
            'shareholder_mandates.view',
            'shareholder_mandates.create',
            'shareholder_mandates.edit',
            'shareholder_mandates.delete',
            'shareholder_mandates.export',

            // Certificate Management
            'certificates.view',
            'certificates.create',
            'certificates.edit',
            'certificates.delete',
            'certificates.issue',
            'certificates.cancel',
            'certificates.export',

            // Warrant Management
            'warrants.view',
            'warrants.create',
            'warrants.edit',
            'warrants.delete',
            'warrants.exercise',
            'warrants.export',

            // Reporting
            'reports.view',
            'reports.generate',
            'reports.export',
            'reports.audit',

            // Notifications
            'notifications.view',
            'notifications.send',
            'notifications.manage',

            // System Administration
            'system.settings',
            'system.backup',
            'system.logs',
            'system.maintenance',

            // Role & Permission Management
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            'permissions.view',
            'permissions.assign',
            'permissions.create',
            'permissions.delete',
            'permissions.edit',

            // Audit Trail
            'audit.view',
            'audit.export',

            // Compliance
            'compliance.view',
            'compliance.manage',
            'compliance.reports',

            // Finance
            'finance.view',
            'finance.manage',
            'finance.reports',
            'finance.transactions',

            // Customer Service
            'support.tickets',
            'support.manage',
            'support.escalate',

            // Marketing
            'marketing.campaigns',
            'marketing.manage',
            'marketing.analytics',

            // Reconciliation
            'reconciliation.view',
            'reconciliation.process',
            'reconciliation.manage',

            // Internal Audit
            'audit.internal.view',
            'audit.internal.manage',
            'audit.internal.reports',

            // Mailing
            'mailing.view',
            'mailing.send',
            'mailing.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $this->command->info('Created ' . count($permissions) . ' permissions');

        // Create roles and assign permissions
        $this->createSuperAdminRole();
        $this->createAdminRole();
        $this->createShareholderManagementRole();
        $this->createCertificateManagementRole();
        $this->createWarrantManagementRole();
        $this->createCustomerServiceRole();
        $this->createCustomerSupportRole();
        $this->createFinanceRole();
        $this->createMarketingRole();
        $this->createComplianceRole();
        $this->createReconciliationRole();
        $this->createInternalAuditRole();
        $this->createMailingRole();

        $this->command->info('✓ Roles and permissions seeded successfully!');
    }

    private function createSuperAdminRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Super Admin',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions(Permission::all());
        $this->command->info('✓ Super Admin role created');
    }

    private function createAdminRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Admin',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'users.view', 'users.create', 'users.edit', 'users.activate', 'users.deactivate',
            'shareholders.view', 'shareholders.create', 'shareholders.edit', 'shareholders.export',
            'shares.view', 'shares.create', 'shares.edit', 'shares.export',
            'certificates.view', 'certificates.create', 'certificates.edit', 'certificates.issue',
            'warrants.view', 'warrants.create', 'warrants.edit',
            'reports.view', 'reports.generate', 'reports.export',
            'notifications.view', 'notifications.send',
            'audit.view', 'audit.export',
            'roles.view', 'permissions.view',
        ]);
    }

    private function createShareholderManagementRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Shareholder Management',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'shareholders.view', 'shareholders.create', 'shareholders.edit', 'shareholders.export',
            'shares.view', 'shares.create', 'shares.edit', 'shares.transfer', 'shares.export',
            'reports.view', 'reports.generate',
            'notifications.view', 'notifications.send',
        ]);
    }

    private function createCertificateManagementRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Certificate Management',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'certificates.view', 'certificates.create', 'certificates.edit', 'certificates.issue', 'certificates.cancel', 'certificates.export',
            'shareholders.view',
            'shares.view',
            'reports.view', 'reports.generate',
            'notifications.view', 'notifications.send',
        ]);
    }

    private function createWarrantManagementRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Warrant Management',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'warrants.view', 'warrants.create', 'warrants.edit', 'warrants.exercise', 'warrants.export',
            'shareholders.view',
            'shares.view',
            'reports.view', 'reports.generate',
            'notifications.view', 'notifications.send',
        ]);
    }

    private function createCustomerServiceRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Customer Service',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'shareholders.view',
            'shares.view',
            'certificates.view',
            'warrants.view',
            'reports.view',
            'notifications.view', 'notifications.send',
            'support.tickets', 'support.manage',
        ]);
    }

    private function createCustomerSupportRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Customer Support',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'shareholders.view',
            'shares.view',
            'certificates.view',
            'warrants.view',
            'reports.view',
            'notifications.view', 'notifications.send',
            'support.tickets', 'support.manage', 'support.escalate',
        ]);
    }

    private function createFinanceRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Finance',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'finance.view', 'finance.manage', 'finance.reports', 'finance.transactions',
            'shareholders.view',
            'shares.view', 'shares.transfer',
            'certificates.view',
            'warrants.view', 'warrants.exercise',
            'reports.view', 'reports.generate', 'reports.export',
            'notifications.view', 'notifications.send',
        ]);
    }

    private function createMarketingRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Marketing',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'marketing.campaigns', 'marketing.manage', 'marketing.analytics',
            'shareholders.view',
            'notifications.view', 'notifications.send', 'notifications.manage',
            'reports.view', 'reports.generate',
        ]);
    }

    private function createComplianceRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Compliance',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'compliance.view', 'compliance.manage', 'compliance.reports',
            'shareholders.view',
            'shares.view',
            'certificates.view',
            'warrants.view',
            'reports.view', 'reports.generate', 'reports.audit',
            'audit.view', 'audit.export',
            'notifications.view', 'notifications.send',
        ]);
    }

    private function createReconciliationRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Reconciliation',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'reconciliation.view', 'reconciliation.process', 'reconciliation.manage',
            'shareholders.view',
            'shares.view',
            'certificates.view',
            'warrants.view',
            'reports.view', 'reports.generate',
            'audit.view', 'audit.export',
        ]);
    }

    private function createInternalAuditRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Internal Audit',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'audit.internal.view', 'audit.internal.manage', 'audit.internal.reports',
            'shareholders.view',
            'shares.view',
            'certificates.view',
            'warrants.view',
            'reports.view', 'reports.generate', 'reports.audit',
            'audit.view', 'audit.export',
            'system.logs',
        ]);
    }

    private function createMailingRole()
    {
        $role = Role::firstOrCreate([
            'name' => 'Mailing',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            'mailing.view', 'mailing.send', 'mailing.manage',
            'shareholders.view',
            'notifications.view', 'notifications.send', 'notifications.manage',
            'reports.view',
        ]);
    }
}
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'make-sales',
            'view-products',
            'manage-products',
            'manage-categories',
            'adjust-stock',
            'view-cost-prices',
            'give-discounts',
            'void-sales',
            'view-reports',
            'manage-customers',
            'issue-invoices',
            'manage-users',
            'manage-settings',
            'record-expenses',
            'process-returns',
            'transfer-stock',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        Role::firstOrCreate([
            'name' => 'owner',
            'guard_name' => 'web',
        ])->syncPermissions($permissions);

        Role::firstOrCreate([
            'name' => 'manager',
            'guard_name' => 'web',
        ])->syncPermissions([
            'make-sales',
            'view-products',
            'manage-products',
            'manage-categories',
            'adjust-stock',
            'view-cost-prices',
            'give-discounts',
            'void-sales',
            'view-reports',
            'manage-customers',
            'issue-invoices',
            'record-expenses',
            'process-returns',
            'transfer-stock',
        ]);

        Role::firstOrCreate([
            'name' => 'cashier',
            'guard_name' => 'web',
        ])->syncPermissions([
            'make-sales',
            'view-products',
            'issue-invoices',
            'record-expenses',
            'manage-customers',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

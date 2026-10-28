<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Ensure roles and permissions are available.
        $this->call(RolesAndPermissionsSeeder::class);

        $users = [
            [
                'name' => 'Super Admin',
                'email' => 'admin@codekraft.co.ke',
                'password' => 'ChangeMe123!',
                'role' => null,
                'is_super_admin' => true,
            ],
            [
                'name' => 'Business Owner',
                'email' => 'owner@craftpos.test',
                'password' => 'ChangeMe123!',
                'role' => 'owner',
                'is_super_admin' => false,
            ],
            [
                'name' => 'Business Manager',
                'email' => 'manager@craftpos.test',
                'password' => 'ChangeMe123!',
                'role' => 'manager',
                'is_super_admin' => false,
            ],
            [
                'name' => 'Cashier',
                'email' => 'cashier@craftpos.test',
                'password' => 'ChangeMe123!',
                'role' => 'cashier',
                'is_super_admin' => false,
            ],
        ];

        foreach ($users as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => $data['password'],
                    'is_active' => true,
                    'is_super_admin' => $data['is_super_admin'],
                ]
            );

            // Clear previously assigned roles.
            $user->syncRoles([]);

            if ($data['role']) {
                $user->assignRole(
                    Role::findByName($data['role'], 'web')
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

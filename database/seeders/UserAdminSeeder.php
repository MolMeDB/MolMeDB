<?php

namespace Database\Seeders;

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Administrator',
            'email' => 'molmedb@upol.cz',
            'password' => Hash::make('admin'),
        ]);

        // Create default roles
        $default_roles = [
            RoleEnums::ADMIN->value,
            RoleEnums::MANAGER->value,
            RoleEnums::EDITOR->value,
            RoleEnums::VIEWER->value,
        ];

        foreach ($default_roles as $default_role) {
            Role::create(['name' => $default_role, 'guard_name' => 'web']);
        }

        $admin_role = Role::where('name', RoleEnums::ADMIN->value)->first();
        $admin->assignRole($admin_role);

        // Get all permission from PermissionEnum
        $permissions = PermissionEnums::cases();

        foreach ($permissions as $permission) {
            if (Permission::where('name', $permission->value)->where('guard_name', 'web')->doesntExist()) {
                $p = Permission::create(['name' => $permission->value, 'description' => $permission->description(), 'guard_name' => 'web']);
                // Assign to admin role
                $p->assignRole($admin_role);
            }
        }
    }
}

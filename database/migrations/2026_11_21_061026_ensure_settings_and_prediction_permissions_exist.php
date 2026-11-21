<?php

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * The settings, system and prediction resources of the admin panel are now
 * guarded by these permissions. A missing permission row would make every
 * admin page fail (Spatie throws PermissionDoesNotExist), so make sure they
 * exist and that administrators keep their access.
 */
return new class extends Migration
{
    /**
     * @var array<int, PermissionEnums>
     */
    private array $permissions = [
        PermissionEnums::SETTINGS_VIEW,
        PermissionEnums::SETTINGS_EDIT,
        PermissionEnums::PREDICTION_DATASET_MANAGE_ALL,
    ];

    public function up(): void
    {
        $adminRole = Role::query()
            ->where('name', RoleEnums::ADMIN->value)
            ->where('guard_name', 'web')
            ->first();

        foreach ($this->permissions as $permissionEnum) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionEnum->value,
                'guard_name' => 'web',
            ], [
                'description' => $permissionEnum->description(),
            ]);

            $adminRole?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The permissions existed before this migration, so nothing is removed.
     */
    public function down(): void {}
};

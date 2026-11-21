<?php

use App\Enums\PermissionEnums;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * The settings, system and prediction resources of the admin panel were
 * open to every role that may enter the panel; they are now guarded by
 * these permissions. Every role holding admin.panel at this point gets them,
 * so nobody loses access they had, and administrators can now take it away
 * per role. Roles created later get them only when assigned explicitly.
 *
 * The permissions are also created when missing: a missing permission row
 * would make every admin page fail (Spatie throws PermissionDoesNotExist).
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
        $permissions = collect($this->permissions)->map(
            fn (PermissionEnums $permissionEnum): Permission => Permission::query()->firstOrCreate([
                'name' => $permissionEnum->value,
                'guard_name' => 'web',
            ], [
                'description' => $permissionEnum->description(),
            ]),
        );

        Role::query()
            ->where('guard_name', 'web')
            ->whereHas('permissions', fn ($query) => $query->where('name', PermissionEnums::ADMIN_PANEL->value))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Roles may have been given or refused these permissions on purpose since,
     * so nothing is taken back.
     */
    public function down(): void {}
};

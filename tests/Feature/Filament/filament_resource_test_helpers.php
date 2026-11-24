<?php

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission and role the application knows, as the seeders do.
 */
function seedFilamentResourcePermissions(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    collect(PermissionEnums::cases())->each(function (PermissionEnums $permissionEnum): void {
        Permission::query()->firstOrCreate(
            ['name' => $permissionEnum->value, 'guard_name' => 'web'],
            ['description' => $permissionEnum->description()],
        );
    });

    collect(RoleEnums::cases())->each(function (RoleEnums $roleEnum): void {
        Role::query()->firstOrCreate(['name' => $roleEnum->value, 'guard_name' => 'web']);
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * A user of a role of their own that may enter the admin panel and holds the
 * given permissions on top of it.
 *
 * @param  array<int, PermissionEnums>  $permissions
 */
function createFilamentResourceUser(array $permissions = []): User
{
    $role = Role::query()->create([
        'name' => 'Resource role '.fake()->unique()->numberBetween(1, 10_000_000),
        'guard_name' => 'web',
    ]);

    $role->givePermissionTo([
        PermissionEnums::ADMIN_PANEL->value,
        ...array_map(fn (PermissionEnums $permission): string => $permission->value, $permissions),
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/**
 * Points the public disk at a directory of its own, so that the files of these
 * tests never meet the fake disks other test runs share.
 */
function useOwnPublicDiskForFilamentResourceTests(): string
{
    $root = storage_path('framework/testing/disks/filament-resources-'.getmypid());

    config()->set('filesystems.disks.public', [
        'driver' => 'local',
        'root' => $root,
        'visibility' => 'public',
    ]);
    Storage::forgetDisk('public');

    return $root;
}

function removeOwnPublicDiskForFilamentResourceTests(string $root): void
{
    Storage::forgetDisk('public');
    \Illuminate\Support\Facades\File::deleteDirectory($root);
}

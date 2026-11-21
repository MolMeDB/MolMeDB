<?php

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Filament\Clusters\Access\Resources\Roles\Pages\EditRole;
use App\Filament\Clusters\Access\Resources\Roles\RelationManagers\PermissionsRelationManager;
use App\Filament\Clusters\Access\Resources\Users\Pages\EditUser;
use App\Filament\Clusters\Access\Resources\Users\RelationManagers\RolesRelationManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Managing roles must not let anyone raise their own rights: a user may only
 * grant permissions, and roles, whose permissions they hold themselves, and
 * only administrators hand out the Administrator role.
 */

beforeEach(function () {
    Notification::fake();

    collect(PermissionEnums::cases())->each(fn (PermissionEnums $permission) => Permission::query()->firstOrCreate(
        ['name' => $permission->value, 'guard_name' => 'web'],
        ['description' => $permission->description()],
    ));
    collect(RoleEnums::cases())->each(fn (RoleEnums $role) => Role::query()->firstOrCreate(['name' => $role->value, 'guard_name' => 'web']));

    Role::findByName(RoleEnums::ADMIN->value)->syncPermissions(PermissionEnums::cases());
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A user of the given role, which holds the given permissions besides
 * entering the panel.
 *
 * @param  array<int, PermissionEnums>  $permissions
 */
function userOfRole(RoleEnums $roleEnum, array $permissions): User
{
    $role = Role::findByName($roleEnum->value);
    $role->syncPermissions([PermissionEnums::ADMIN_PANEL, ...$permissions]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function permissionId(PermissionEnums $permission): int
{
    return Permission::findByName($permission->value)->id;
}

test('a role manager cannot grant permissions they do not hold, not even to their own role', function () {
    $manager = userOfRole(RoleEnums::MANAGER, [PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT, PermissionEnums::DATASET_EDIT]);
    $ownRole = Role::findByName(RoleEnums::MANAGER->value);

    $this->actingAs($manager);

    Livewire::test(PermissionsRelationManager::class, ['ownerRecord' => $ownRole, 'pageClass' => EditRole::class])
        ->callTableAction('attach', data: ['recordId' => [permissionId(PermissionEnums::SSH_CREDENTIALS_MANAGE)]]);

    expect($ownRole->refresh()->hasPermissionTo(PermissionEnums::SSH_CREDENTIALS_MANAGE->value))->toBeFalse();
});

test('a role manager may grant a permission they hold to another role', function () {
    $manager = userOfRole(RoleEnums::MANAGER, [PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT, PermissionEnums::DATASET_EDIT]);
    $viewer = Role::findByName(RoleEnums::VIEWER->value);

    $this->actingAs($manager);

    Livewire::test(PermissionsRelationManager::class, ['ownerRecord' => $viewer, 'pageClass' => EditRole::class])
        ->callTableAction('attach', data: ['recordId' => [permissionId(PermissionEnums::DATASET_EDIT)]])
        ->assertHasNoTableActionErrors();

    expect($viewer->refresh()->hasPermissionTo(PermissionEnums::DATASET_EDIT->value))->toBeTrue();
});

test('administrators may grant any permission', function () {
    $admin = User::factory()->create();
    $admin->syncRoles([RoleEnums::ADMIN->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $viewer = Role::findByName(RoleEnums::VIEWER->value);

    $this->actingAs($admin->refresh());

    Livewire::test(PermissionsRelationManager::class, ['ownerRecord' => $viewer, 'pageClass' => EditRole::class])
        ->callTableAction('attach', data: ['recordId' => [permissionId(PermissionEnums::SSH_CREDENTIALS_MANAGE)]]);

    expect($viewer->refresh()->hasPermissionTo(PermissionEnums::SSH_CREDENTIALS_MANAGE->value))->toBeTrue();
});

test('a role assigner cannot hand out the Administrator role, not even bypassing the role list', function () {
    $assigner = userOfRole(RoleEnums::MANAGER, [PermissionEnums::USERS_VIEW, PermissionEnums::USERS_EDIT, PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_ASSIGN]);
    $target = User::factory()->create();

    $this->actingAs($assigner);

    Livewire::test(RolesRelationManager::class, ['ownerRecord' => $target, 'pageClass' => EditUser::class])
        ->callTableAction('attach', data: ['recordId' => Role::findByName(RoleEnums::ADMIN->value)->id]);

    expect($target->refresh()->hasRole(RoleEnums::ADMIN->value))->toBeFalse();
});

test('a role assigner cannot hand out a role with permissions they do not hold', function () {
    $assigner = userOfRole(RoleEnums::MANAGER, [PermissionEnums::USERS_VIEW, PermissionEnums::USERS_EDIT, PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_ASSIGN]);
    Role::findByName(RoleEnums::EDITOR->value)->syncPermissions([PermissionEnums::ADMIN_PANEL, PermissionEnums::SSH_CREDENTIALS_MANAGE]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($assigner);

    Livewire::test(RolesRelationManager::class, ['ownerRecord' => $assigner, 'pageClass' => EditUser::class])
        ->callTableAction('attach', data: ['recordId' => Role::findByName(RoleEnums::EDITOR->value)->id]);

    expect($assigner->refresh()->hasRole(RoleEnums::EDITOR->value))->toBeFalse();
});

test('a role assigner may hand out a role within their own permissions', function () {
    $assigner = userOfRole(RoleEnums::MANAGER, [PermissionEnums::USERS_VIEW, PermissionEnums::USERS_EDIT, PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_ASSIGN]);
    Role::findByName(RoleEnums::EDITOR->value)->syncPermissions([PermissionEnums::ADMIN_PANEL]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $target = User::factory()->create();

    $this->actingAs($assigner);

    Livewire::test(RolesRelationManager::class, ['ownerRecord' => $target, 'pageClass' => EditUser::class])
        ->callTableAction('attach', data: ['recordId' => Role::findByName(RoleEnums::EDITOR->value)->id])
        ->assertHasNoTableActionErrors();

    expect($target->refresh()->hasRole(RoleEnums::EDITOR->value))->toBeTrue();
});

test('nobody can remove their own roles', function () {
    $assigner = userOfRole(RoleEnums::MANAGER, [PermissionEnums::USERS_VIEW, PermissionEnums::USERS_EDIT, PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_ASSIGN]);

    $this->actingAs($assigner);

    Livewire::test(RolesRelationManager::class, ['ownerRecord' => $assigner, 'pageClass' => EditUser::class])
        ->assertTableActionHidden('detach', Role::findByName(RoleEnums::MANAGER->value));
});

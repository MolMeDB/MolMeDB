<?php

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Filament\Clusters\Access\Resources\Permissions\Pages\CreatePermission;
use App\Filament\Clusters\Access\Resources\Permissions\Pages\EditPermission;
use App\Filament\Clusters\Access\Resources\Permissions\PermissionResource;
use App\Filament\Clusters\Access\Resources\Permissions\RelationManagers\RolesRelationManager as PermissionRolesRelationManager;
use App\Filament\Clusters\Access\Resources\Roles\Pages\EditRole;
use App\Filament\Clusters\Access\Resources\Roles\Pages\ListRoles;
use App\Filament\Clusters\Access\Resources\Roles\RelationManagers\UsersRelationManager;
use App\Filament\Clusters\Access\Resources\Users\Pages\CreateUser;
use App\Filament\Clusters\Access\Resources\Users\Pages\EditUser;
use App\Filament\Clusters\Access\Resources\Users\RelationManagers\LogsRelationManager;
use App\Filament\Clusters\Access\Resources\Users\UserResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SshCredential;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Users, roles and permissions: who sees a user's activity and a role's
 * members, what the role pages let editors do, and which pages stay
 * unreachable because they would hand out rights without any check.
 */

beforeEach(function () {
    collect(PermissionEnums::cases())->each(fn (PermissionEnums $permission) => Permission::query()->firstOrCreate(
        ['name' => $permission->value, 'guard_name' => 'web'],
        ['description' => $permission->description()],
    ));
    collect(RoleEnums::cases())->each(fn (RoleEnums $role) => Role::query()->firstOrCreate(['name' => $role->value, 'guard_name' => 'web']));

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @param  array<int, PermissionEnums>  $permissions
 */
function accessPanelUser(array $permissions = []): User
{
    $role = Role::query()->create(['name' => 'Access role '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo([PermissionEnums::ADMIN_PANEL, ...$permissions]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

test("a user's activity log is shown only to holders of the settings permissions", function () {
    $target = User::factory()->create();

    $this->actingAs(accessPanelUser([PermissionEnums::USERS_VIEW, PermissionEnums::USERS_EDIT]));
    expect(LogsRelationManager::canViewForRecord($target, EditUser::class))->toBeFalse();

    $this->actingAs(accessPanelUser([PermissionEnums::USERS_VIEW, PermissionEnums::USERS_EDIT, PermissionEnums::SETTINGS_VIEW]));
    expect(LogsRelationManager::canViewForRecord($target, EditUser::class))->toBeTrue();
});

test("a user's activity log lists what that user did, newest first, linking to the changed records", function () {
    $target = User::factory()->create();
    $editedUser = User::factory()->create();
    $credential = SshCredential::query()->create(['name' => 'Backup', 'username' => 'deploy', 'type' => SshCredential::AUTH_TYPE_PASSWORD, 'password' => 'p']);

    $older = activity()->causedBy($target)->performedOn($editedUser)->event('edited')->log('Edited a user');
    $older->forceFill(['created_at' => now()->subHour()])->save();
    $newer = activity()->causedBy($target)->performedOn($credential)->event('deleted')->log('Deleted a credential');
    $othersEntry = activity()->causedBy($editedUser)->event('created')->log('Someone else');

    $this->actingAs(accessPanelUser([PermissionEnums::USERS_VIEW, PermissionEnums::USERS_EDIT, PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(LogsRelationManager::class, ['ownerRecord' => $target, 'pageClass' => EditUser::class])
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertCanNotSeeTableRecords([$othersEntry])
        ->assertTableColumnFormattedStateSet('subject_type', 'User ['.$editedUser->id.']', $older)
        ->assertTableColumnFormattedStateSet('subject_type', 'SshCredential ['.$credential->id.']', $newer)
        ->assertSee(UserResource::getUrl('edit', ['record' => $editedUser]), escape: false)
        ->searchTable('deleted')
        ->assertCanSeeTableRecords([$newer])
        ->assertCanNotSeeTableRecords([$older]);
});

test("a role's members are shown only to those who may view users", function () {
    $role = Role::findByName(RoleEnums::EDITOR->value);

    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT]));
    expect(UsersRelationManager::canViewForRecord($role, EditRole::class))->toBeFalse();

    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT, PermissionEnums::USERS_VIEW]));
    expect(UsersRelationManager::canViewForRecord($role, EditRole::class))->toBeTrue();
});

test("a role's member list shows its users and offers no way to add, remove or change them", function () {
    $role = Role::query()->create(['name' => 'Curator', 'guard_name' => 'web']);
    $member = User::factory()->create(['name' => 'Role Member']);
    $member->assignRole($role);
    $outsider = User::factory()->create();

    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT, PermissionEnums::USERS_VIEW, PermissionEnums::USERS_EDIT]));

    Livewire::test(UsersRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditRole::class])
        ->assertCanSeeTableRecords([$member])
        ->assertCanNotSeeTableRecords([$outsider])
        ->assertTableActionDoesNotExist('attach')
        ->assertTableActionDoesNotExist('detach')
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit');
});

test('the permission roles manager, which attaches roles without any check, has no page to appear on', function () {
    $permission = Permission::findByName(PermissionEnums::SETTINGS_EDIT->value);
    $everything = accessPanelUser(PermissionEnums::cases());

    $this->actingAs($everything);

    $routedPageClasses = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->getActionName())
        ->filter(fn (string $action): bool => str_contains($action, 'Access\\Resources\\Permissions'))
        ->values()
        ->all();

    expect(array_keys(PermissionResource::getPages()))->toBe(['index'])
        ->and(PermissionResource::getRelations())->toContain(PermissionRolesRelationManager::class)
        ->and($routedPageClasses)->not->toContain(EditPermission::class)
        ->and($routedPageClasses)->not->toContain(CreatePermission::class)
        ->and(PermissionResource::canEdit($permission))->toBeFalse()
        ->and(PermissionResource::canCreate())->toBeFalse();

    Livewire::test(EditPermission::class, ['record' => $permission->getKey()])->assertForbidden();
    Livewire::test(CreatePermission::class)->assertForbidden();
});

test('the user creation page, which sets a fixed password, is not routed', function () {
    $routedActions = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->getActionName())
        ->all();

    expect(UserResource::hasPage('create'))->toBeFalse()
        ->and($routedActions)->not->toContain(CreateUser::class);
});

test('user editors cannot change the name or e-mail of another user', function () {
    $target = User::factory()->create(['name' => 'Original Name', 'affiliation' => 'Original Lab']);
    $originalEmail = $target->email;

    $this->actingAs(accessPanelUser([PermissionEnums::USERS_VIEW, PermissionEnums::USERS_EDIT]));

    Livewire::test(EditUser::class, ['record' => $target->getKey()])
        ->assertFormFieldIsDisabled('name')
        ->assertFormFieldIsDisabled('affiliation')
        ->assertFormFieldIsDisabled('email')
        ->set('data.name', 'Changed Name')
        ->set('data.affiliation', 'Changed Lab')
        ->set('data.email', 'changed@example.org')
        ->call('save');

    expect($target->refresh())
        ->name->toBe('Original Name')
        ->affiliation->toBe('Original Lab')
        ->email->toBe($originalEmail);
});

test('users change their own name and affiliation but not their e-mail', function () {
    $user = accessPanelUser([PermissionEnums::USERS_VIEW]);
    $originalEmail = $user->email;

    $this->actingAs($user);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['name' => 'jane curator', 'affiliation' => 'Palacký University'])
        ->set('data.email', 'changed@example.org')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh())
        ->name->toBe('Jane Curator')
        ->affiliation->toBe('Palacký University')
        ->email->toBe($originalEmail);
});

test('the default roles keep their names even when a new one is sent', function () {
    $admin = Role::findByName(RoleEnums::ADMIN->value);

    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT]));

    Livewire::test(EditRole::class, ['record' => $admin->getKey()])
        ->set('data.name', 'Renamed Administrator')
        ->call('save');

    expect($admin->refresh()->name)->toBe(RoleEnums::ADMIN->value);
});

test('role editors rename a custom role', function () {
    $role = Role::query()->create(['name' => 'Curator', 'guard_name' => 'web']);

    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT]));

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'Senior Curator'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($role->refresh()->name)->toBe('Senior Curator');
});

test('a custom role can be deleted from its page only by holders of the roles delete permission', function () {
    $role = Role::query()->create(['name' => 'Curator', 'guard_name' => 'web']);

    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT]));
    Livewire::test(EditRole::class, ['record' => $role->getKey()])->assertActionHidden(DeleteAction::class);

    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT, PermissionEnums::ROLES_DELETE]));
    Livewire::test(EditRole::class, ['record' => $role->getKey()])->callAction(DeleteAction::class);

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

test('the default roles offer no delete action on their page', function () {
    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT, PermissionEnums::ROLES_DELETE]));

    Livewire::test(EditRole::class, ['record' => Role::findByName(RoleEnums::EDITOR->value)->getKey()])
        ->assertActionHidden(DeleteAction::class);
});

test('role pages reload the permission cache on request', function (string $page, array $parameters) {
    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT]));

    $registrar = app(PermissionRegistrar::class);
    $registrar->getPermissions();
    DB::table('permissions')->insert(['name' => 'added.behind.the.cache', 'guard_name' => 'web', 'description' => 'Added directly', 'created_at' => now(), 'updated_at' => now()]);

    expect($registrar->getPermissions()->pluck('name'))->not->toContain('added.behind.the.cache');

    Livewire::test($page, $parameters)
        ->callAction('reloadPermissions')
        ->assertNotified('Permissions cache flushed');

    expect(app(PermissionRegistrar::class)->getPermissions()->pluck('name'))->toContain('added.behind.the.cache');
})->with([
    'role list' => fn (): array => [ListRoles::class, []],
    'role page' => fn (): array => [EditRole::class, ['record' => Role::findByName(RoleEnums::EDITOR->value)->getKey()]],
]);

test('only role editors see the create action on the role list', function () {
    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW]));
    Livewire::test(ListRoles::class)->assertActionHidden('create');

    $this->actingAs(accessPanelUser([PermissionEnums::ROLES_VIEW, PermissionEnums::ROLES_EDIT]));
    Livewire::test(ListRoles::class)->assertActionVisible('create');
});

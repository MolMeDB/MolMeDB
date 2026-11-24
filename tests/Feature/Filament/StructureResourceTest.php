<?php

require_once __DIR__.'/../Structures/structure_rdkit_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Filament\Resources\Structures\Pages\CreateStructure;
use App\Filament\Resources\Structures\Pages\EditStructure;
use App\Filament\Resources\Structures\Pages\ListStructures;
use App\Filament\Resources\Structures\StructureResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Structure;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    collect(PermissionEnums::cases())->each(function (PermissionEnums $permissionEnum): void {
        Permission::query()->firstOrCreate([
            'name' => $permissionEnum->value,
            'guard_name' => 'web',
        ], [
            'description' => $permissionEnum->description(),
        ]);
    });

    collect(RoleEnums::cases())->each(function (RoleEnums $roleEnum): void {
        Role::query()->firstOrCreate([
            'name' => $roleEnum->value,
            'guard_name' => 'web',
        ]);
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function createStructureAdmin(array $permissions = []): User
{
    $adminRole = Role::query()->firstOrCreate([
        'name' => RoleEnums::ADMIN->value,
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$adminRole]);

    if ($permissions !== []) {
        $adminRole->givePermissionTo(
            Permission::query()->whereIn('name', $permissions)->get(),
        );
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function createStructureRecord(User $owner, array $attributes = []): Structure
{
    return Structure::factory()->create([
        'user_id' => $owner->id,
        'identifier' => 'MM'.fake()->unique()->numberBetween(1000, 9999),
        'canonical_smiles' => 'CCO',
        ...$attributes,
    ]);
}

afterEach(function () {
    resetApiRouteRdkitState();
});

test('user with structure view permission can load the structures list page', function () {
    $user = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW->value,
    ]);

    $this->actingAs($user);

    Livewire::test(ListStructures::class)
        ->assertOk();
});

test('user without structure view permission cannot load the structures list page', function () {
    $user = createStructureAdmin();

    $this->actingAs($user);

    Livewire::test(ListStructures::class)
        ->assertForbidden();
});

test('user with structure view own permission sees only own structures in the list', function () {
    $user = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW_OWN->value,
    ]);
    $otherUser = createStructureAdmin();

    $ownStructure = createStructureRecord($user, [
        'identifier' => 'MM1001',
    ]);
    $foreignStructure = createStructureRecord($otherUser, [
        'identifier' => 'MM2002',
    ]);

    $this->actingAs($user);

    Livewire::test(ListStructures::class)
        ->assertOk()
        ->assertSee($ownStructure->identifier)
        ->assertDontSee($foreignStructure->identifier);
});

test('structures list page displays existing structures for authorized user', function () {
    $user = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW->value,
    ]);
    $structure = createStructureRecord($user, [
        'identifier' => 'MM3003',
    ]);

    $this->actingAs($user);

    Livewire::test(ListStructures::class)
        ->assertOk()
        ->assertSee((string) $structure->id)
        ->assertSee($structure->identifier)
        ->assertSee($structure->canonical_smiles);
});

test('user with structure edit own permission can load the create structure page', function () {
    $user = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW_OWN->value,
        PermissionEnums::STRUCTURE_EDIT_OWN->value,
    ]);

    $this->actingAs($user);

    Livewire::test(CreateStructure::class)
        ->assertOk()
        ->assertFormFieldExists('canonical_smiles');
});

test('user without structure edit permission cannot load the create structure page', function () {
    $user = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW_OWN->value,
    ]);

    $this->actingAs($user);

    Livewire::test(CreateStructure::class)
        ->assertForbidden();
});

test('user with structure edit own permission can create a structure', function () {
    $user = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW_OWN->value,
        PermissionEnums::STRUCTURE_EDIT_OWN->value,
    ]);

    $this->actingAs($user);

    // Saving computes the structure by RDKit, whatever the form holds.
    fakeRdkitService();
    FakeRdkitService::$info = ['CCO' => [
        'canonized_smiles' => 'CCO',
        'inchi' => 'InChI=1S/C2H6O/c1-2-3/h3H,2H2,1H3',
        'inchikey' => 'LFQSCWFLJHTTHZ-UHFFFAOYSA-N',
        'MW' => 46.0684,
        'LogP' => -0.3081,
    ]];

    Livewire::test(CreateStructure::class)
        ->fillForm([
            'canonical_smiles' => 'CCO',
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    $structure = Structure::query()
        ->where('canonical_smiles', 'CCO')
        ->latest('id')
        ->first();

    expect($structure)->not->toBeNull()
        ->and($structure->user_id)->toBe($user->id)
        ->and($structure->identifier)->not->toBeNull();
});

test('user with structure edit own permission can load the edit page for own structure', function () {
    $user = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW_OWN->value,
        PermissionEnums::STRUCTURE_EDIT_OWN->value,
    ]);
    $structure = createStructureRecord($user, [
        'identifier' => 'MM4004',
    ]);

    $this->actingAs($user);

    Livewire::test(EditStructure::class, [
        'record' => $structure->getKey(),
    ])
        ->assertOk()
        ->assertFormSet([
            'identifier' => $structure->identifier,
            'canonical_smiles' => $structure->canonical_smiles,
        ]);
});

test('user with structure edit own permission gets not found for foreign structure when only own structures are visible', function () {
    $user = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW_OWN->value,
        PermissionEnums::STRUCTURE_EDIT_OWN->value,
    ]);
    $owner = createStructureAdmin();
    $structure = createStructureRecord($owner);

    $this->actingAs($user);

    // Filament v5's EditRecord::authorizeAccess() does
    // abort_unless(canEdit($record), 403) — a hardcoded 403, not 404.
    $this->get(StructureResource::getUrl('edit', ['record' => $structure]))
        ->assertForbidden();
});

test('user with structure view permission and structure edit own permission cannot load the edit page for foreign structure', function () {
    $user = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW->value,
        PermissionEnums::STRUCTURE_EDIT_OWN->value,
    ]);
    $owner = createStructureAdmin();
    $structure = createStructureRecord($owner);

    $this->actingAs($user);

    $this->get(StructureResource::getUrl('edit', ['record' => $structure]))
        ->assertForbidden();
});

test('structure policy allows own and global permissions correctly', function () {
    $owner = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW_OWN->value,
        PermissionEnums::STRUCTURE_EDIT_OWN->value,
        PermissionEnums::STRUCTURE_DELETE_OWN->value,
    ]);
    $ownStructure = createStructureRecord($owner);
    $foreignOwner = createStructureAdmin();
    $foreignStructure = createStructureRecord($foreignOwner);

    expect($owner->can('view', $ownStructure))->toBeTrue()
        ->and($owner->can('update', $ownStructure))->toBeTrue()
        ->and($owner->can('delete', $ownStructure))->toBeTrue()
        ->and($owner->can('view', $foreignStructure))->toBeFalse()
        ->and($owner->can('update', $foreignStructure))->toBeFalse()
        ->and($owner->can('delete', $foreignStructure))->toBeFalse();

    $globalUser = createStructureAdmin([
        PermissionEnums::STRUCTURE_VIEW->value,
        PermissionEnums::STRUCTURE_EDIT->value,
        PermissionEnums::STRUCTURE_DELETE->value,
    ]);

    expect($globalUser->can('view', $foreignStructure))->toBeTrue()
        ->and($globalUser->can('create', Structure::class))->toBeTrue()
        ->and($globalUser->can('update', $foreignStructure))->toBeTrue()
        ->and($globalUser->can('delete', $foreignStructure))->toBeTrue()
        ->and($globalUser->can('restore', $foreignStructure))->toBeTrue();
});

test('structure resource exposes index create and edit pages', function () {
    expect(StructureResource::getPages())
        ->toHaveKeys(['index', 'create', 'edit']);
});

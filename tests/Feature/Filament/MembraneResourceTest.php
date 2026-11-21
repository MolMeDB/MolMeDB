<?php

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Filament\Resources\Membranes\MembraneResource;
use App\Filament\Resources\Membranes\Pages\CreateMembrane;
use App\Filament\Resources\Membranes\Pages\EditMembrane;
use App\Filament\Resources\Membranes\Pages\ListMembranes;
use App\Models\Category;
use App\Models\Membrane;
use App\Models\Permission;
use App\Models\Role;
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

function createMembraneAdmin(array $permissions = []): User
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

function createMembraneResourceCategory(array $attributes = []): Category
{
    return Category::factory()->create([
        'title' => 'Phospholipid bilayers',
        'type' => Category::TYPE_MEMBRANE,
        ...$attributes,
    ]);
}

function createMembrane(array $attributes = []): Membrane
{
    $membrane = Membrane::factory()->create([
        'name' => 'DOPC bilayer',
        'abbreviation' => 'DOPC',
        'description' => 'Reference membrane for permeability studies.',
        ...$attributes,
    ]);

    $membrane->categories()->syncWithPivotValues(
        [createMembraneResourceCategory()->id],
        ['model_type' => Membrane::class],
    );

    return $membrane->refresh();
}

test('user with membrane method view permission can load the membranes list page', function () {
    $user = createMembraneAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
    ]);

    $this->actingAs($user);

    Livewire::test(ListMembranes::class)
        ->assertOk();
});

test('user without membrane method view permission cannot load the membranes list page', function () {
    $user = createMembraneAdmin();

    $this->actingAs($user);

    Livewire::test(ListMembranes::class)
        ->assertForbidden();
});

test('membranes list page displays existing membranes for authorized user', function () {
    $user = createMembraneAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
    ]);
    $membrane = createMembrane();

    $this->actingAs($user);

    Livewire::test(ListMembranes::class)
        ->assertOk()
        ->assertSee($membrane->name)
        ->assertSee($membrane->abbreviation);
});

test('user with membrane method view and edit permission can load the create membrane page', function () {
    $user = createMembraneAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
    ]);
    $category = createMembraneResourceCategory();

    $this->actingAs($user);

    Livewire::test(CreateMembrane::class)
        ->assertOk()
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('abbreviation')
        ->assertFormFieldExists('categories');
});

test('user without membrane method edit permission cannot load the create membrane page', function () {
    $user = createMembraneAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
    ]);
    createMembraneResourceCategory();

    $this->actingAs($user);

    Livewire::test(CreateMembrane::class)
        ->assertForbidden();
});

test('user with membrane method edit permission can create a membrane', function () {
    $user = createMembraneAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
    ]);
    $category = createMembraneResourceCategory();

    $this->actingAs($user);

    Livewire::test(CreateMembrane::class)
        ->fillForm([
            'type' => Membrane::TYPE_PUBCHEM_LOGP,
            'name' => 'DPPC bilayer',
            'abbreviation' => 'DPPC',
            'description' => 'Curated membrane for diffusion tests.',
            'categories' => [$category->id],
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    $membrane = Membrane::query()
        ->where('name', 'DPPC bilayer')
        ->first();

    expect($membrane)->not->toBeNull()
        ->and($membrane->abbreviation)->toBe('DPPC')
        ->and($membrane->categories()->whereKey($category->id)->exists())->toBeTrue();
});

test('user with membrane method view and edit permission can load the edit membrane page', function () {
    $user = createMembraneAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
    ]);
    $membrane = createMembrane();

    $this->actingAs($user);

    Livewire::test(EditMembrane::class, [
        'record' => $membrane->getKey(),
    ])
        ->assertOk()
        ->assertFormSet([
            'name' => $membrane->name,
            'abbreviation' => $membrane->abbreviation,
        ])
        // Whether the editor wraps plain text in a paragraph depends on the
        // HTML parser of the PHP build, so only the text itself is looked for
        // in the editor's document.
        ->assertFormSet(function (array $state) use ($membrane): void {
            expect(json_encode($state['description'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->toContain($membrane->description);
        });
});

test('user without membrane method edit permission cannot load the edit membrane page', function () {
    $user = createMembraneAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
    ]);
    $membrane = createMembrane();

    $this->actingAs($user);

    Livewire::test(EditMembrane::class, [
        'record' => $membrane->getKey(),
    ])
        ->assertForbidden();
});

test('user with membrane method edit permission can update a membrane', function () {
    $user = createMembraneAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
    ]);
    $membrane = createMembrane();
    $category = createMembraneResourceCategory([
        'title' => 'Sterol-rich membranes',
    ]);

    $this->actingAs($user);

    Livewire::test(EditMembrane::class, [
        'record' => $membrane->getKey(),
    ])
        ->fillForm([
            'type' => $membrane->type,
            'name' => 'Updated membrane',
            'abbreviation' => 'UPDMB',
            'description' => 'Updated membrane description.',
            'categories' => [$category->id],
        ])
        ->call('save')
        ->assertNotified();

    $updatedMembrane = $membrane->fresh();

    expect($updatedMembrane)
        ->name->toBe('Updated membrane')
        ->abbreviation->toBe('UPDMB');

    expect($updatedMembrane->categories()->whereKey($category->id)->exists())->toBeTrue();
});

test('membrane policy allows global view create update and delete permissions', function () {
    $user = createMembraneAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
        PermissionEnums::MEMBRANE_METHOD_DELETE->value,
    ]);
    $membrane = createMembrane();

    expect($user->can('view', $membrane))->toBeTrue()
        ->and($user->can('create', Membrane::class))->toBeTrue()
        ->and($user->can('update', $membrane))->toBeTrue()
        ->and($user->can('delete', $membrane))->toBeTrue()
        ->and($user->can('restore', $membrane))->toBeTrue();
});

test('membrane resource exposes index create and edit pages', function () {
    expect(MembraneResource::getPages())
        ->toHaveKeys(['index', 'create', 'edit']);
});

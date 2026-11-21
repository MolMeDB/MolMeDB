<?php

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Filament\Resources\Methods\MethodResource;
use App\Filament\Resources\Methods\Pages\CreateMethod;
use App\Filament\Resources\Methods\Pages\EditMethod;
use App\Filament\Resources\Methods\Pages\ListMethods;
use App\Models\Category;
use App\Models\Method;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\ValueObjects\MethodParameters;
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

function createMethodAdmin(array $permissions = []): User
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

function createMethodResourceCategory(array $attributes = []): Category
{
    return Category::factory()->create([
        'title' => 'Permeability methods',
        'type' => Category::TYPE_METHOD,
        ...$attributes,
    ]);
}

function createMethod(array $attributes = []): Method
{
    $parameters = $attributes['parameters'] ?? new MethodParameters([]);

    if (is_array($parameters)) {
        $parameters = new MethodParameters($parameters);
    }

    $attributes['parameters'] = $parameters;

    $method = Method::factory()->create([
        'name' => 'Parallel Artificial Membrane Permeation Assay',
        'abbreviation' => 'PAMPA',
        'description' => 'Reference assay for passive permeability.',
        'parameters' => new MethodParameters([]),
        ...$attributes,
    ]);

    $method->categories()->syncWithPivotValues(
        [createMethodResourceCategory()->id],
        ['model_type' => Method::class],
    );

    return $method->refresh();
}

test('user with membrane method view permission can load the methods list page', function () {
    $user = createMethodAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
    ]);

    $this->actingAs($user);

    Livewire::test(ListMethods::class)
        ->assertOk();
});

test('user without membrane method view permission cannot load the methods list page', function () {
    $user = createMethodAdmin();

    $this->actingAs($user);

    Livewire::test(ListMethods::class)
        ->assertForbidden();
});

test('methods list page displays existing methods for authorized user', function () {
    $user = createMethodAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
    ]);
    $method = createMethod();

    $this->actingAs($user);

    Livewire::test(ListMethods::class)
        ->assertOk()
        ->assertSee($method->name)
        ->assertSee($method->abbreviation);
});

test('user with membrane method view and edit permission can load the create method page', function () {
    $user = createMethodAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
    ]);
    createMethodResourceCategory();

    $this->actingAs($user);

    Livewire::test(CreateMethod::class)
        ->assertOk()
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('abbreviation')
        ->assertFormFieldExists('categories');
});

test('user without membrane method edit permission cannot load the create method page', function () {
    $user = createMethodAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
    ]);
    createMethodResourceCategory();

    $this->actingAs($user);

    Livewire::test(CreateMethod::class)
        ->assertForbidden();
});

test('user with membrane method edit permission can create a method', function () {
    $user = createMethodAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
    ]);
    $category = createMethodResourceCategory();

    $this->actingAs($user);

    Livewire::test(CreateMethod::class)
        ->fillForm([
            'type' => Method::TYPE_PUBCHEM_LOGP,
            'name' => 'New diffusion method',
            'abbreviation' => 'NDM',
            'description' => 'Curated method for interaction testing.',
            'categories' => [$category->id],
            'parameters' => [
                'alert_limits' => [
                    'logperm' => [
                        'min' => -5.5,
                        'max' => 2.3,
                    ],
                ],
            ],
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    $method = Method::query()
        ->where('name', 'New diffusion method')
        ->first();

    expect($method)->not->toBeNull()
        ->and($method->abbreviation)->toBe('NDM')
        ->and($method->categories()->whereKey($category->id)->exists())->toBeTrue();
});

test('user with membrane method view and edit permission can load the edit method page', function () {
    $user = createMethodAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
    ]);
    $method = createMethod([
        'parameters' => [
            'alert_limits' => [
                'logperm' => [
                    'min' => -4.2,
                    'max' => 1.8,
                ],
            ],
        ],
    ]);

    $this->actingAs($user);

    Livewire::test(EditMethod::class, [
        'record' => $method->getKey(),
    ])
        ->assertOk()
        ->assertFormSet([
            'name' => $method->name,
            'abbreviation' => $method->abbreviation,
        ])
        // Whether the editor wraps plain text in a paragraph depends on the
        // HTML parser of the PHP build, so only the text itself is looked for
        // in the editor's document.
        ->assertFormSet(function (array $state) use ($method): void {
            expect(json_encode($state['description'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->toContain($method->description);
        });
});

test('user without membrane method edit permission cannot load the edit method page', function () {
    $user = createMethodAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
    ]);
    $method = createMethod();

    $this->actingAs($user);

    Livewire::test(EditMethod::class, [
        'record' => $method->getKey(),
    ])
        ->assertForbidden();
});

test('user with membrane method edit permission can update a method', function () {
    $user = createMethodAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
    ]);
    $method = createMethod();
    $category = createMethodResourceCategory([
        'title' => 'Transport methods',
    ]);

    $this->actingAs($user);

    Livewire::test(EditMethod::class, [
        'record' => $method->getKey(),
    ])
        ->fillForm([
            'type' => $method->type,
            'name' => 'Updated method',
            'abbreviation' => 'UPDM',
            'description' => 'Updated method description.',
            'categories' => [$category->id],
            'parameters' => [
                'alert_limits' => [
                    'logperm' => [
                        'min' => -3.2,
                        'max' => 1.5,
                    ],
                ],
            ],
        ])
        ->call('save')
        ->assertNotified();

    $updatedMethod = $method->fresh();

    expect($updatedMethod)
        ->name->toBe('Updated method')
        ->abbreviation->toBe('UPDM');

    expect($updatedMethod->categories()->whereKey($category->id)->exists())->toBeTrue();
});

test('method policy allows global view create update and delete permissions', function () {
    $user = createMethodAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
        PermissionEnums::MEMBRANE_METHOD_DELETE->value,
    ]);
    $method = createMethod();

    expect($user->can('view', $method))->toBeTrue()
        ->and($user->can('create', Method::class))->toBeTrue()
        ->and($user->can('update', $method))->toBeTrue()
        ->and($user->can('delete', $method))->toBeTrue()
        ->and($user->can('restore', $method))->toBeTrue();
});

test('method resource exposes index create and edit pages', function () {
    expect(MethodResource::getPages())
        ->toHaveKeys(['index', 'create', 'edit']);
});

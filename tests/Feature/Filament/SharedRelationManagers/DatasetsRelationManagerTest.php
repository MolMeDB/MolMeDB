<?php

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Filament\Resources\Membranes\Pages\EditMembrane;
use App\Filament\Resources\Methods\Pages\EditMethod;
use App\Filament\Resources\Publications\Pages\EditPublication;
use App\Filament\Resources\SharedRelationManagers\DatasetsRelationManager;
use App\Models\Category;
use App\Models\Dataset;
use App\Models\DatasetGroup;
use App\Models\Membrane;
use App\Models\Method;
use App\Models\Permission;
use App\Models\Publication;
use App\Models\Role;
use App\Models\User;
use App\ValueObjects\MethodParameters;
use Illuminate\Support\Facades\DB;
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

function createRelationManagerAdmin(array $permissions = []): User
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

function createMethodCategoryForRelationManager(array $attributes = []): Category
{
    return Category::factory()->create([
        'title' => 'Permeability methods',
        'type' => Category::TYPE_METHOD,
        ...$attributes,
    ]);
}

function createMembraneCategoryForRelationManager(array $attributes = []): Category
{
    return Category::factory()->create([
        'title' => 'Phospholipid membranes',
        'type' => Category::TYPE_MEMBRANE,
        ...$attributes,
    ]);
}

/**
 * @return array{group: DatasetGroup, method: Method, membrane: Membrane}
 */
function createDatasetDependenciesForRelationManager(): array
{
    $group = DatasetGroup::factory()->create();

    $method = Method::factory()->create([
        'name' => 'Parallel Artificial Membrane Permeation Assay',
        'abbreviation' => 'PAMPA',
        'parameters' => new MethodParameters([]),
    ]);

    $membrane = Membrane::factory()->create([
        'name' => 'DOPC bilayer',
        'abbreviation' => 'DOPC',
    ]);

    DB::table('model_has_categories')->insert([
        [
            'category_id' => createMethodCategoryForRelationManager()->id,
            'model_id' => $method->id,
            'model_type' => Method::class,
        ],
        [
            'category_id' => createMembraneCategoryForRelationManager()->id,
            'model_id' => $membrane->id,
            'model_type' => Membrane::class,
        ],
    ]);

    return [
        'group' => $group,
        'method' => $method,
        'membrane' => $membrane,
    ];
}

function createDatasetForRelationManager(User $owner, array $attributes = []): Dataset
{
    $dependencies = createDatasetDependenciesForRelationManager();

    return Dataset::query()->create([
        'type' => Dataset::TYPE_PASSIVE,
        'name' => 'Reference passive dataset',
        'comment' => 'Dataset used in shared relation manager tests.',
        'dataset_group_id' => $dependencies['group']->id,
        'method_id' => $dependencies['method']->id,
        'membrane_id' => $dependencies['membrane']->id,
        'created_by' => $owner->id,
        ...$attributes,
    ]);
}

function createPublicationForRelationManager(array $attributes = []): Publication
{
    return Publication::factory()->create([
        'citation' => 'Reference publication for relation manager test.',
        'doi' => null,
        'identifier' => null,
        'identifier_source' => null,
        'title' => null,
        'journal' => null,
        'volume' => null,
        'issue' => null,
        'page' => null,
        'year' => null,
        'published_at' => null,
        'validated_at' => null,
        ...$attributes,
    ]);
}

test('datasets relation manager can load for membrane owner record', function () {
    $user = createRelationManagerAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
        PermissionEnums::DATASET_VIEW->value,
    ]);
    $membrane = createDatasetDependenciesForRelationManager()['membrane'];

    $this->actingAs($user);

    Livewire::test(DatasetsRelationManager::class, [
        'ownerRecord' => $membrane,
        'pageClass' => EditMembrane::class,
    ])
        ->assertOk();
});

// Filament refuses a relation manager whose records the user may not list
// (since 5.12 also when the component is addressed directly).
test('datasets relation manager is refused to a user who may not view datasets', function () {
    $user = createRelationManagerAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
    ]);
    $membrane = createDatasetDependenciesForRelationManager()['membrane'];

    $this->actingAs($user);

    Livewire::test(DatasetsRelationManager::class, [
        'ownerRecord' => $membrane,
        'pageClass' => EditMembrane::class,
    ])
        ->assertForbidden();
});

test('datasets relation manager shows datasets related to membrane only', function () {
    $user = createRelationManagerAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
        PermissionEnums::DATASET_VIEW->value,
    ]);

    $relatedDataset = createDatasetForRelationManager($user, [
        'name' => 'Membrane related dataset',
    ]);
    $foreignDataset = createDatasetForRelationManager($user, [
        'name' => 'Foreign dataset',
    ]);

    $this->actingAs($user);

    Livewire::test(DatasetsRelationManager::class, [
        'ownerRecord' => $relatedDataset->membrane,
        'pageClass' => EditMembrane::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$relatedDataset])
        ->assertCanNotSeeTableRecords([$foreignDataset]);
});

test('datasets relation manager shows datasets related to method only', function () {
    $user = createRelationManagerAdmin([
        PermissionEnums::MEMBRANE_METHOD_VIEW->value,
        PermissionEnums::MEMBRANE_METHOD_EDIT->value,
        PermissionEnums::DATASET_VIEW->value,
    ]);

    $relatedDataset = createDatasetForRelationManager($user, [
        'name' => 'Method related dataset',
    ]);
    $foreignDataset = createDatasetForRelationManager($user, [
        'name' => 'Foreign method dataset',
    ]);

    $this->actingAs($user);

    Livewire::test(DatasetsRelationManager::class, [
        'ownerRecord' => $relatedDataset->method,
        'pageClass' => EditMethod::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$relatedDataset])
        ->assertCanNotSeeTableRecords([$foreignDataset]);
});

test('datasets relation manager shows datasets attached to publication only', function () {
    $user = createRelationManagerAdmin([
        PermissionEnums::PUBLICATION_VIEW->value,
        PermissionEnums::PUBLICATION_EDIT->value,
        PermissionEnums::DATASET_VIEW->value,
    ]);

    $publication = createPublicationForRelationManager();
    $relatedDataset = createDatasetForRelationManager($user, [
        'name' => 'Publication related dataset',
    ]);
    $foreignDataset = createDatasetForRelationManager($user, [
        'name' => 'Unattached dataset',
    ]);

    $relatedDataset->publications()->syncWithPivotValues(
        [$publication->id],
        ['model_type' => Dataset::class],
    );

    $this->actingAs($user);

    Livewire::test(DatasetsRelationManager::class, [
        'ownerRecord' => $publication,
        'pageClass' => EditPublication::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$relatedDataset])
        ->assertCanNotSeeTableRecords([$foreignDataset]);
});

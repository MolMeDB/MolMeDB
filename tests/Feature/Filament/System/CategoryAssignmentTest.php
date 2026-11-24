<?php

use App\Enums\PermissionEnums;
use App\Filament\Clusters\Categories\Resources\InteractionActiveCategories\Pages\EditInteractionActiveCategory;
use App\Filament\Clusters\Categories\Resources\InteractionActiveCategories\Pages\InteractionActiveCategoryTree;
use App\Filament\Clusters\Categories\Resources\MembraneCategories\Pages\EditMembraneCategory;
use App\Filament\Clusters\Categories\Resources\MembraneCategories\Pages\MembraneCategoryTree;
use App\Filament\Clusters\Categories\Resources\MembraneCategories\RelationManagers\MembranesRelationManager;
use App\Filament\Clusters\Categories\Resources\MethodCategories\Pages\EditMethodCategory;
use App\Filament\Clusters\Categories\Resources\MethodCategories\Pages\MethodCategoryTree;
use App\Filament\Clusters\Categories\Resources\MethodCategories\RelationManagers\MethodsRelationManager;
use App\Filament\Clusters\Categories\Resources\ProteinCategories\Pages\EditProteinCategory;
use App\Filament\Clusters\Categories\Resources\ProteinCategories\Pages\ProteinCategoryTree;
use App\Filament\Clusters\Categories\Resources\ProteinCategories\RelationManagers\ProteinsRelationManager;
use App\Models\Category;
use App\Models\Membrane;
use App\Models\Method;
use App\Models\Permission;
use App\Models\Protein;
use App\Models\Role;
use App\Models\User;
use App\ValueObjects\MethodParameters;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Categories group methods, membranes and proteins. Assigning records to a
 * category needs the permission to edit those records, rearranging and
 * deleting categories needs the permission to manage them, and a category
 * still in use cannot be deleted.
 */

beforeEach(function () {
    collect(PermissionEnums::cases())->each(fn (PermissionEnums $permission) => Permission::query()->firstOrCreate(
        ['name' => $permission->value, 'guard_name' => 'web'],
        ['description' => $permission->description()],
    ));

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @param  array<int, PermissionEnums>  $permissions
 */
function categoryPanelUser(array $permissions = []): User
{
    $role = Role::query()->create(['name' => 'Category role '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo([PermissionEnums::ADMIN_PANEL, ...$permissions]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function categoryOfType(int $type, string $title = 'Category', ?Category $parent = null): Category
{
    return Category::factory()->create(['type' => $type, 'title' => $title, 'parent_id' => $parent?->id]);
}

function categoryMethod(): Method
{
    return Method::factory()->create(['parameters' => new MethodParameters([])]);
}

test('method editors assign methods to a category', function () {
    $category = categoryOfType(Category::TYPE_METHOD);
    $method = categoryMethod();

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE, PermissionEnums::MEMBRANE_METHOD_VIEW, PermissionEnums::MEMBRANE_METHOD_EDIT]));

    Livewire::test(MethodsRelationManager::class, ['ownerRecord' => $category, 'pageClass' => EditMethodCategory::class])
        ->callTableAction('attach', data: ['recordId' => [$method->id]])
        ->assertHasNoTableActionErrors()
        ->assertCanSeeTableRecords([$method]);

    expect($category->methods()->pluck('methods.id')->all())->toBe([$method->id])
        ->and($method->categories()->wherePivot('model_type', Method::class)->exists())->toBeTrue();
});

test('category managers without the method edit permission cannot assign or edit methods', function () {
    $category = categoryOfType(Category::TYPE_METHOD);
    $assigned = categoryMethod();
    $category->methods()->attach($assigned->id, ['model_type' => Method::class]);

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE, PermissionEnums::MEMBRANE_METHOD_VIEW]));

    Livewire::test(MethodsRelationManager::class, ['ownerRecord' => $category, 'pageClass' => EditMethodCategory::class])
        ->assertCanSeeTableRecords([$assigned])
        ->assertTableActionHidden('attach')
        ->assertTableActionHidden('edit', $assigned);
});

test('protein editors assign proteins to a category', function () {
    $category = categoryOfType(Category::TYPE_PROTEIN);
    $protein = Protein::factory()->create();

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE, PermissionEnums::PROTEIN_VIEW, PermissionEnums::PROTEIN_EDIT]));

    Livewire::test(ProteinsRelationManager::class, ['ownerRecord' => $category, 'pageClass' => EditProteinCategory::class])
        ->callTableAction('attach', data: ['recordId' => [$protein->id]])
        ->assertHasNoTableActionErrors();

    expect($category->proteins()->pluck('proteins.id')->all())->toBe([$protein->id]);
});

test('category managers without the protein edit permission cannot assign proteins', function () {
    $category = categoryOfType(Category::TYPE_PROTEIN);

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE, PermissionEnums::PROTEIN_VIEW, PermissionEnums::PROTEIN_EDIT_OWN]));

    Livewire::test(ProteinsRelationManager::class, ['ownerRecord' => $category, 'pageClass' => EditProteinCategory::class])
        ->assertTableActionHidden('attach');
});

test('a category lists only the membranes assigned to it', function () {
    $category = categoryOfType(Category::TYPE_MEMBRANE);
    $assigned = Membrane::factory()->create();
    $other = Membrane::factory()->create();
    $category->membranes()->attach($assigned->id, ['model_type' => Membrane::class]);

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE, PermissionEnums::MEMBRANE_METHOD_VIEW]));

    Livewire::test(MembranesRelationManager::class, ['ownerRecord' => $category, 'pageClass' => EditMembraneCategory::class])
        ->assertCanSeeTableRecords([$assigned])
        ->assertCanNotSeeTableRecords([$other])
        ->assertTableActionHidden('associate');
});

test('a category with assigned records or child categories is not deleted from its page', function () {
    $withMethod = categoryOfType(Category::TYPE_METHOD, 'With method');
    $withMethod->methods()->attach(categoryMethod()->id, ['model_type' => Method::class]);
    $parent = categoryOfType(Category::TYPE_METHOD, 'Parent');
    categoryOfType(Category::TYPE_METHOD, 'Child', $parent);

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE]));

    Livewire::test(EditMethodCategory::class, ['record' => $withMethod->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified('The record cannot be deleted!');

    Livewire::test(EditMethodCategory::class, ['record' => $parent->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified('The record cannot be deleted!');

    expect(Category::query()->whereKey([$withMethod->id, $parent->id])->count())->toBe(2);
});

test('an unused category is deleted from its page', function (string $editPage, int $type) {
    $category = categoryOfType($type);

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE]));

    Livewire::test($editPage, ['record' => $category->getKey()])
        ->callAction(DeleteAction::class)
        ->assertRedirect();

    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse();
})->with([
    'method' => [EditMethodCategory::class, Category::TYPE_METHOD],
    'membrane' => [EditMembraneCategory::class, Category::TYPE_MEMBRANE],
    'protein' => [EditProteinCategory::class, Category::TYPE_PROTEIN],
    'active interaction' => [EditInteractionActiveCategory::class, Category::TYPE_ACTIVE_INTERACTION],
]);

test('an active interaction category with child categories is not deleted from its page', function () {
    $parent = categoryOfType(Category::TYPE_ACTIVE_INTERACTION, 'Parent');
    categoryOfType(Category::TYPE_ACTIVE_INTERACTION, 'Child', $parent);

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE]));

    Livewire::test(EditInteractionActiveCategory::class, ['record' => $parent->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified('The record cannot be deleted!');

    expect(Category::query()->whereKey($parent->id)->exists())->toBeTrue();
});

test('a category is deleted from the tree only when it is unused', function (string $treePage, int $type) {
    $unused = categoryOfType($type, 'Unused');
    $parent = categoryOfType($type, 'Parent');
    categoryOfType($type, 'Child', $parent);

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE]));

    Livewire::test($treePage)
        ->call('mountTreeAction', 'delete', (string) $parent->id)
        ->call('callMountedAction')
        ->assertNotified('The record cannot be deleted!')
        ->call('mountTreeAction', 'delete', (string) $unused->id)
        ->call('callMountedAction');

    expect(Category::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(Category::query()->whereKey($parent->id)->exists())->toBeTrue();
})->with([
    'method' => [MethodCategoryTree::class, Category::TYPE_METHOD],
    'membrane' => [MembraneCategoryTree::class, Category::TYPE_MEMBRANE],
    'protein' => [ProteinCategoryTree::class, Category::TYPE_PROTEIN],
    'active interaction' => [InteractionActiveCategoryTree::class, Category::TYPE_ACTIVE_INTERACTION],
]);

test('category viewers cannot delete categories from the tree', function () {
    $category = categoryOfType(Category::TYPE_METHOD);

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW]));

    Livewire::test(MethodCategoryTree::class)
        ->call('mountTreeAction', 'delete', (string) $category->id)
        ->call('callMountedAction');

    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue();
});

test('category managers move a category under another in the tree', function () {
    $first = categoryOfType(Category::TYPE_METHOD, 'First');
    $second = categoryOfType(Category::TYPE_METHOD, 'Second');

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE]));

    Livewire::test(MethodCategoryTree::class)
        ->call('updateTree', [['id' => $first->id, 'children' => [['id' => $second->id]]]])
        ->assertNotified();

    expect($second->refresh()->parent_id)->toBe($first->id);
});

test('category viewers cannot rearrange the category tree', function (string $treePage, int $type) {
    $first = categoryOfType($type, 'First');
    $second = categoryOfType($type, 'Second');

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW]));

    Livewire::test($treePage)
        ->call('updateTree', [['id' => $first->id, 'children' => [['id' => $second->id]]]])
        ->assertForbidden();

    expect($second->refresh()->parent_id)->not->toBe($first->id);
})->with([
    'method' => [MethodCategoryTree::class, Category::TYPE_METHOD],
    'membrane' => [MembraneCategoryTree::class, Category::TYPE_MEMBRANE],
    'protein' => [ProteinCategoryTree::class, Category::TYPE_PROTEIN],
    'active interaction' => [InteractionActiveCategoryTree::class, Category::TYPE_ACTIVE_INTERACTION],
]);

test('users who manage only their own categories cannot rearrange the tree', function () {
    $user = categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE_OWN]);
    $own = categoryOfType(Category::TYPE_METHOD, 'Own');
    $foreign = categoryOfType(Category::TYPE_METHOD, 'Foreign');

    $this->actingAs($user);

    Livewire::test(MethodCategoryTree::class)
        ->call('updateTree', [['id' => $own->id, 'children' => [['id' => $foreign->id]]]])
        ->assertForbidden();

    expect($foreign->refresh()->parent_id)->not->toBe($own->id);
});

test('the tree shows each category with its parent and number of methods', function () {
    $parent = categoryOfType(Category::TYPE_METHOD, 'Permeability');
    $child = categoryOfType(Category::TYPE_METHOD, 'PAMPA variants', $parent);
    $child->methods()->attach(categoryMethod()->id, ['model_type' => Method::class]);

    $this->actingAs(categoryPanelUser([PermissionEnums::CATEGORIES_VIEW, PermissionEnums::CATEGORIES_MANAGE]));

    $tree = Livewire::test(MethodCategoryTree::class)->instance();

    expect($tree->getTreeRecordTitle($child))->toBe('Permeability >> PAMPA variants (# Methods: 1)')
        ->and($tree->getTreeRecordTitle($parent))->toBe('Permeability')
        ->and($tree->getTreeRecordTitle(null))->toBe('')
        ->and($tree->getTreeQuery()->pluck('id')->all())->toContain($parent->id, $child->id)
        ->and(MethodCategoryTree::shouldRegisterNavigation())->toBeFalse();
});

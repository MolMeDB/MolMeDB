<?php

require_once __DIR__.'/../filament_resource_test_helpers.php';
require_once __DIR__.'/../../Structures/structure_rdkit_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\Structures\Pages\CreateStructure;
use App\Filament\Resources\Structures\Pages\EditStructure;
use App\Filament\Resources\Structures\StructureResource;
use App\Models\Structure;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * A structure is created in two steps: its SMILES is validated (canonized and
 * described by RDKit) and only then saved.
 */

beforeEach(function () {
    seedFilamentResourcePermissions();
    fakeRdkitService();
    config()->set('services.cdk_depict.url', 'https://cdk-depict.test');

    $this->editor = createFilamentResourceUser([PermissionEnums::STRUCTURE_VIEW, PermissionEnums::STRUCTURE_EDIT]);
    $this->actingAs($this->editor);
});

afterEach(function () {
    resetApiRouteRdkitState();
});

function structurePagesInfo(string $canonical): array
{
    return [
        'canonized_smiles' => $canonical,
        'inchi' => 'InChI=1S/C2H6O/c1-2-3/h3H,2H2,1H3',
        'inchikey' => 'LFQSCWFLJHTTHZ-UHFFFAOYSA-N',
        'MW' => 46.0684,
        'LogP' => -0.3081,
    ];
}

function structurePagesSubmit(): TestAction
{
    return TestAction::make('submit')->schemaComponent('form-actions', 'content');
}

test('validating a SMILES canonizes it and fills the computed properties before it can be saved', function () {
    FakeRdkitService::$canonical = ['OCC' => 'CCO'];
    FakeRdkitService::$info = ['CCO' => structurePagesInfo('CCO')];

    $page = Livewire::test(CreateStructure::class)
        ->fillForm(['canonical_smiles' => 'OCC'])
        ->callAction(structurePagesSubmit())
        ->assertHasNoFormErrors()
        ->assertSet('isValidated', true)
        ->assertSchemaStateSet([
            'canonical_smiles' => 'CCO',
            'molecular_weight' => 46.07,
            'logp' => -0.31,
            'inchikey' => 'LFQSCWFLJHTTHZ-UHFFFAOYSA-N',
        ]);

    expect(Structure::query()->count())->toBe(0);

    $page->callAction(structurePagesSubmit())
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $structure = Structure::query()->sole();

    expect($structure)
        ->canonical_smiles->toBe('CCO')
        ->molecular_weight->toBe(46.07)
        ->inchikey->toBe('LFQSCWFLJHTTHZ-UHFFFAOYSA-N')
        ->user_id->toBe($this->editor->id)
        ->identifier->not->toBeNull();

    Http::assertSentCount(3);
});

test('a SMILES RDKit cannot canonize is refused with a warning', function () {
    FakeRdkitService::$canonical = ['C1CC' => null];

    Livewire::test(CreateStructure::class)
        ->fillForm(['canonical_smiles' => 'C1CC'])
        ->callAction(structurePagesSubmit())
        ->assertNotified('Invalid SMILES')
        ->assertSet('isValidated', false);

    expect(Structure::query()->count())->toBe(0);
});

test('a SMILES RDKit cannot describe is refused with a warning', function () {
    Livewire::test(CreateStructure::class)
        ->fillForm(['canonical_smiles' => 'CCN'])
        ->callAction(structurePagesSubmit())
        ->assertNotified('Unable to obtain molecule info.')
        ->assertSet('isValidated', false);
});

test('a structure cannot be validated while RDKit is unreachable', function () {
    FakeRdkitService::$reachable = false;

    Livewire::test(CreateStructure::class)
        ->fillForm(['canonical_smiles' => 'CCO'])
        ->callAction(structurePagesSubmit())
        ->assertNotified('Rdkit disconnected')
        ->assertSet('isValidated', false);
});

test('a structure cannot be validated without a SMILES', function () {
    Livewire::test(CreateStructure::class)
        ->fillForm(['canonical_smiles' => null])
        ->callAction(structurePagesSubmit())
        ->assertHasFormErrors(['canonical_smiles' => 'required']);

    Http::assertNothingSent();
});

test('only structure deleters get the delete action of the structure page', function () {
    $structure = createApiStructure(['user_id' => $this->editor->id]);

    Livewire::test(EditStructure::class, ['record' => $structure->getKey()])
        ->assertActionHidden('delete');

    $this->actingAs(createFilamentResourceUser([PermissionEnums::STRUCTURE_VIEW, PermissionEnums::STRUCTURE_EDIT, PermissionEnums::STRUCTURE_DELETE]));
    Livewire::test(EditStructure::class, ['record' => $structure->getKey()])
        ->assertActionHidden('restore')
        ->callAction('delete');

    expect($structure->fresh()->trashed())->toBeTrue();
});

test('an own structure may be deleted with the delete own permission, a foreign one not', function () {
    $user = createFilamentResourceUser([PermissionEnums::STRUCTURE_VIEW, PermissionEnums::STRUCTURE_EDIT, PermissionEnums::STRUCTURE_DELETE_OWN]);
    $own = createApiStructure(['user_id' => $user->id]);
    $foreign = createApiStructure(['user_id' => $this->editor->id]);
    $this->actingAs($user);

    Livewire::test(EditStructure::class, ['record' => $foreign->getKey()])
        ->assertActionHidden('delete');

    Livewire::test(EditStructure::class, ['record' => $own->getKey()])
        ->callAction('delete');

    expect($own->fresh()->trashed())->toBeTrue()
        ->and($foreign->fresh()->trashed())->toBeFalse();
});

test('a deleted structure is marked in its title and restored by deleters only', function () {
    $structure = createApiStructure(['identifier' => 'MM90001']);
    $structure->delete();

    Livewire::test(EditStructure::class, ['record' => $structure->getKey()])
        ->assertSee("(DELETED) Edit structure [ID:{$structure->id}] - MM90001")
        ->assertActionHidden('restore');

    $this->actingAs(createFilamentResourceUser([PermissionEnums::STRUCTURE_VIEW, PermissionEnums::STRUCTURE_EDIT, PermissionEnums::STRUCTURE_DELETE]));
    Livewire::test(EditStructure::class, ['record' => $structure->getKey()])
        ->callAction('restore');

    expect($structure->fresh()->trashed())->toBeFalse();
});

test('the breadcrumbs of an ion lead through its parent structure', function () {
    $parent = createApiStructure(['identifier' => 'MM90002']);
    $ion = createApiStructure(['identifier' => 'MM90002.1', 'parent_id' => $parent->id]);

    $ionBreadcrumbs = Livewire::test(EditStructure::class, ['record' => $ion->getKey()])->instance()->getBreadcrumbs();
    $parentBreadcrumbs = Livewire::test(EditStructure::class, ['record' => $parent->getKey()])->instance()->getBreadcrumbs();

    expect($ionBreadcrumbs)->toBe([
        StructureResource::getUrl('index') => 'List of structures',
        StructureResource::getUrl('edit', ['record' => $parent]) => 'Parent structure [MM90002]',
        StructureResource::getUrl('edit', ['record' => $ion]) => 'Ion [MM90002.1]',
    ])->and($parentBreadcrumbs)->toBe([
        StructureResource::getUrl('index') => 'List of structures',
        StructureResource::getUrl('edit', ['record' => $parent]) => 'Structure [MM90002]',
    ]);
});

test('a structure whose LogP rounds to zero from below can be validated and saved', function () {
    FakeRdkitService::$info = ['CCO' => [...structurePagesInfo('CCO'), 'LogP' => -0.0014]];

    Livewire::test(CreateStructure::class)
        ->fillForm(['canonical_smiles' => 'CCO'])
        ->callAction(structurePagesSubmit())
        ->assertSchemaStateSet(['logp' => 0.0])
        ->callAction(structurePagesSubmit())
        ->assertHasNoFormErrors();

    expect(Structure::query()->sole()->logp)->toBe(0.0);
});

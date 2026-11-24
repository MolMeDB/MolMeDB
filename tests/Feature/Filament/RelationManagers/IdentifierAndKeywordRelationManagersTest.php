<?php

require_once __DIR__.'/../filament_resource_test_helpers.php';
require_once __DIR__.'/../../Api/api_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\Datasets\Pages\EditDataset;
use App\Filament\Resources\Methods\Pages\EditMethod;
use App\Filament\Resources\Proteins\Pages\EditProtein;
use App\Filament\Resources\Proteins\RelationManagers\IdentifiersRelationManager as ProteinIdentifiersRelationManager;
use App\Filament\Resources\SharedRelationManagers\IdentifiersRelationManager;
use App\Filament\Resources\SharedRelationManagers\KeywordsRelationManager;
use App\Filament\Resources\Structures\Pages\EditStructure;
use App\Filament\Resources\Structures\StructureResource;
use App\Models\Dataset;
use App\Models\Identifier;
use App\Models\Keyword;
use App\Models\Method;
use App\Models\ProteinIdentifier;
use App\Models\Structure;
use App\Models\User;
use Livewire\Livewire;

/*
 * Identifiers of structures and proteins, and keywords of membranes and
 * methods, are edited in their relation tabs.
 */

beforeEach(function () {
    seedFilamentResourcePermissions();

    $this->editor = createFilamentResourceUser([
        PermissionEnums::STRUCTURE_VIEW,
        PermissionEnums::STRUCTURE_EDIT,
        PermissionEnums::PROTEIN_VIEW,
        PermissionEnums::PROTEIN_EDIT,
        PermissionEnums::DATASET_VIEW,
        PermissionEnums::DATASET_EDIT,
        PermissionEnums::MEMBRANE_METHOD_VIEW,
        PermissionEnums::MEMBRANE_METHOD_EDIT,
    ]);
    $this->actingAs($this->editor);
});

function identifierRmIdentifier(Structure $structure, array $attributes = []): Identifier
{
    return Identifier::query()->create([
        'structure_id' => $structure->id,
        'value' => 'Ethanol',
        'type' => Identifier::TYPE_NAME,
        'state' => Identifier::STATE_VALIDATED,
        ...$attributes,
    ]);
}

test('a structure identifier added by hand is validated and attributed to its author', function () {
    $structure = createApiStructure();

    Livewire::test(IdentifiersRelationManager::class, ['ownerRecord' => $structure, 'pageClass' => EditStructure::class])
        ->assertTableActionVisible('create')
        ->callTableAction('create', data: ['value' => 'Ethyl alcohol', 'type' => Identifier::TYPE_NAME])
        ->assertHasNoTableActionErrors();

    expect($structure->identifiers()->where('value', 'Ethyl alcohol')->firstOrFail())
        ->type->toBe(Identifier::TYPE_NAME)
        ->state->toBe(Identifier::STATE_VALIDATED)
        ->source_type->toBe(User::class)
        ->source_id->toBe($this->editor->id);
});

test('a structure identifier needs a value and a known type', function () {
    $structure = createApiStructure();

    Livewire::test(IdentifiersRelationManager::class, ['ownerRecord' => $structure, 'pageClass' => EditStructure::class])
        ->callTableAction('create', data: ['value' => null, 'type' => 999])
        ->assertHasTableActionErrors(['value' => 'required', 'type']);

    expect($structure->identifiers()->count())->toBe(0);
});

test('only identifiers added by users can be edited or deleted', function () {
    $structure = createApiStructure();
    $byUser = identifierRmIdentifier($structure, ['value' => 'Typed by hand', 'source_type' => User::class, 'source_id' => $this->editor->id]);
    $byDataset = identifierRmIdentifier($structure, ['value' => 'Imported', 'source_type' => Dataset::class, 'source_id' => 1]);

    Livewire::test(IdentifiersRelationManager::class, ['ownerRecord' => $structure, 'pageClass' => EditStructure::class])
        ->assertTableActionVisible('edit', $byUser)
        ->assertTableActionHidden('edit', $byDataset)
        ->assertTableActionHidden('delete', $byDataset)
        ->callTableAction('edit', $byUser, data: ['value' => 'Corrected by hand', 'type' => Identifier::TYPE_NAME])
        ->assertHasNoTableActionErrors()
        ->callTableAction('delete', $byUser);

    $edited = Identifier::withTrashed()->find($byUser->id);

    expect($edited->getAttribute('value'))->toBe('Corrected by hand')
        ->and($edited->trashed())->toBeTrue()
        ->and($byDataset->fresh()->trashed())->toBeFalse();
});

test('a name of a structure can be made its primary name', function () {
    $structure = createApiStructure();
    $current = identifierRmIdentifier($structure, ['value' => 'Old primary', 'state' => Identifier::STATE_ACTIVE]);
    $candidate = identifierRmIdentifier($structure, ['value' => 'New primary']);
    $pubchem = identifierRmIdentifier($structure, ['value' => '702', 'type' => Identifier::TYPE_PUBCHEM]);

    Livewire::test(IdentifiersRelationManager::class, ['ownerRecord' => $structure, 'pageClass' => EditStructure::class])
        ->assertTableActionHidden('activate', $current)
        ->assertTableActionHidden('activate', $pubchem)
        ->assertTableActionHidden('compound_detail', $candidate)
        ->callTableAction('activate', $candidate);

    expect($candidate->fresh()->state)->toBe(Identifier::STATE_ACTIVE)
        ->and($current->fresh()->state)->toBe(Identifier::STATE_VALIDATED);
});

test('the identifiers of a dataset cannot be added there and link to their structures', function () {
    $dataset = createApiDataset(['owner' => $this->editor]);
    $structure = createApiStructure();
    $identifier = identifierRmIdentifier($structure, ['source_type' => Dataset::class, 'source_id' => $dataset->id]);

    Livewire::test(IdentifiersRelationManager::class, ['ownerRecord' => $dataset, 'pageClass' => EditDataset::class])
        ->assertSee('Structure identifiers added from current dataset')
        ->assertTableActionHidden('create')
        ->assertTableActionHidden('activate', $identifier)
        ->assertTableActionHasUrl('compound_detail', StructureResource::getUrl('edit', ['record' => $structure]), $identifier);
});

test('the identifiers tab of a deleted structure lists deleted identifiers by default', function () {
    $active = createApiStructure();
    $deleted = createApiStructure();
    $deletedIdentifier = identifierRmIdentifier($deleted, ['value' => 'Gone']);
    $deletedIdentifier->delete();
    $deleted->delete();

    // The tab of a live structure comes first: the default must not stick.
    Livewire::test(IdentifiersRelationManager::class, ['ownerRecord' => $active, 'pageClass' => EditStructure::class])
        ->assertTableFilterExists('trashed');

    Livewire::test(IdentifiersRelationManager::class, ['ownerRecord' => $deleted->fresh(), 'pageClass' => EditStructure::class])
        ->assertCanSeeTableRecords([$deletedIdentifier]);
});

test('protein identifiers are added validated, edited and deleted in the protein tab', function () {
    $protein = createApiProtein(['uniprot_id' => 'P08183']);

    $component = Livewire::test(ProteinIdentifiersRelationManager::class, ['ownerRecord' => $protein, 'pageClass' => EditProtein::class])
        ->callTableAction('create', data: ['value' => 'MDR1', 'type' => ProteinIdentifier::TYPE_NAME])
        ->assertHasNoTableActionErrors();

    $identifier = $protein->identifiers()->where('value', 'MDR1')->firstOrFail();

    expect($identifier)
        ->state->toBe(ProteinIdentifier::STATE_VALIDATED)
        ->source_type->toBe(User::class)
        ->source_id->toBe($this->editor->id);

    $component
        ->assertCanSeeTableRecords([$identifier])
        ->callTableAction('edit', $identifier, data: ['value' => 'P-glycoprotein', 'type' => ProteinIdentifier::TYPE_NAME])
        ->assertHasNoTableActionErrors();

    expect($identifier->fresh()->value)->toBe('P-glycoprotein');

    $component->callTableAction('delete', $identifier);

    expect(ProteinIdentifier::query()->find($identifier->id))->toBeNull();
});

test('a protein identifier needs a value and a known type', function () {
    $protein = createApiProtein();

    Livewire::test(ProteinIdentifiersRelationManager::class, ['ownerRecord' => $protein, 'pageClass' => EditProtein::class])
        ->callTableAction('create', data: ['value' => '', 'type' => 999])
        ->assertHasTableActionErrors(['value' => 'required', 'type']);

    expect($protein->identifiers()->count())->toBe(0);
});

test('protein identifiers are deleted in bulk', function () {
    $protein = createApiProtein();
    $identifiers = collect(['ABCB1', 'PGY1'])->map(fn (string $value) => $protein->identifiers()->create([
        'value' => $value,
        'type' => ProteinIdentifier::TYPE_NAME,
        'state' => ProteinIdentifier::STATE_VALIDATED,
        'source_type' => User::class,
        'source_id' => $this->editor->id,
    ]));

    Livewire::test(ProteinIdentifiersRelationManager::class, ['ownerRecord' => $protein, 'pageClass' => EditProtein::class])
        ->callTableBulkAction('delete', $identifiers);

    expect(ProteinIdentifier::query()->whereKey($identifiers->pluck('id'))->count())->toBe(0);
});

test('keywords of a method are added, renamed and deleted in its keywords tab', function () {
    $method = createApiMethod();
    $otherMethod = createApiMethod(['name' => 'Other', 'abbreviation' => 'OTH']);
    $foreignKeyword = Keyword::query()->create(['value' => 'foreign', 'model_type' => Method::class, 'model_id' => $otherMethod->id]);

    $component = Livewire::test(KeywordsRelationManager::class, ['ownerRecord' => $method, 'pageClass' => EditMethod::class])
        ->callTableAction('create', data: ['value' => 'permeability'])
        ->assertHasNoTableActionErrors();

    $keyword = $method->keywords()->firstOrFail();

    expect($keyword->getAttribute('value'))->toBe('permeability')
        ->and($keyword)
        ->model_type->toBe(Method::class)
        ->model_id->toBe($method->id);

    $component
        ->assertCanSeeTableRecords([$keyword])
        ->assertCanNotSeeTableRecords([$foreignKeyword])
        ->callTableAction('edit', $keyword, data: ['value' => 'passive permeability'])
        ->assertHasNoTableActionErrors();

    expect($keyword->fresh()->value)->toBe('passive permeability');

    $component->callTableAction('delete', $keyword);

    Livewire::test(KeywordsRelationManager::class, ['ownerRecord' => $method, 'pageClass' => EditMethod::class])
        ->callTableAction('create', data: ['value' => ''])
        ->assertHasTableActionErrors(['value' => 'required']);

    expect(Keyword::query()->find($keyword->id))->toBeNull()
        ->and($method->keywords()->count())->toBe(0)
        ->and($foreignKeyword->fresh())->not->toBeNull();
});

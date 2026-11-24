<?php

require_once __DIR__.'/../filament_resource_test_helpers.php';
require_once __DIR__.'/../../Api/api_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\Datasets\Pages\EditDataset;
use App\Filament\Resources\InteractionActives\InteractionActiveResource;
use App\Filament\Resources\InteractionPassives\InteractionPassiveResource;
use App\Filament\Resources\Membranes\Pages\EditMembrane;
use App\Filament\Resources\Methods\Pages\EditMethod;
use App\Filament\Resources\Publications\Pages\EditPublication;
use App\Filament\Resources\Publications\PublicationResource;
use App\Filament\Resources\Publications\RelationManagers\AuthorRelationManager;
use App\Filament\Resources\SharedRelationManagers\InteractionsActiveRelationManager;
use App\Filament\Resources\SharedRelationManagers\InteractionsPassiveRelationManager;
use App\Filament\Resources\SharedRelationManagers\PublicationsRelationManager;
use App\Filament\Resources\Structures\Pages\EditStructure;
use App\Filament\Resources\Structures\RelationManagers\ChargesRelationManager;
use App\Filament\Resources\Structures\StructureResource;
use App\Filament\Resources\UploadQueues\Pages\EditUploadQueue;
use App\Models\Author;
use App\Models\Dataset;
use App\Models\Method;
use App\Models\UploadQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Relation tabs that list the publications, interactions, charged forms and
 * authors of a record.
 */

beforeEach(function () {
    seedFilamentResourcePermissions();
    Http::fake();
});

function ownerRmUploadQueue(int $type): UploadQueue
{
    return new UploadQueue(['type' => $type]);
}

test('an existing publication is attached to a method as its reference and detached again', function () {
    $this->actingAs(createFilamentResourceUser([PermissionEnums::MEMBRANE_METHOD_VIEW, PermissionEnums::MEMBRANE_METHOD_EDIT, PermissionEnums::PUBLICATION_VIEW]));
    $method = createApiMethod();
    $publication = createApiPublication(['citation' => 'Reference of the method']);

    $component = Livewire::test(PublicationsRelationManager::class, ['ownerRecord' => $method, 'pageClass' => EditMethod::class])
        ->assertSee('references. Warning! Not related to assigned datasets or interactions.')
        ->callTableAction('attach', data: ['recordId' => $publication->id])
        ->assertHasNoTableActionErrors();

    expect($method->publications()->pluck('publications.id')->all())->toBe([$publication->id])
        ->and(DB::table('model_has_publications')->where('publication_id', $publication->id)->value('model_type'))->toBe(Method::class);

    $component
        ->assertCanSeeTableRecords([$publication])
        ->assertTableActionHasUrl('edit', PublicationResource::getUrl('edit', ['record' => $publication]), $publication)
        ->callTableAction('detach', $publication);

    expect($method->publications()->count())->toBe(0)
        ->and($publication->fresh())->not->toBeNull();
});

test('a new publication is created from a membrane tab only by users who may create publications', function () {
    $membrane = createApiMembrane();

    $this->actingAs(createFilamentResourceUser([PermissionEnums::MEMBRANE_METHOD_VIEW, PermissionEnums::MEMBRANE_METHOD_EDIT, PermissionEnums::PUBLICATION_VIEW]));
    Livewire::test(PublicationsRelationManager::class, ['ownerRecord' => $membrane, 'pageClass' => EditMembrane::class])
        ->assertSee('references. Warning! Not related to assigned datasets or interactions.')
        ->assertTableActionHidden('create');

    $this->actingAs(createFilamentResourceUser([
        PermissionEnums::MEMBRANE_METHOD_VIEW,
        PermissionEnums::MEMBRANE_METHOD_EDIT,
        PermissionEnums::PUBLICATION_VIEW,
        PermissionEnums::PUBLICATION_EDIT,
    ]));
    Livewire::test(PublicationsRelationManager::class, ['ownerRecord' => $membrane, 'pageClass' => EditMembrane::class])
        ->callTableAction('create', data: ['citation' => 'Membrane paper written by hand'])
        ->assertHasNoTableActionErrors();

    expect($membrane->publications()->pluck('citation')->all())->toBe(['Membrane paper written by hand']);
});

test('the publications tab of a dataset lists its secondary publications', function () {
    $dataset = createApiDataset();

    expect(PublicationsRelationManager::getTitle($dataset, EditDataset::class))->toBe('Secondary publications')
        ->and(PublicationsRelationManager::getTitle(createApiMethod(), EditMethod::class))->toBe('References');
});

test('the interaction tabs of an upload are shown only for uploads of their kind', function () {
    $this->actingAs(createFilamentResourceUser([PermissionEnums::DATASET_VIEW]));

    $active = ownerRmUploadQueue(UploadQueue::TYPE_ACTIVE_DATASET);
    $passive = ownerRmUploadQueue(UploadQueue::TYPE_PASSIVE_DATASET);

    expect(InteractionsActiveRelationManager::canViewForRecord($active, EditUploadQueue::class))->toBeTrue()
        ->and(InteractionsActiveRelationManager::canViewForRecord($passive, EditUploadQueue::class))->toBeFalse()
        ->and(InteractionsPassiveRelationManager::canViewForRecord($passive, EditUploadQueue::class))->toBeTrue()
        ->and(InteractionsPassiveRelationManager::canViewForRecord($active, EditUploadQueue::class))->toBeFalse()
        ->and(InteractionsPassiveRelationManager::canViewForRecord(createApiDataset(['type' => Dataset::TYPE_PASSIVE_INTERNAL_COSMO]), EditDataset::class))->toBeTrue();
});

test('the active interactions of a deleted method are hidden with a warning', function () {
    $this->actingAs(createFilamentResourceUser([PermissionEnums::DATASET_VIEW, PermissionEnums::DATASET_DELETE]));
    $dataset = createApiDataset(['type' => Dataset::TYPE_ACTIVE]);
    $method = $dataset->method;
    $interaction = createApiActiveInteraction(['dataset' => $dataset]);

    Livewire::test(InteractionsActiveRelationManager::class, ['ownerRecord' => $method, 'pageClass' => EditMethod::class])
        ->assertSee('Active interactions assigned to the method.')
        ->assertCanSeeTableRecords([$interaction])
        ->assertTableActionHasUrl('compound_detail', StructureResource::getUrl('edit', ['record' => $interaction->structure]), $interaction)
        ->assertTableActionHasUrl('edit', InteractionActiveResource::getUrl('edit', ['record' => $interaction]), $interaction);

    $interaction->delete();
    $method->delete();

    Livewire::test(InteractionsActiveRelationManager::class, ['ownerRecord' => $method->fresh(), 'pageClass' => EditMethod::class])
        ->assertSee('Warning! Interactions labeled as "deleted" are hidden.')
        ->filterTable('trashed', false)
        ->assertCanNotSeeTableRecords([$interaction]);
});

test('a deleted active interaction is restored from a structure only by users who may restore its dataset', function () {
    $structure = createApiStructure();
    $interaction = createApiActiveInteraction(['structure' => $structure]);
    $interaction->delete();

    $this->actingAs(createFilamentResourceUser([PermissionEnums::DATASET_VIEW]));
    Livewire::test(InteractionsActiveRelationManager::class, ['ownerRecord' => $structure, 'pageClass' => EditStructure::class])
        ->assertSee('Active interactions assigned to the structure.')
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$interaction])
        ->assertTableActionHidden('restore', $interaction);

    $this->actingAs(createFilamentResourceUser([PermissionEnums::DATASET_VIEW, PermissionEnums::DATASET_DELETE]));
    Livewire::test(InteractionsActiveRelationManager::class, ['ownerRecord' => $structure, 'pageClass' => EditStructure::class])
        ->filterTable('trashed', false)
        ->assertTableActionEnabled('restore', $interaction)
        ->callTableAction('restore', $interaction);

    expect($interaction->fresh()->trashed())->toBeFalse();
});

test('a deleted passive interaction is restored from a publication by users who may restore its dataset', function () {
    $publication = createApiPublication();
    $interaction = createApiPassiveInteraction(['publication' => $publication]);
    $interaction->delete();

    $this->actingAs(createFilamentResourceUser([PermissionEnums::DATASET_VIEW]));
    Livewire::test(InteractionsPassiveRelationManager::class, ['ownerRecord' => $publication, 'pageClass' => EditPublication::class])
        ->assertSee('Interactions with current record as PRIMARY reference.')
        ->filterTable('trashed', false)
        ->assertTableActionHidden('restore', $interaction);

    $this->actingAs(createFilamentResourceUser([PermissionEnums::DATASET_VIEW, PermissionEnums::DATASET_DELETE]));
    Livewire::test(InteractionsPassiveRelationManager::class, ['ownerRecord' => $publication, 'pageClass' => EditPublication::class])
        ->filterTable('trashed', false)
        ->assertTableActionHasUrl('edit', InteractionPassiveResource::getUrl('edit', ['record' => $interaction]), $interaction)
        ->callTableAction('restore', $interaction);

    expect($interaction->fresh()->trashed())->toBeFalse();
});

test('the passive interactions of a deleted membrane are hidden with a warning', function () {
    $this->actingAs(createFilamentResourceUser([PermissionEnums::DATASET_VIEW]));
    $dataset = createApiDataset();
    $membrane = $dataset->membrane;
    $interaction = createApiPassiveInteraction(['dataset' => $dataset]);

    Livewire::test(InteractionsPassiveRelationManager::class, ['ownerRecord' => $membrane, 'pageClass' => EditMembrane::class])
        ->assertSee('Passive interactions assigned to the membrane.');

    $interaction->delete();
    $membrane->delete();

    Livewire::test(InteractionsPassiveRelationManager::class, ['ownerRecord' => $membrane->fresh(), 'pageClass' => EditMembrane::class])
        ->assertSee('Warning! Interactions labeled as "deleted" are hidden.')
        ->filterTable('trashed', false)
        ->assertCanNotSeeTableRecords([$interaction]);
});

test('the charged forms tab belongs to parent structures only and lists their ions', function () {
    $this->actingAs(createFilamentResourceUser([PermissionEnums::STRUCTURE_VIEW, PermissionEnums::STRUCTURE_EDIT]));
    $parent = createApiStructure();
    $ion = createApiStructure(['parent_id' => $parent->id, 'canonical_smiles' => 'CC[O-]']);
    $foreignIon = createApiStructure(['parent_id' => createApiStructure()->id]);

    expect(ChargesRelationManager::canViewForRecord($parent, EditStructure::class))->toBeTrue()
        ->and(ChargesRelationManager::canViewForRecord($ion, EditStructure::class))->toBeFalse();

    Livewire::test(ChargesRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditStructure::class])
        ->assertCanSeeTableRecords([$ion])
        ->assertCanNotSeeTableRecords([$foreignIon])
        ->assertTableActionHasUrl('edit', StructureResource::getUrl('edit', ['record' => $ion]), $ion);
});

test('a deleted ion is restored only by users who may restore structures and only while its parent exists', function () {
    $parent = createApiStructure();
    $ion = createApiStructure(['parent_id' => $parent->id]);
    $ion->delete();

    $this->actingAs(createFilamentResourceUser([PermissionEnums::STRUCTURE_VIEW, PermissionEnums::STRUCTURE_EDIT]));
    Livewire::test(ChargesRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditStructure::class])
        ->filterTable('trashed', false)
        ->assertTableActionHidden('restore', $ion);

    $this->actingAs(createFilamentResourceUser([PermissionEnums::STRUCTURE_VIEW, PermissionEnums::STRUCTURE_DELETE]));
    Livewire::test(ChargesRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditStructure::class])
        ->filterTable('trashed', false)
        ->callTableAction('restore', $ion);

    expect($ion->fresh()->trashed())->toBeFalse();
});

test('the ions of a deleted structure are listed with the deleted ones', function () {
    $this->actingAs(createFilamentResourceUser([PermissionEnums::STRUCTURE_VIEW, PermissionEnums::STRUCTURE_DELETE]));
    $liveParent = createApiStructure();
    $deletedParent = createApiStructure();
    $deletedIon = createApiStructure(['parent_id' => $deletedParent->id]);
    $deletedIon->delete();
    $deletedParent->delete();

    Livewire::test(ChargesRelationManager::class, ['ownerRecord' => $liveParent, 'pageClass' => EditStructure::class])
        ->assertCanNotSeeTableRecords([$deletedIon]);

    Livewire::test(ChargesRelationManager::class, ['ownerRecord' => $deletedParent->fresh(), 'pageClass' => EditStructure::class])
        ->assertCanSeeTableRecords([$deletedIon])
        ->assertTableActionDisabled('restore', $deletedIon);
});

test('the authors tab lists the authors of the publication with their affiliation', function () {
    $this->actingAs(createFilamentResourceUser([PermissionEnums::PUBLICATION_VIEW, PermissionEnums::PUBLICATION_EDIT]));
    $publication = createApiPublication();
    $author = Author::query()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'full_name' => 'Jane Doe', 'affiliation' => 'Palacky University Olomouc']);
    $foreignAuthor = Author::query()->create(['first_name' => 'John', 'last_name' => 'Roe', 'full_name' => 'John Roe']);
    $publication->authors()->attach($author);
    createApiPublication(['citation' => 'Other'])->authors()->attach($foreignAuthor);

    Livewire::test(AuthorRelationManager::class, ['ownerRecord' => $publication, 'pageClass' => EditPublication::class])
        ->assertCanSeeTableRecords([$author])
        ->assertCanNotSeeTableRecords([$foreignAuthor])
        ->assertSee('Jane Doe')
        ->assertSee('Palacky University Olomouc')
        ->searchTable('Roe')
        ->assertCanNotSeeTableRecords([$author]);
});

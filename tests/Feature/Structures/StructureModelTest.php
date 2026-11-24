<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Models\Dataset;
use App\Models\FluorescentProperty;
use App\Models\Identifier;
use App\Models\InteractionActive;
use App\Models\InteractionPassive;
use App\Models\Structure;

/**
 * @param  array<string, mixed>  $attributes
 */
function createStructureIdentifier(Structure $structure, int $type, string $value, array $attributes = []): Identifier
{
    return Identifier::create([
        'structure_id' => $structure->id,
        'type' => $type,
        'value' => $value,
        'state' => Identifier::STATE_VALIDATED,
        ...$attributes,
    ]);
}

function createFluorescentProperty(Structure $structure): FluorescentProperty
{
    return FluorescentProperty::create([
        'dataset_id' => createApiDataset(['type' => Dataset::TYPE_PASSIVE])->id,
        'structure_id' => $structure->id,
        'theta' => 0.5,
    ]);
}

/**
 * @return array<string, Structure|InteractionPassive|InteractionActive|Identifier>
 */
function createStructureWithRelatedData(): array
{
    $parent = createApiStructure(['identifier' => 'MM00100']);
    $charged = createApiStructure(['identifier' => 'MM00100.1', 'parent_id' => $parent->id]);

    return [
        'parent' => $parent,
        'charged' => $charged,
        'passive' => createApiPassiveInteraction(['structure' => $parent]),
        'active' => createApiActiveInteraction(['structure' => $parent]),
        'chargedPassive' => createApiPassiveInteraction(['structure' => $charged]),
        'name' => createStructureIdentifier($parent, Identifier::TYPE_NAME, 'Ethanol'),
        'chargedName' => createStructureIdentifier($charged, Identifier::TYPE_NAME, 'Ethanol cation'),
    ];
}

test('deleting a structure soft deletes its identifiers, interactions and charged forms with their data', function () {
    $records = createStructureWithRelatedData();

    $records['parent']->delete();

    foreach ($records as $record) {
        expect($record::query()->find($record->id))->toBeNull()
            ->and($record::withTrashed()->find($record->id)->trashed())->toBeTrue();
    }
});

test('restoring a structure brings back its identifiers, interactions and charged forms with their data', function () {
    $records = createStructureWithRelatedData();
    $records['parent']->delete();

    Structure::withTrashed()->find($records['parent']->id)->restore();

    foreach ($records as $record) {
        expect($record::query()->find($record->id))->not->toBeNull();
    }
});

test('force deleting a structure removes its identifiers, interactions and charged forms for good', function () {
    $records = createStructureWithRelatedData();
    $records['parent']->delete();

    Structure::withTrashed()->find($records['parent']->id)->forceDelete();

    foreach ($records as $record) {
        expect($record::withTrashed()->find($record->id))->toBeNull();
    }
});

test('a structure is force deletable only when nothing refers to it, not even removed records', function (Closure $attach) {
    $structure = createApiStructure();

    expect($structure->isForceDeletable())->toBeTrue();

    $attach($structure)->delete();

    expect($structure->isForceDeletable())->toBeFalse();
})->with([
    'passive interaction' => fn (Structure $structure) => createApiPassiveInteraction(['structure' => $structure]),
    'active interaction' => fn (Structure $structure) => createApiActiveInteraction(['structure' => $structure]),
    'fluorescent property' => fn (Structure $structure) => createFluorescentProperty($structure),
    'charged form' => fn (Structure $structure) => createApiStructure(['parent_id' => $structure->id]),
]);

test('a charged form is restoreable only while its parent exists', function () {
    $parent = createApiStructure();
    $charged = createApiStructure(['parent_id' => $parent->id]);

    expect((new Structure)->isRestoreable())->toBeFalse()
        ->and($parent->isRestoreable())->toBeFalse()
        ->and($charged->isRestoreable())->toBeTrue();

    $parent->delete();

    expect(Structure::withTrashed()->find($charged->id)->isRestoreable())->toBeFalse();
});

test('setting a parent moves the structure and its own charged forms under the new parent', function () {
    $newParent = createApiStructure();
    $structure = createApiStructure();
    $children = collect([
        createApiStructure(['parent_id' => $structure->id]),
        createApiStructure(['parent_id' => $structure->id]),
    ]);
    $unrelated = createApiStructure();

    $structure->setParent($newParent);

    expect($structure->refresh()->parent_id)->toBe($newParent->id)
        ->and($children->map(fn (Structure $child) => $child->refresh()->parent_id)->all())->toBe([$newParent->id, $newParent->id])
        ->and($unrelated->refresh()->parent_id)->toBeNull();
});

test('changing the main identifier to an empty value does nothing', function () {
    $structure = createApiStructure(['identifier' => 'MM00005']);

    $structure->changeMainIdentifier(null);
    $structure->changeMainIdentifier('');

    expect($structure->refresh()->identifier)->toBe('MM00005')
        ->and(Identifier::query()->count())->toBe(0);
});

test('the main identifier cannot be changed to one that is already in use', function (Closure $occupy) {
    $structure = createApiStructure(['identifier' => 'MM00005']);
    $occupy();

    expect(fn () => $structure->changeMainIdentifier('MM00003'))
        ->toThrow(Exception::class, 'Identifier MM00003 is already in use.');

    expect($structure->refresh()->identifier)->toBe('MM00005')
        ->and(Identifier::query()->where('structure_id', $structure->id)->exists())->toBeFalse();
})->with([
    'by another structure' => fn () => createApiStructure(['identifier' => 'MM00003']),
    'by a removed structure' => fn () => createApiStructure(['identifier' => 'MM00003'])->delete(),
    'by a merged identifier of another structure' => fn () => createStructureIdentifier(
        createApiStructure(['identifier' => 'MM00001']),
        Identifier::TYPE_MOLMEDB,
        'MM00003',
        ['state' => Identifier::STATE_OBSOLETE],
    )->delete(),
]);

test('changing the main identifier keeps the old one as an obsolete pointer to the new one', function () {
    $structure = createApiStructure(['identifier' => 'MM00005']);
    $current = createStructureIdentifier($structure, Identifier::TYPE_MOLMEDB, 'MM00005', ['state' => Identifier::STATE_ACTIVE]);
    $name = createStructureIdentifier($structure, Identifier::TYPE_NAME, 'Ethanol', ['state' => Identifier::STATE_ACTIVE]);

    $structure->changeMainIdentifier('MM00003');

    $new = Identifier::query()->where('value', 'MM00003')->sole();
    $current->refresh();

    expect($structure->refresh()->identifier)->toBe('MM00003')
        ->and($new->structure_id)->toBe($structure->id)
        ->and($new->type)->toBe(Identifier::TYPE_MOLMEDB)
        ->and($new->state)->toBe(Identifier::STATE_ACTIVE)
        ->and($new->source_id)->toBeNull()
        ->and($current->state)->toBe(Identifier::STATE_OBSOLETE)
        ->and($current->source_id)->toBe($new->id)
        ->and($current->source_type)->toBe(Identifier::class)
        ->and($name->refresh()->state)->toBe(Identifier::STATE_ACTIVE);
});

test('changing the main identifier back revives the removed records of both identifiers', function () {
    $structure = createApiStructure(['identifier' => 'MM00005']);
    $previous = createStructureIdentifier($structure, Identifier::TYPE_MOLMEDB, 'MM00003', [
        'state' => Identifier::STATE_OBSOLETE,
        'source_type' => Identifier::class,
        'source_id' => 999,
    ]);
    $current = createStructureIdentifier($structure, Identifier::TYPE_MOLMEDB, 'MM00005', ['state' => Identifier::STATE_ACTIVE]);
    $previous->delete();
    $current->delete();

    $structure->changeMainIdentifier('MM00003');

    $previous = Identifier::query()->find($previous->id);
    $current = Identifier::query()->find($current->id);

    expect(Identifier::withTrashed()->count())->toBe(2)
        ->and($previous->state)->toBe(Identifier::STATE_ACTIVE)
        ->and($previous->source_id)->toBeNull()
        ->and($previous->source_type)->toBeNull()
        ->and($current->state)->toBe(Identifier::STATE_OBSOLETE)
        ->and($current->source_id)->toBe($previous->id);
});

test('joining structures moves all data of the second into the first and keeps its identifier as obsolete', function () {
    $survivor = createApiStructure(['identifier' => 'MM00001']);
    $survivorMolmedb = createStructureIdentifier($survivor, Identifier::TYPE_MOLMEDB, 'MM00001', ['state' => Identifier::STATE_ACTIVE]);
    createStructureIdentifier($survivor, Identifier::TYPE_PUBCHEM, '702');

    $merged = createApiStructure(['identifier' => 'MM00002']);
    $mergedMolmedb = createStructureIdentifier($merged, Identifier::TYPE_MOLMEDB, 'MM00002', ['state' => Identifier::STATE_ACTIVE]);
    $name = createStructureIdentifier($merged, Identifier::TYPE_NAME, 'Ethanol');
    $duplicatePubchem = createStructureIdentifier($merged, Identifier::TYPE_PUBCHEM, '702');
    $passive = createApiPassiveInteraction(['structure' => $merged]);
    $active = createApiActiveInteraction(['structure' => $merged]);
    $fluorescent = createFluorescentProperty($merged);

    Structure::join_structures($survivor, $merged->refresh());

    expect(Structure::query()->find($merged->id))->toBeNull()
        ->and(Structure::withTrashed()->find($merged->id)->trashed())->toBeTrue()
        ->and($name->refresh()->structure_id)->toBe($survivor->id)
        ->and($name->trashed())->toBeFalse()
        ->and(Identifier::withTrashed()->find($duplicatePubchem->id)->structure_id)->toBe($merged->id)
        ->and(Identifier::withTrashed()->find($duplicatePubchem->id)->trashed())->toBeTrue()
        ->and($survivor->identifiers()->where('type', Identifier::TYPE_PUBCHEM)->count())->toBe(1);

    foreach ([$passive, $active, $fluorescent] as $record) {
        $record = $record::query()->find($record->id);

        expect($record)->not->toBeNull()
            ->and($record->structure_id)->toBe($survivor->id);
    }

    $mergedMolmedb->refresh();

    expect($mergedMolmedb->structure_id)->toBe($survivor->id)
        ->and($mergedMolmedb->state)->toBe(Identifier::STATE_OBSOLETE)
        ->and($mergedMolmedb->source_id)->toBe($survivorMolmedb->id)
        ->and($mergedMolmedb->source_type)->toBe(Identifier::class)
        ->and($survivorMolmedb->refresh()->state)->toBe(Identifier::STATE_ACTIVE);

    // The merged identifier now resolves to the survivor in the public API.
    $this->getJson('/api/v1/structures/MM00002')
        ->assertStatus(301)
        ->assertJsonPath('data.replaced_by', 'MM00001');
});

test('joining into a structure without its own MolMeDB record keeps the merged identifier without a pointer', function () {
    $survivor = createApiStructure(['identifier' => 'MM00001']);
    $merged = createApiStructure(['identifier' => 'MM00002']);

    Structure::join_structures($survivor, $merged);

    $legacy = Identifier::query()->where('value', 'MM00002')->sole();

    expect($legacy->structure_id)->toBe($survivor->id)
        ->and($legacy->type)->toBe(Identifier::TYPE_MOLMEDB)
        ->and($legacy->state)->toBe(Identifier::STATE_OBSOLETE)
        ->and($legacy->source_id)->toBeNull()
        ->and($legacy->source_type)->toBeNull();
});

test('joining revives a removed record of the merged identifier on the survivor', function () {
    $survivor = createApiStructure(['identifier' => 'MM00001']);
    $merged = createApiStructure(['identifier' => 'MM00002']);
    $legacy = createStructureIdentifier($survivor, Identifier::TYPE_MOLMEDB, 'MM00002', ['state' => Identifier::STATE_INVALID]);
    $legacy->delete();

    Structure::join_structures($survivor, $merged);

    $legacy = Identifier::query()->find($legacy->id);

    expect($legacy)->not->toBeNull()
        ->and($legacy->state)->toBe(Identifier::STATE_OBSOLETE)
        ->and(Identifier::withTrashed()->where('value', 'MM00002')->count())->toBe(1);
});

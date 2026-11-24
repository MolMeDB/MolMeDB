<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Models\Identifier;
use App\Models\Structure;
use App\Models\StructureLink;
use App\Services\Structures\LegacyStructureLinksPreprocessor;
use Illuminate\Support\Facades\Schema;

/*
 * The legacy structure_links table maps old MolMeDB identifiers to the
 * structure they belong to now. The preprocessor turns every link into an
 * obsolete MolMeDB identifier record of that structure, and moves any other
 * structure that took over the old identifier to a fresh one.
 */

function createLegacyLink(Structure $structure, string $identifier): StructureLink
{
    return StructureLink::create([
        'structure_id' => $structure->id,
        'identifier' => $identifier,
    ]);
}

function createMolmedbIdentifier(Structure $structure, string $value, int $state = Identifier::STATE_ACTIVE): Identifier
{
    return Identifier::create([
        'structure_id' => $structure->id,
        'type' => Identifier::TYPE_MOLMEDB,
        'value' => $value,
        'state' => $state,
    ]);
}

/**
 * @return array<string, int>
 */
function preprocessLegacyLinks(): array
{
    return app(LegacyStructureLinksPreprocessor::class)->process();
}

/**
 * @param  array<string, int>  $expected
 * @return array<string, int>
 */
function legacyStats(array $expected): array
{
    return [
        'processed' => 0,
        'conflicts' => 0,
        'reassigned' => 0,
        'obsolete_created' => 0,
        'obsolete_updated' => 0,
        'skipped_current_identifier' => 0,
        ...$expected,
    ];
}

function obsoleteMolmedbIdentifier(Structure $structure, string $value): ?Identifier
{
    return Identifier::withTrashed()
        ->where('structure_id', $structure->id)
        ->where('type', Identifier::TYPE_MOLMEDB)
        ->where('value', $value)
        ->first();
}

test('nothing is processed without the legacy table', function () {
    Schema::drop('structure_links');

    expect(preprocessLegacyLinks())->toBe(legacyStats([]));
});

test('an old identifier becomes an obsolete record pointing to the current MolMeDB record', function () {
    $structure = createApiStructure(['identifier' => 'MM00010']);
    $current = createMolmedbIdentifier($structure, 'MM00010');
    createLegacyLink($structure, 'MM00003');

    expect(preprocessLegacyLinks())->toBe(legacyStats(['processed' => 1, 'obsolete_created' => 1]));

    $obsolete = obsoleteMolmedbIdentifier($structure, 'MM00003');
    expect($obsolete->state)->toBe(Identifier::STATE_OBSOLETE)
        ->and($obsolete->source_id)->toBe($current->id)
        ->and($obsolete->source_type)->toBe(Identifier::class)
        ->and($structure->refresh()->identifier)->toBe('MM00010');
});

test('an old identifier of a structure without its own MolMeDB record is kept without a pointer', function () {
    $structure = createApiStructure(['identifier' => 'MM00010']);
    createLegacyLink($structure, 'MM00003');

    preprocessLegacyLinks();

    $obsolete = obsoleteMolmedbIdentifier($structure, 'MM00003');
    expect($obsolete->state)->toBe(Identifier::STATE_OBSOLETE)
        ->and($obsolete->source_id)->toBeNull()
        ->and($obsolete->source_type)->toBeNull();
});

test('a link to the current identifier of its structure is skipped', function () {
    $structure = createApiStructure(['identifier' => 'MM00010']);
    createLegacyLink($structure, 'MM00010');

    expect(preprocessLegacyLinks())->toBe(legacyStats(['processed' => 1, 'skipped_current_identifier' => 1]))
        ->and(Identifier::withTrashed()->count())->toBe(0);
});

test('a removed record of the old identifier is revived as obsolete instead of duplicated', function () {
    $structure = createApiStructure(['identifier' => 'MM00010']);
    $current = createMolmedbIdentifier($structure, 'MM00010');
    $old = createMolmedbIdentifier($structure, 'MM00003', Identifier::STATE_VALIDATED);
    $old->delete();
    createLegacyLink($structure, 'MM00003');

    expect(preprocessLegacyLinks())->toBe(legacyStats(['processed' => 1, 'obsolete_updated' => 1]));

    $old->refresh();
    expect($old->trashed())->toBeFalse()
        ->and($old->state)->toBe(Identifier::STATE_OBSOLETE)
        ->and($old->source_id)->toBe($current->id)
        ->and(Identifier::withTrashed()->where('value', 'MM00003')->count())->toBe(1);
});

test('running the preprocessing again only refreshes the records it created', function () {
    $structure = createApiStructure(['identifier' => 'MM00010']);
    createLegacyLink($structure, 'MM00003');
    preprocessLegacyLinks();

    expect(preprocessLegacyLinks())->toBe(legacyStats(['processed' => 1, 'obsolete_updated' => 1]))
        ->and(Identifier::where('value', 'MM00003')->count())->toBe(1);
});

test('a link to a removed structure is left alone', function () {
    $structure = createApiStructure(['identifier' => 'MM00010']);
    createLegacyLink($structure, 'MM00003');
    $structure->delete();

    expect(preprocessLegacyLinks())->toBe(legacyStats(['processed' => 1]))
        ->and(Identifier::withTrashed()->where('value', 'MM00003')->exists())->toBeFalse();
});

test('a structure that took over an old identifier moves to the next free number', function () {
    $owner = createApiStructure(['identifier' => 'MM00010']);
    $squatter = createApiStructure(['identifier' => 'MM00003']);
    createApiStructure(['identifier' => 'MM00012.1']);
    createLegacyLink($owner, 'MM00003');
    createLegacyLink($owner, 'MM00014');
    createMolmedbIdentifier($owner, 'MM00015', Identifier::STATE_OBSOLETE);

    expect(preprocessLegacyLinks())->toBe(legacyStats([
        'processed' => 2,
        'conflicts' => 1,
        'reassigned' => 1,
        'obsolete_created' => 2,
    ]));

    // The highest number in use anywhere is 15 (a MolMeDB identifier record).
    expect($squatter->refresh()->identifier)->toBe('MM00016')
        ->and(obsoleteMolmedbIdentifier($owner, 'MM00003')->state)->toBe(Identifier::STATE_OBSOLETE);
});

test('a charged form that took over an old identifier gets the next free suffix under its parent', function () {
    $owner = createApiStructure(['identifier' => 'MM00010']);
    $parent = createApiStructure(['identifier' => 'MM00020']);
    createApiStructure(['identifier' => 'MM00020.1', 'parent_id' => $parent->id]);
    $squatter = createApiStructure(['identifier' => 'MM00003', 'parent_id' => $parent->id]);
    createLegacyLink($owner, 'MM00003');
    createMolmedbIdentifier($parent, 'MM00020.2', Identifier::STATE_OBSOLETE);

    preprocessLegacyLinks();

    expect($squatter->refresh()->identifier)->toBe('MM00020.3');
});

test('a charged form whose parent has no usable identifier gets a new top-level identifier', function (?string $parentIdentifier) {
    $owner = createApiStructure(['identifier' => 'MM00010']);
    $parent = createApiStructure(['identifier' => $parentIdentifier]);
    $squatter = createApiStructure(['identifier' => 'MM00003', 'parent_id' => $parent->id]);
    createLegacyLink($owner, 'MM00003');

    preprocessLegacyLinks();

    expect($squatter->refresh()->identifier)->toBe('MM00011');
})->with([
    'no identifier' => [null],
    'charged form identifier' => ['MM00005.1'],
]);

test('a new top-level identifier never reuses the identifier of a removed structure', function () {
    $owner = createApiStructure(['identifier' => 'MM00010']);
    $squatter = createApiStructure(['identifier' => 'MM00003']);
    createLegacyLink($owner, 'MM00003');
    // A removed legacy structure without any MolMeDB identifier record.
    createApiStructure(['identifier' => 'MM00011'])->delete();

    preprocessLegacyLinks();

    expect($squatter->refresh()->identifier)->toBe('MM00012');
});

test('a new charged form identifier never reuses the identifier of a removed charged form', function () {
    $owner = createApiStructure(['identifier' => 'MM00010']);
    $parent = createApiStructure(['identifier' => 'MM00020']);
    createApiStructure(['identifier' => 'MM00020.1', 'parent_id' => $parent->id])->delete();
    $squatter = createApiStructure(['identifier' => 'MM00003', 'parent_id' => $parent->id]);
    createLegacyLink($owner, 'MM00003');

    preprocessLegacyLinks();

    expect($squatter->refresh()->identifier)->toBe('MM00020.2');
});

test('the check command runs the preprocessing and prints its numbers', function () {
    $structure = createApiStructure(['identifier' => 'MM00010']);
    createLegacyLink($structure, 'MM00003');

    $this->artisan('structures:check-internal-identifiers', ['--preprocess-legacy-links' => true])
        ->expectsTable(['Metric', 'Value'], [
            ['processed', 1],
            ['conflicts', 0],
            ['reassigned', 0],
            ['obsolete_created', 1],
            ['obsolete_updated', 0],
            ['skipped_current_identifier', 0],
        ])
        ->assertSuccessful();

    expect(obsoleteMolmedbIdentifier($structure, 'MM00003'))->not->toBeNull();
});

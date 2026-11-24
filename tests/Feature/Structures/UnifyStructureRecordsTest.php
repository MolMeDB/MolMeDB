<?php

require_once __DIR__.'/structure_rdkit_test_helpers.php';

use App\Libraries\Identifiers;
use App\Models\Identifier;
use App\Models\Structure;

/*
 * structures:unify-charges canonizes every structure's SMILES and files each
 * charged form under its neutral representant, creating the representant
 * when it does not exist yet. Structures without any data are removed.
 */

const UNIFY_CHECK_QUESTION = 'Do you want to run it immediately?';

beforeEach(function () {
    fakeRdkitService();
});

test('nothing changes while RDKit is unreachable', function () {
    FakeRdkitService::$reachable = false;
    $empty = createApiStructure(['canonical_smiles' => 'CCO']);

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain('Rdkit is not connected. Stopping...')
        ->doesntExpectOutputToContain('Done.');

    expect(Structure::find($empty->id))->not->toBeNull();
});

test('a structure without any data is removed for good', function () {
    $empty = createApiStructure(['canonical_smiles' => 'CCO']);
    $name = Identifier::create([
        'structure_id' => $empty->id,
        'type' => Identifier::TYPE_NAME,
        'value' => 'Ethanol',
        'state' => Identifier::STATE_VALIDATED,
    ]);
    $kept = createStructureWithData(['canonical_smiles' => 'CCN']);

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain('Structure has no data. Deleting...')
        ->expectsConfirmation(UNIFY_CHECK_QUESTION, 'no')
        ->assertSuccessful();

    expect(Structure::withTrashed()->find($empty->id))->toBeNull()
        ->and(Identifier::withTrashed()->find($name->id))->toBeNull()
        ->and(Structure::find($kept->id))->not->toBeNull();
});

test('a structure without SMILES is reported and the run goes on', function () {
    $withoutSmiles = createStructureWithData(['canonical_smiles' => null]);
    $next = createStructureWithData(['canonical_smiles' => 'OCC']);
    FakeRdkitService::$canonical = ['OCC' => 'CCO'];

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain('Structure ID: '.$withoutSmiles->id.' has no SMILES.')
        ->expectsConfirmation(UNIFY_CHECK_QUESTION, 'no')
        ->assertSuccessful();

    expect($next->refresh()->canonical_smiles)->toBe('CCO');
});

test('a neutral structure gets its canonical SMILES and stays on its own', function () {
    $structure = createStructureWithData(['canonical_smiles' => 'OCC']);
    FakeRdkitService::$canonical = ['OCC' => 'CCO'];

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain('invalid structure SMILES has been fixed')
        ->expectsOutputToContain('structure is self-representative')
        ->expectsConfirmation(UNIFY_CHECK_QUESTION, 'no')
        ->assertSuccessful();

    $structure->refresh();
    expect($structure->canonical_smiles)->toBe('CCO')
        ->and($structure->parent_id)->toBeNull();
});

test('a charged form is filed under its existing neutral representant together with its own charged forms', function () {
    $neutral = createStructureWithData(['canonical_smiles' => 'CC(=O)O']);
    $charged = createStructureWithData(['canonical_smiles' => 'CC(=O)[O-]']);
    $salt = createStructureWithData(['canonical_smiles' => 'CC(=O)[O-].[Na+]', 'parent_id' => $charged->id]);
    FakeRdkitService::$representants = [
        'CC(=O)[O-]' => 'CC(=O)O',
        'CC(=O)[O-].[Na+]' => 'CC(=O)O',
    ];

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain('assigned parent ID: '.$neutral->id)
        ->expectsConfirmation(UNIFY_CHECK_QUESTION, 'no')
        ->assertSuccessful();

    expect($charged->refresh()->parent_id)->toBe($neutral->id)
        ->and($salt->refresh()->parent_id)->toBe($neutral->id)
        ->and($neutral->refresh()->parent_id)->toBeNull();
});

test('a charged form already under its representant is left as it is', function () {
    $neutral = createStructureWithData(['canonical_smiles' => 'CC(=O)O']);
    $charged = createStructureWithData(['canonical_smiles' => 'CC(=O)[O-]', 'parent_id' => $neutral->id]);
    FakeRdkitService::$representants = ['CC(=O)[O-]' => 'CC(=O)O'];

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain('parent ID is correctly assigned')
        ->expectsConfirmation(UNIFY_CHECK_QUESTION, 'no')
        ->assertSuccessful();

    expect($charged->refresh()->parent_id)->toBe($neutral->id)
        ->and(Structure::count())->toBe(2);
});

test('a missing representant is created from RDKit data and the identifiers can be checked right away', function () {
    $charged = createStructureWithData(['identifier' => 'MM00002', 'canonical_smiles' => 'CC(=O)[O-]']);
    FakeRdkitService::$representants = ['CC(=O)[O-]' => 'CC(=O)O'];
    FakeRdkitService::$info = ['CC(=O)O' => [
        'canonized_smiles' => 'CC(=O)O',
        'inchi' => 'InChI=1S/C2H4O2/c1-2(3)4/h1H3,(H,3,4)',
        'inchikey' => 'QTBSBXVTEAMEQO-UHFFFAOYSA-N',
        'MW' => 60.05,
        'LogP' => 0.09,
    ]];

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain('created and assigned representant')
        ->expectsConfirmation(UNIFY_CHECK_QUESTION, 'yes')
        ->assertSuccessful();

    $representant = Structure::where('canonical_smiles', 'CC(=O)O')->sole();
    $representantIdentifier = Identifiers::get_identifier($representant->id);

    expect($representant->inchi)->toBe('InChI=1S/C2H4O2/c1-2(3)4/h1H3,(H,3,4)')
        ->and($representant->inchikey)->toBe('QTBSBXVTEAMEQO-UHFFFAOYSA-N')
        ->and($representant->molecular_weight)->toBe(60.05)
        ->and($representant->logp)->toBe(0.09)
        ->and($representant->identifier)->toBe($representantIdentifier)
        ->and($charged->refresh()->parent_id)->toBe($representant->id)
        ->and($charged->identifier)->toBe($representantIdentifier.'.1');
});

test('a newer duplicate of a neutral structure is joined into the older one on confirmation', function () {
    $older = createStructureWithData(['identifier' => 'MM00001', 'canonical_smiles' => 'CCO']);
    $duplicate = createStructureWithData(['identifier' => 'MM00002', 'canonical_smiles' => 'OCC']);
    FakeRdkitService::$canonical = ['OCC' => 'CCO'];

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain('Found duplicity. Structure has the same SMILES as molecule ID:'.$older->id)
        ->expectsConfirmation('Do you want to join them?', 'yes')
        ->expectsConfirmation(UNIFY_CHECK_QUESTION, 'no')
        ->assertSuccessful();

    expect(Structure::find($duplicate->id))->toBeNull()
        ->and($older->interactionsPassive()->count())->toBe(2)
        ->and(Identifier::where('structure_id', $older->id)->where('value', 'MM00002')->value('state'))
        ->toBe(Identifier::STATE_OBSOLETE);
});

test('declining to join a duplicate stops the run', function () {
    createStructureWithData(['canonical_smiles' => 'CCO']);
    $duplicate = createStructureWithData(['canonical_smiles' => 'CCO']);
    $later = createStructureWithData(['canonical_smiles' => 'NCC']);
    FakeRdkitService::$canonical = ['NCC' => 'CCN'];

    $this->artisan('structures:unify-charges')
        ->expectsConfirmation('Do you want to join them?', 'no')
        ->doesntExpectOutputToContain('Done.');

    expect(Structure::find($duplicate->id))->not->toBeNull()
        ->and($later->refresh()->canonical_smiles)->toBe('NCC');
});

test('an inconsistent structure stops the run', function (Closure $arrange, string $error) {
    $later = $arrange();

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain($error)
        ->doesntExpectOutputToContain('Done.');

    expect($later->refresh()->canonical_smiles)->toBe('NCCC')
        ->and(Structure::count())->toBe(3);
})->with([
    'a neutral structure with a parent' => [
        function (): Structure {
            $parent = createStructureWithData(['canonical_smiles' => 'CCO']);
            createStructureWithData(['canonical_smiles' => 'CCN', 'parent_id' => $parent->id]);
            FakeRdkitService::$canonical = ['NCCC' => 'CCCN'];

            return createStructureWithData(['canonical_smiles' => 'NCCC']);
        },
        'Structure is neutralized, but has parent.',
    ],
    'a charged form with the SMILES of its parent' => [
        function (): Structure {
            $parent = createStructureWithData(['canonical_smiles' => 'CCO']);
            createStructureWithData(['canonical_smiles' => 'CCO', 'parent_id' => $parent->id]);
            FakeRdkitService::$canonical = ['NCCC' => 'CCCN'];

            return createStructureWithData(['canonical_smiles' => 'NCCC']);
        },
        'Found duplicity. Structure has the same SMILES as its parent.',
    ],
]);

test('an RDKit failure stops the run', function (Closure $arrange, string $error) {
    createStructureWithData(['canonical_smiles' => 'CCO']);
    $charged = createStructureWithData(['canonical_smiles' => 'CC(=O)[O-]']);
    $later = createStructureWithData(['canonical_smiles' => 'NCCC']);
    FakeRdkitService::$canonical = ['NCCC' => 'CCCN'];
    FakeRdkitService::$representants = ['CC(=O)[O-]' => 'CC(=O)O'];
    $arrange();

    $this->artisan('structures:unify-charges')
        ->expectsOutputToContain($error)
        ->doesntExpectOutputToContain('Done.');

    expect($later->refresh()->canonical_smiles)->toBe('NCCC')
        ->and($charged->refresh()->parent_id)->toBeNull()
        ->and(Structure::count())->toBe(3);
})->with([
    'canonizing' => [
        fn () => FakeRdkitService::$canonical['CC(=O)[O-]'] = null,
        'Failed to canonize SMILES for structure ID',
    ],
    'finding the representant' => [
        fn () => FakeRdkitService::$representants['CC(=O)[O-]'] = null,
        'Failed to get representant SMILES for structure ID',
    ],
    'describing a new representant' => [
        fn () => null,
        'Failed to get general info for representant SMILES: CC(=O)O',
    ],
]);

test('the run starts at the given structure id', function () {
    $skipped = createApiStructure(['canonical_smiles' => 'CCO']);
    $processed = createStructureWithData(['canonical_smiles' => 'NCC']);
    FakeRdkitService::$canonical = ['NCC' => 'CCN'];

    $this->artisan('structures:unify-charges', ['startId' => $processed->id])
        ->expectsOutputToContain('Total 1 structures will be processed.')
        ->expectsConfirmation(UNIFY_CHECK_QUESTION, 'no')
        ->assertSuccessful();

    expect(Structure::find($skipped->id))->not->toBeNull()
        ->and($processed->refresh()->canonical_smiles)->toBe('CCN');
});

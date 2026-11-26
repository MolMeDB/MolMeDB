<?php

require_once __DIR__.'/structure_rdkit_test_helpers.php';

use App\Models\Structure;
use App\Services\Structures\CanonicalSmilesConversion;
use Illuminate\Support\Facades\DB;
use Modules\PredictionWorkers\Models\PredictionStructure;

/*
 * structures:convert-canonical-smiles writes the stored canonical SMILES of
 * structures and prediction structures in the form the RDKit service writes
 * now (the Kekulé form Bingo reads), so that an equality on canonical_smiles
 * keeps finding a molecule that is already stored. The RDKit service is
 * faked: it maps the old aromatic forms to the Kekulé ones.
 */

const REJECTED_BY_BINGO = 'CN1CCN(C2=c3cc(CO)sc3=Nc3ccccc3N2)CC1';

const READABLE_BY_BINGO = 'CN1CCN(C2=C3C=C(CO)SC3=NC3=CC=CC=C3N2)CC1';

beforeEach(function () {
    fakeRdkitService();
    FakeRdkitService::$canonical = [
        'c1ccccc1' => 'C1=CC=CC=C1',
        'Cc1ccccc1' => 'CC1=CC=CC=C1',
        'OCc1ccccc1' => 'OCC1=CC=CC=C1',
        REJECTED_BY_BINGO => READABLE_BY_BINGO,
        'not a molecule' => null,
    ];
});

afterEach(function () {
    resetApiRouteRdkitState();
});

test('a dry run reports what would change and changes nothing', function () {
    $benzene = createApiStructure(['canonical_smiles' => 'c1ccccc1']);
    $ethanol = createApiStructure(['canonical_smiles' => 'CCO']);
    $broken = createApiStructure(['canonical_smiles' => 'not a molecule']);

    $this->artisan('structures:convert-canonical-smiles', ['--dry-run' => true, '--only' => 'structures'])
        ->expectsOutputToContain('Dry run')
        ->expectsTable(['', 'records'], [
            ['already in the canonical form', 1],
            ['to convert', 1],
            ['unreadable by RDKit (left as they are)', 1],
            ['colliding (left as they are)', '0 in 0 groups'],
        ])
        ->assertSuccessful();

    expect($benzene->refresh()->canonical_smiles)->toBe('c1ccccc1')
        ->and($ethanol->refresh()->canonical_smiles)->toBe('CCO')
        ->and($broken->refresh()->canonical_smiles)->toBe('not a molecule');
});

test('the stored forms are converted, removed records too, and a second run changes nothing', function () {
    $benzene = createApiStructure(['canonical_smiles' => 'c1ccccc1']);
    $toluene = createApiStructure(['canonical_smiles' => 'Cc1ccccc1']);
    $toluene->delete();
    $ethanol = createApiStructure(['canonical_smiles' => 'CCO']);
    $updatedAt = $benzene->updated_at;

    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'structures'])
        ->expectsOutputToContain('2 records converted')
        ->assertSuccessful();

    expect($benzene->refresh()->canonical_smiles)->toBe('C1=CC=CC=C1')
        ->and($benzene->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(Structure::withTrashed()->find($toluene->id)->canonical_smiles)->toBe('CC1=CC=CC=C1')
        ->and($ethanol->refresh()->canonical_smiles)->toBe('CCO');

    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'structures'])
        ->expectsTable(['', 'records'], [
            ['already in the canonical form', 3],
            ['to convert', 0],
            ['unreadable by RDKit (left as they are)', 0],
            ['colliding (left as they are)', '0 in 0 groups'],
        ])
        ->doesntExpectOutputToContain('records converted')
        ->assertSuccessful();

    expect(Structure::withTrashed()->orderBy('id')->pluck('canonical_smiles')->all())
        ->toBe(['C1=CC=CC=C1', 'CC1=CC=CC=C1', 'CCO']);
});

test('records whose forms would be equal are reported and left as they are', function () {
    // One record of benzene written by an older RDKit, one written in the new form.
    $old = createApiStructure(['canonical_smiles' => 'c1ccccc1', 'identifier' => 'MM00010']);
    $new = createApiStructure(['canonical_smiles' => 'C1=CC=CC=C1', 'identifier' => 'MM00011']);
    $toluene = createApiStructure(['canonical_smiles' => 'Cc1ccccc1']);
    $report = tempnam(sys_get_temp_dir(), 'conversion-report');

    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'structures', '--report' => $report])
        ->expectsOutputToContain('decide which one stays')
        ->expectsOutputToContain("php artisan structures:join-records {$old->id} {$new->id}")
        ->expectsOutputToContain('1 records converted')
        ->assertSuccessful();

    expect($old->refresh()->canonical_smiles)->toBe('c1ccccc1')
        ->and($new->refresh()->canonical_smiles)->toBe('C1=CC=CC=C1')
        ->and($toluene->refresh()->canonical_smiles)->toBe('CC1=CC=CC=C1');

    $rows = array_map(str_getcsv(...), file($report, FILE_IGNORE_NEW_LINES));
    unlink($report);

    expect($rows)->toBe([
        ['database', 'reason', 'id', 'identifier', 'stored_smiles', 'canonical_smiles', 'deleted'],
        ['structures', 'collision', (string) $old->id, 'MM00010', 'c1ccccc1', 'C1=CC=CC=C1', ''],
        ['structures', 'collision', (string) $new->id, 'MM00011', 'C1=CC=CC=C1', 'C1=CC=CC=C1', ''],
    ]);

    // Joined, the record that stays gets the new form; the removed one
    // sharing it collides with nothing.
    $this->artisan('structures:join-records', ['id1' => $old->id, 'id2' => $new->id])->assertSuccessful();

    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'structures', '--dry-run' => true])
        ->expectsTable(['', 'records'], [
            ['already in the canonical form', 3],
            ['to convert', 0],
            ['unreadable by RDKit (left as they are)', 0],
            ['colliding (left as they are)', '0 in 0 groups'],
        ]);

    expect($old->refresh()->canonical_smiles)->toBe('C1=CC=CC=C1')
        ->and(Structure::query()->storedAs('C1=CC=CC=C1')->first()->id)->toBe($old->id);
});

test('a removed record is converted even when it shares the form of one that stays', function () {
    $removed = createApiStructure(['canonical_smiles' => 'c1ccccc1']);
    $removed->delete();
    $kept = createApiStructure(['canonical_smiles' => 'C1=CC=CC=C1']);

    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'structures'])
        ->expectsOutputToContain('1 records converted')
        ->assertSuccessful();

    expect(Structure::withTrashed()->find($removed->id)->canonical_smiles)->toBe('C1=CC=CC=C1')
        ->and(Structure::query()->storedAs('C1=CC=CC=C1')->first()->id)->toBe($kept->id);
});

test('the prediction structures are converted the same way', function () {
    $benzene = PredictionStructure::query()->create(['canonical_smiles' => 'c1ccccc1']);
    $alcohol = PredictionStructure::query()->create(['canonical_smiles' => 'OCc1ccccc1']);
    $ethanol = PredictionStructure::query()->create(['canonical_smiles' => 'CCO']);

    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'predictions'])
        ->expectsOutputToContain('2 records converted')
        ->assertSuccessful();

    expect($benzene->refresh()->canonical_smiles)->toBe('C1=CC=CC=C1')
        ->and($alcohol->refresh()->canonical_smiles)->toBe('OCC1=CC=CC=C1')
        ->and($ethanol->refresh()->canonical_smiles)->toBe('CCO');

    // A new submission of the molecule now finds the converted record.
    expect(PredictionStructure::query()->firstOrCreate(['canonical_smiles' => 'OCC1=CC=CC=C1'])->id)->toBe($alcohol->id);
});

test('records written or changed between the scan and the update are left as they are', function () {
    $benzene = createApiStructure(['canonical_smiles' => 'c1ccccc1']);
    $toluene = createApiStructure(['canonical_smiles' => 'Cc1ccccc1']);
    $alcohol = createApiStructure(['canonical_smiles' => 'OCc1ccccc1']);
    $conversion = app(CanonicalSmilesConversion::class);

    expect($conversion->scan(CanonicalSmilesConversion::TARGET_STRUCTURES)->convertible)->toBe(3);

    // Meanwhile benzene is stored once more in the new form, and the
    // alcohol is edited.
    $newBenzene = createApiStructure(['canonical_smiles' => 'C1=CC=CC=C1']);
    $alcohol->forceFill(['canonical_smiles' => 'OCC1=CC=CC=C1'])->save();

    expect($conversion->apply())->toBe(['converted' => 1, 'changed_meanwhile' => 1, 'collided_meanwhile' => 1])
        ->and($benzene->refresh()->canonical_smiles)->toBe('c1ccccc1')
        ->and($newBenzene->refresh()->canonical_smiles)->toBe('C1=CC=CC=C1')
        ->and($toluene->refresh()->canonical_smiles)->toBe('CC1=CC=CC=C1')
        ->and($alcohol->refresh()->canonical_smiles)->toBe('OCC1=CC=CC=C1');

    // The next run reports the pair.
    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'structures', '--dry-run' => true])
        ->expectsOutputToContain("php artisan structures:join-records {$benzene->id} {$newBenzene->id}");
});

test('nothing is changed when RDKit is out of reach', function () {
    $benzene = createApiStructure(['canonical_smiles' => 'c1ccccc1']);
    FakeRdkitService::$reachable = false;
    resetApiRouteRdkitState();

    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'structures'])
        ->expectsOutputToContain('The RDKit service is out of reach.')
        ->assertFailed();

    expect($benzene->refresh()->canonical_smiles)->toBe('c1ccccc1');
});

test('nothing is changed when RDKit fails on a batch', function () {
    $benzene = createApiStructure(['canonical_smiles' => 'c1ccccc1']);
    FakeRdkitService::$batchAvailable = false;

    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'structures'])
        ->expectsOutputToContain('did not canonize a batch')
        ->assertFailed();

    expect($benzene->refresh()->canonical_smiles)->toBe('c1ccccc1');
});

test('an unknown target is refused', function () {
    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'everything'])
        ->expectsOutputToContain('--only takes')
        ->assertExitCode(2);
});

test('a structure Bingo rejected becomes searchable once converted, and the index is rebuilt', function () {
    $rejected = createApiStructure(['canonical_smiles' => REJECTED_BY_BINGO]);
    createApiStructure(['canonical_smiles' => 'CCO']);

    expect(DB::scalar('SELECT bingo.checkMolecule(?)', [REJECTED_BY_BINGO]))->not->toBeNull()
        ->and($rejected->isSearchableBySimilarity())->toBeFalse()
        ->and(Structure::query()->exactMolecule(READABLE_BY_BINGO)->pluck('id')->all())->toBe([]);

    $this->artisan('structures:convert-canonical-smiles', ['--only' => 'structures'])
        ->expectsOutputToContain('Bingo rejects 1 of the records as they are stored and would reject 0 after the conversion.')
        ->expectsOutputToContain('1 records converted')
        ->expectsOutputToContain('Index structures_canonical_smiles_bingo_index rebuilt')
        ->assertSuccessful();

    $rejected->refresh();

    expect($rejected->canonical_smiles)->toBe(READABLE_BY_BINGO)
        ->and($rejected->isSearchableBySimilarity())->toBeTrue()
        ->and(Structure::query()->exactMolecule(READABLE_BY_BINGO)->pluck('id')->all())->toBe([$rejected->id])
        ->and(Structure::query()->containingSubstructure('C1=CSC=C1')->pluck('id')->all())->toBe([$rejected->id])
        ->and(Structure::query()->similarTo(READABLE_BY_BINGO, 0.9)->pluck('structures.id')->all())->toBe([$rejected->id]);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

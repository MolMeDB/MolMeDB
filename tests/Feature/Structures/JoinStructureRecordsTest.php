<?php

require_once __DIR__.'/structure_rdkit_test_helpers.php';

use App\Models\Identifier;
use App\Models\InteractionPassive;
use App\Models\Structure;

/*
 * structures:join-records merges the second structure into the first, but
 * only after RDKit confirms both are the same molecule.
 */

beforeEach(function () {
    fakeRdkitService();
});

test('two records of the same molecule are joined into the first one', function () {
    $survivor = createStructureWithData(['identifier' => 'MM00001', 'canonical_smiles' => 'CCO']);
    $merged = createApiStructure(['identifier' => 'MM00002', 'canonical_smiles' => 'OCC']);
    $interaction = createStructureInteraction($merged);
    FakeRdkitService::$canonical = ['OCC' => 'CCO'];

    $this->artisan('structures:join-records', ['id1' => $survivor->id, 'id2' => $merged->id])
        ->expectsOutputToContain('Done.')
        ->assertSuccessful();

    expect(Structure::find($merged->id))->toBeNull()
        ->and(Structure::withTrashed()->find($merged->id)->trashed())->toBeTrue()
        ->and(InteractionPassive::find($interaction->id)->structure_id)->toBe($survivor->id)
        ->and($survivor->interactionsPassive()->count())->toBe(2)
        ->and(Identifier::where('structure_id', $survivor->id)->where('value', 'MM00002')->value('state'))
        ->toBe(Identifier::STATE_OBSOLETE);
});

test('records are not joined when', function (Closure $arrange, string $error) {
    $first = createStructureWithData(['identifier' => 'MM00001', 'canonical_smiles' => 'CCO']);
    $second = createStructureWithData(['identifier' => 'MM00002', 'canonical_smiles' => 'CCN']);
    $ids = $arrange($first, $second);

    $this->artisan('structures:join-records', $ids)
        ->expectsOutputToContain($error)
        ->doesntExpectOutputToContain('Done.');

    expect(Structure::count())->toBe(2)
        ->and($first->interactionsPassive()->count())->toBe(1)
        ->and($second->interactionsPassive()->count())->toBe(1)
        ->and(Identifier::count())->toBe(0);
})->with([
    'a structure does not exist' => [
        fn (Structure $first, Structure $second): array => ['id1' => $first->id, 'id2' => $second->id + 1000],
        'One of the structures does not exist.',
    ],
    'a structure is removed' => [
        function (Structure $first, Structure $second): array {
            $third = createApiStructure(['canonical_smiles' => 'CCO']);
            $third->delete();

            return ['id1' => $first->id, 'id2' => $third->id];
        },
        'One of the structures does not exist.',
    ],
    'RDKit is unreachable' => [
        function (Structure $first, Structure $second): array {
            FakeRdkitService::$reachable = false;

            return ['id1' => $first->id, 'id2' => $second->id];
        },
        'Rdkit is not connected.',
    ],
    'RDKit cannot canonize a SMILES' => [
        function (Structure $first, Structure $second): array {
            FakeRdkitService::$canonical = ['CCN' => null];

            return ['id1' => $first->id, 'id2' => $second->id];
        },
        'Could not canonize one of the structures.',
    ],
    'the molecules differ' => [
        fn (Structure $first, Structure $second): array => ['id1' => $first->id, 'id2' => $second->id],
        'The structures have different SMILES.',
    ],
]);

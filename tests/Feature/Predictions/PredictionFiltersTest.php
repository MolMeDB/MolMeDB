<?php

require_once __DIR__.'/prediction_helpers.php';

use App\Models\User;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionDataset;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionResult;
use Modules\PredictionWorkers\Models\PredictionStructure;

/*
 * Filtering and sorting of the prediction lists the frontend reads: the
 * records and structures of a dataset and the signed-in user's datasets.
 */

/**
 * A dataset of the owner with one prediction per given attribute set.
 *
 * @param  array<string, array<string, mixed>>  $predictions  name => attributes
 * @return array{dataset: PredictionDataset, predictions: array<string, Prediction>}
 */
function datasetWithPredictions(User $owner, array $predictions): array
{
    $first = array_key_first($predictions);
    ['dataset' => $dataset, 'prediction' => $firstPrediction] = createPredictionDataset($owner, $predictions[$first]);
    $created = [$first => $firstPrediction];

    foreach (array_slice($predictions, 1, preserve_keys: true) as $name => $attributes) {
        $prediction = Prediction::query()->create([
            'structure_id' => PredictionStructure::query()->create(['canonical_smiles' => $attributes['smiles'] ?? 'C'.Str::random(6)])->id,
            'state' => Prediction::STATE_RUNNING,
            'step' => Prediction::STEP_COSMO,
            'temperature' => 25.0,
            'membrane_id' => $dataset->membrane_id,
            'method_type' => 'cosmoperm',
            ...collect($attributes)->except('smiles')->all(),
        ]);
        $dataset->predictions()->attach($prediction->id);
        $created[$name] = $prediction;
    }

    return ['dataset' => $dataset, 'predictions' => $created];
}

/**
 * @param  array<string, mixed>  $query
 * @return array<int, int>
 */
function filteredRecordIds(User $owner, PredictionDataset $dataset, array $query): array
{
    return collect(test()->actingAs($owner)
        ->getJson("/api/predictions/datasets/{$dataset->id}/records?".http_build_query($query))
        ->assertOk()
        ->json('data'))
        ->pluck('id')
        ->all();
}

test('records are filtered by their effective state', function (mixed $state, array $expected) {
    $owner = User::factory()->create();
    ['dataset' => $dataset, 'predictions' => $predictions] = datasetWithPredictions($owner, [
        'prepared' => ['state' => Prediction::STATE_PREPARED],
        'running' => ['state' => Prediction::STATE_RUNNING],
        'paused' => ['state' => Prediction::STATE_RUNNING, 'remote_paused_at' => now()],
        'failed' => ['state' => Prediction::STATE_ERROR],
        'finished' => ['state' => Prediction::STATE_FINISHED],
    ]);

    $ids = filteredRecordIds($owner, $dataset, ['state' => $state, 'sortBy' => 'id', 'sortByDirection' => 'asc']);

    expect($ids)->toBe(collect($expected)->map(fn (string $name): int => $predictions[$name]->id)->all());
})->with([
    'running excludes paused' => [(string) Prediction::STATE_RUNNING, ['running']],
    'paused' => ['paused', ['paused']],
    'several states as a list' => [Prediction::STATE_PREPARED.','.Prediction::STATE_ERROR, ['prepared', 'failed']],
    'running and paused as an array' => [[(string) Prediction::STATE_RUNNING, 'paused'], ['running', 'paused']],
    'non-numeric values are ignored' => ['bogus', ['prepared', 'running', 'paused', 'failed', 'finished']],
    'empty' => ['', ['prepared', 'running', 'paused', 'failed', 'finished']],
]);

test('records are filtered by step and by having a result', function () {
    $owner = User::factory()->create();
    $file = PredictionFile::query()->create(['type' => PredictionFile::TYPE_RESULT_COSMO_XML, 'mime' => 'application/xml', 'storage' => 'local', 'path' => 'r/cosmo.xml', 'hash' => md5('r')]);
    $result = PredictionResult::query()->create(['file_id' => $file->id, 'data' => []]);
    ['dataset' => $dataset, 'predictions' => $predictions] = datasetWithPredictions($owner, [
        'pending' => ['step' => Prediction::STEP_PENDING],
        'optimizing' => ['step' => Prediction::STEP_OPTIMIZATION],
        'stored' => ['step' => Prediction::STEP_RESULT_DB_STORE, 'state' => Prediction::STATE_FINISHED, 'result_id' => $result->id],
    ]);
    $sorted = ['sortBy' => 'id', 'sortByDirection' => 'asc'];

    expect(filteredRecordIds($owner, $dataset, ['step' => Prediction::STEP_PENDING.','.Prediction::STEP_OPTIMIZATION, ...$sorted]))
        ->toBe([$predictions['pending']->id, $predictions['optimizing']->id])
        ->and(filteredRecordIds($owner, $dataset, ['step' => 'x', ...$sorted]))->toHaveCount(3)
        ->and(filteredRecordIds($owner, $dataset, ['hasResults' => 1]))->toBe([$predictions['stored']->id])
        ->and(filteredRecordIds($owner, $dataset, ['hasResults' => 0]))->toHaveCount(3);
});

test('records are found by their id and sorted either way', function () {
    $owner = User::factory()->create();
    ['dataset' => $dataset, 'predictions' => $predictions] = datasetWithPredictions($owner, [
        'first' => ['priority' => Prediction::PRIORITY_LOW],
        'second' => ['priority' => Prediction::PRIORITY_HIGH],
        'third' => ['priority' => Prediction::PRIORITY_MEDIUM],
    ]);
    $ids = collect($predictions)->map->id;

    expect(filteredRecordIds($owner, $dataset, ['query' => 'ID: '.$ids['second']]))->toBe([$ids['second']])
        ->and(filteredRecordIds($owner, $dataset, ['query' => '   ', 'sortBy' => 'id', 'sortByDirection' => 'asc']))->toBe($ids->values()->all())
        ->and(filteredRecordIds($owner, $dataset, ['sortBy' => 'priority', 'sortByDirection' => 'asc']))->toBe([$ids['first'], $ids['third'], $ids['second']])
        ->and(filteredRecordIds($owner, $dataset, ['sortBy' => 'priority', 'sortByDirection' => 'DESC']))->toBe([$ids['second'], $ids['third'], $ids['first']]);
});

test('records of another dataset cannot be reached by adding its id to the filter', function () {
    $owner = User::factory()->create();
    ['dataset' => $own, 'predictions' => $ownPredictions] = datasetWithPredictions($owner, ['own' => []]);
    ['dataset' => $foreign] = createPredictionDataset(User::factory()->create());
    $records = fn (string $query): array => collect($this->actingAs($owner)
        ->getJson("/api/predictions/datasets/{$own->id}/records?{$query}")
        ->assertOk()
        ->json('data'))->pluck('id')->all();

    // The route's own dataset id overrides datasetId; another spelling that
    // still reaches the filter (method names are case-insensitive) only
    // narrows the records to both datasets at once.
    expect($records("datasetId={$foreign->id}"))->toBe([$ownPredictions['own']->id])
        ->and($records("datasetid={$foreign->id}"))->toBe([]);
});

test('the structures of a dataset are listed and sorted', function () {
    $owner = User::factory()->create();
    ['dataset' => $dataset, 'predictions' => $predictions] = datasetWithPredictions($owner, [
        'b' => [],
        'a' => ['smiles' => 'AAA'],
        'c' => ['smiles' => 'ZZZ'],
    ]);
    $predictions['b']->predictionStructure->update(['canonical_smiles' => 'MMM']);
    createPredictionDataset(User::factory()->create());

    $smiles = fn (string $direction): array => collect($this->actingAs($owner)
        ->getJson("/api/predictions/datasets/{$dataset->id}/structures?sortBy=canonical_smiles&sortByDirection={$direction}")
        ->assertOk()
        ->json('data'))->pluck('canonical_smiles')->all();

    expect($smiles('asc'))->toBe(['AAA', 'MMM', 'ZZZ'])
        ->and($smiles('desc'))->toBe(['ZZZ', 'MMM', 'AAA']);
});

test('the user\'s datasets are searched by comment or id and sorted', function () {
    $owner = User::factory()->create();
    ['dataset' => $ethanol] = createPredictionDataset($owner);
    ['dataset' => $caffeine] = createPredictionDataset($owner);
    $caffeine->update(['comment' => 'Caffeine at 37 °C']);
    ['dataset' => $foreign] = createPredictionDataset(User::factory()->create());
    $foreign->update(['comment' => 'Caffeine of someone else']);

    $ids = fn (array $query): array => collect($this->actingAs($owner)
        ->getJson('/api/predictions/datasets?'.http_build_query($query))
        ->assertOk()
        ->json('data'))->pluck('id')->all();

    expect($ids(['query' => 'CAFFEINE']))->toBe([$caffeine->id])
        ->and($ids(['query' => 'id:'.$ethanol->id]))->toBe([$ethanol->id])
        ->and($ids(['query' => 'id:'.$foreign->id]))->toBe([])
        ->and($ids(['query' => 'id:abc']))->toBe([])
        ->and($ids(['query' => '', 'sortBy' => 'id', 'sortByDirection' => 'asc']))->toBe([$ethanol->id, $caffeine->id])
        ->and($ids(['sortBy' => 'comment', 'sortByDirection' => 'desc']))->toBe([$ethanol->id, $caffeine->id]);
});

dataset('unsortable keys', [
    'unknown column' => 'no_such_column',
    'column of another table' => 'users.email',
    'JSON path injection' => "id->x' desc, (select 1/0)--",
    'not a string' => [['id']],
]);

test('records sorted by anything but their own column fall back to the id', function (mixed $sortBy) {
    $owner = User::factory()->create();
    ['dataset' => $dataset, 'predictions' => $predictions] = datasetWithPredictions($owner, ['first' => [], 'second' => [], 'third' => []]);
    $ids = collect($predictions)->map->id->values();

    expect(filteredRecordIds($owner, $dataset, ['sort_by' => $sortBy, 'sort_by_direction' => 'desc']))->toBe($ids->reverse()->values()->all())
        ->and(filteredRecordIds($owner, $dataset, ['sort_by' => $sortBy, 'sort_by_direction' => 'asc']))->toBe($ids->all());
})->with('unsortable keys');

test('structures sorted by anything but their own column fall back to the id', function (mixed $sortBy) {
    $owner = User::factory()->create();
    ['dataset' => $dataset, 'predictions' => $predictions] = datasetWithPredictions($owner, ['b' => [], 'a' => ['smiles' => 'AAA'], 'c' => ['smiles' => 'ZZZ']]);
    $predictions['b']->predictionStructure->update(['canonical_smiles' => 'MMM']);

    $smiles = fn (string $direction): array => collect($this->actingAs($owner)
        ->getJson("/api/predictions/datasets/{$dataset->id}/structures?".http_build_query(['sort_by' => $sortBy, 'sort_by_direction' => $direction]))
        ->assertOk()
        ->json('data'))->pluck('canonical_smiles')->all();

    expect($smiles('desc'))->toBe(['ZZZ', 'AAA', 'MMM'])
        ->and($smiles('asc'))->toBe(['MMM', 'AAA', 'ZZZ']);
})->with('unsortable keys');

test('datasets sorted by anything but their own column fall back to the id', function (mixed $sortBy) {
    $owner = User::factory()->create();
    ['dataset' => $first] = createPredictionDataset($owner);
    ['dataset' => $second] = createPredictionDataset($owner);
    $first->update(['comment' => 'Zeta']);
    $second->update(['comment' => 'Alpha']);

    $ids = fn (string $direction): array => collect($this->actingAs($owner)
        ->getJson('/api/predictions/datasets?'.http_build_query(['sort_by' => $sortBy, 'sort_by_direction' => $direction]))
        ->assertOk()
        ->json('data'))->pluck('id')->all();

    expect($ids('desc'))->toBe([$second->id, $first->id])
        ->and($ids('asc'))->toBe([$first->id, $second->id]);
})->with('unsortable keys');

test('lists sort by an own column in both directions', function () {
    $owner = User::factory()->create();
    ['dataset' => $first] = createPredictionDataset($owner);
    ['dataset' => $second] = createPredictionDataset($owner);
    $first->update(['comment' => 'Zeta']);
    $second->update(['comment' => 'Alpha']);

    $ids = fn (string $direction): array => collect($this->actingAs($owner)
        ->getJson("/api/predictions/datasets?sort_by=comment&sort_by_direction={$direction}")
        ->assertOk()
        ->json('data'))->pluck('id')->all();

    expect($ids('asc'))->toBe([$second->id, $first->id])
        ->and($ids('desc'))->toBe([$first->id, $second->id]);
});

test('filter helpers and malformed sort directions cannot be called from the query string', function (array $query) {
    $owner = User::factory()->create();
    ['dataset' => $dataset, 'predictions' => $predictions] = datasetWithPredictions($owner, ['only' => []]);

    $this->actingAs($owner)->getJson("/api/predictions/datasets/{$dataset->id}/records?".http_build_query($query))
        ->assertOk()
        ->assertJsonPath('data.0.id', $predictions['only']->id);
    $this->actingAs($owner)->getJson("/api/predictions/datasets/{$dataset->id}/structures?".http_build_query($query))->assertOk();
    $this->actingAs($owner)->getJson('/api/predictions/datasets?'.http_build_query($query))->assertOk();
})->with([
    'sort column helper' => [['sortable_column' => 'id']],
    'filter value helpers' => [['integer_filter_values' => '1', 'filter_values' => '1']],
    'array direction' => [['sort_by' => 'id', 'sort_by_direction' => ['asc']]],
]);

test('datasets cannot be sorted by their access token', function () {
    $owner = User::factory()->create();
    ['dataset' => $first] = createPredictionDataset($owner);
    ['dataset' => $second] = createPredictionDataset($owner);
    $first->update(['token' => 'zzz-token']);
    $second->update(['token' => 'aaa-token']);

    $ids = collect($this->actingAs($owner)
        ->getJson('/api/predictions/datasets?sort_by=token&sort_by_direction=asc')
        ->assertOk()
        ->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$first->id, $second->id]);
});

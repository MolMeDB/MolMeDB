<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Dataset;
use App\Models\Publication;

/*
 * Filtering and sorting of the internal (frontend) API listings that go
 * through App\ModelFilters\InteractionPassiveFilter, InteractionActiveFilter
 * and PublicationFilter.
 */

beforeEach(function () {
    prepareApiEndpointTestEnvironment();
});

afterEach(function () {
    resetApiRouteCdkDepictState();
    resetApiRouteRdkitState();
});

/**
 * Three passive interactions of one structure, each measured on a different
 * membrane and method.
 *
 * @return array<string, mixed>
 */
function createPassiveInteractionsOnDifferentDatasets(): array
{
    $structure = createApiStructure(['identifier' => 'MM02000']);
    $datasets = [];

    foreach ([['POPC', 'BLM', 30.0], ['DOPC', 'PAMPA', 10.0], ['SOPC', 'CACO', 20.0]] as [$membrane, $method, $temperature]) {
        $dataset = createApiDataset([
            'type' => Dataset::TYPE_PASSIVE,
            'membrane' => createApiMembrane(['name' => "{$membrane} bilayer", 'abbreviation' => $membrane]),
            'method' => createApiMethod(['name' => "{$method} assay", 'abbreviation' => $method]),
        ]);

        $datasets[$membrane] = $dataset;
        createApiPassiveInteraction([
            'structure' => $structure,
            'dataset' => $dataset,
            'temperature' => $temperature,
            'note' => "{$membrane}/{$method}",
        ]);
    }

    // Another structure's interaction on the same membrane is never listed.
    createApiPassiveInteraction(['dataset' => $datasets['DOPC'], 'note' => 'other structure']);

    return ['structure' => $structure, 'datasets' => $datasets];
}

/**
 * @return array<int, string>
 */
function passiveNotes(string $query): array
{
    return test()->getJson(apiRoutePath('api/interactions/passive/structure/MM02000').$query)
        ->assertOk()
        ->json('data.*.note');
}

test('passive interactions of a structure can be narrowed to membranes and methods', function () {
    $datasets = createPassiveInteractionsOnDifferentDatasets()['datasets'];
    $dopc = $datasets['DOPC'];
    $sopc = $datasets['SOPC'];

    expect(passiveNotes('?membrane_ids[]='.$dopc->membrane_id))->toBe(['DOPC/PAMPA'])
        ->and(passiveNotes('?membrane_ids[]='.$dopc->membrane_id.'&membrane_ids[]='.$sopc->membrane_id))
        ->toEqualCanonicalizing(['DOPC/PAMPA', 'SOPC/CACO'])
        ->and(passiveNotes('?method_ids[]='.$sopc->method_id))->toBe(['SOPC/CACO'])
        ->and(passiveNotes('?membrane_ids[]='.$dopc->membrane_id.'&method_ids[]='.$sopc->method_id))->toBe([])
        ->and(passiveNotes('?membrane_ids[]=0'))->toBe([]);
});

test('passive interactions of a structure are sorted by membrane, method or a column of their own', function (string $query, array $expected) {
    createPassiveInteractionsOnDifferentDatasets();

    expect(passiveNotes($query))->toBe($expected);
})->with([
    'membrane ascending' => ['?sort_by=membrane&sort_by_direction=asc', ['DOPC/PAMPA', 'POPC/BLM', 'SOPC/CACO']],
    'membrane descending' => ['?sort_by=membrane&sort_by_direction=desc', ['SOPC/CACO', 'POPC/BLM', 'DOPC/PAMPA']],
    'method ascending' => ['?sort_by=method&sort_by_direction=ASC', ['POPC/BLM', 'SOPC/CACO', 'DOPC/PAMPA']],
    'column ascending' => ['?sort_by=temperature&sort_by_direction=asc', ['DOPC/PAMPA', 'SOPC/CACO', 'POPC/BLM']],
    'unknown direction sorts descending' => ['?sort_by=temperature&sort_by_direction=sideways', ['POPC/BLM', 'SOPC/CACO', 'DOPC/PAMPA']],
    'direction alone sorts by id' => ['?sort_by_direction=desc', ['SOPC/CACO', 'DOPC/PAMPA', 'POPC/BLM']],
]);

test('sorting passive interactions by membrane combines with the membrane filter and keeps the page metadata', function () {
    $datasets = createPassiveInteractionsOnDifferentDatasets()['datasets'];

    $response = $this->getJson(apiRoutePath('api/interactions/passive/structure/MM02000')
        .'?sort_by=membrane&sort_by_direction=asc&per_page=1'
        .'&membrane_ids[]='.$datasets['POPC']->membrane_id.'&membrane_ids[]='.$datasets['SOPC']->membrane_id)
        ->assertOk();

    expect($response->json('data.*.note'))->toBe(['POPC/BLM'])
        ->and($response->json('meta.total'))->toBe(2)
        // The joined tables do not leak into the listed interaction.
        ->and($response->json('data.0.dataset.id'))->toBe($datasets['POPC']->id);
});

test('a structure id in the query cannot widen passive interactions to another structure', function (string $key) {
    createPassiveInteractionsOnDifferentDatasets();
    $other = createApiPassiveInteraction(['note' => 'foreign']);

    expect(passiveNotes("?{$key}=".$other->structure_id))->not->toContain('foreign');
})->with(['structure_id', 'structureId']);

test('active interactions of a structure are sorted by a column of their own', function (string $query, array $expected) {
    $structure = createApiStructure(['identifier' => 'MM02001']);
    $dataset = createApiDataset(['type' => Dataset::TYPE_ACTIVE]);

    foreach (['low' => 0.5, 'high' => 9.0, 'mid' => 3.0] as $note => $km) {
        createApiActiveInteraction(['structure' => $structure, 'dataset' => $dataset, 'km' => $km, 'note' => $note]);
    }
    createApiActiveInteraction(['dataset' => $dataset, 'note' => 'other structure']);

    expect($this->getJson(apiRoutePath('api/interactions/active/structure/MM02001').$query)
        ->assertOk()
        ->json('data.*.note'))->toBe($expected);
})->with([
    'ascending' => ['?sort_by=km&sort_by_direction=asc', ['low', 'mid', 'high']],
    'descending' => ['?sort_by=km&sort_by_direction=desc', ['high', 'mid', 'low']],
    'unknown direction sorts descending' => ['?sort_by=km&sort_by_direction=up', ['high', 'mid', 'low']],
    'direction alone sorts by id' => ['?sort_by_direction=desc', ['mid', 'high', 'low']],
]);

test('interactions of an unknown structure answer 404', function (string $type) {
    $this->getJson(apiRoutePath("api/interactions/{$type}/structure/MM09999"))
        ->assertNotFound()
        ->assertJsonPath('message', 'Structure not found');
})->with(['passive', 'active']);

test('publications are searched case-insensitively by citation, title or DOI', function (string $endpoint) {
    createApiPublication(['citation' => 'Novak P.: Lipid bilayers.', 'title' => 'Bilayers', 'doi' => '10.1000/aaa']);
    createApiPublication(['citation' => 'Smith J.: Something else.', 'title' => 'Permeation of DRUGS', 'doi' => '10.1000/bbb']);
    createApiPublication(['citation' => 'Doe J.: Unrelated.', 'title' => 'Unrelated', 'doi' => '10.1000/XYZ-777']);
    createApiPublication(['citation' => 'Novak P.: Removed.', 'title' => 'Removed', 'doi' => null])->delete();

    $titles = fn (string $query): array => $this->getJson(apiRoutePath($endpoint).'?query='.urlencode($query))
        ->assertOk()
        ->json('data.*.title');

    expect($titles('NOVAK'))->toBe(['Bilayers'])
        ->and($titles('drugs'))->toBe(['Permeation of DRUGS'])
        ->and($titles('xyz-777'))->toBe(['Unrelated'])
        ->and($titles('nothing like this'))->toBe([]);
})->with(['api/publication', 'api/search/datasets']);

test('publications are found by their id with the id: prefix', function () {
    $wanted = createApiPublication(['title' => 'Wanted']);
    createApiPublication(['title' => 'Other', 'citation' => "Has id:{$wanted->id} in it"]);

    $this->getJson(apiRoutePath('api/publication').'?query='.urlencode('ID: '.$wanted->id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $wanted->id);
});

test('publications are sorted by the requested column and direction', function (string $query, array $expected) {
    createApiPublication(['title' => 'Middle', 'year' => 2010]);
    createApiPublication(['title' => 'Oldest', 'year' => 1999]);
    createApiPublication(['title' => 'Newest', 'year' => 2024]);

    expect($this->getJson(apiRoutePath('api/publication').$query)->assertOk()->json('data.*.title'))->toBe($expected);
})->with([
    'ascending' => ['?sort_by=year&sort_by_direction=asc', ['Oldest', 'Middle', 'Newest']],
    'descending' => ['?sort_by=year&sort_by_direction=desc', ['Newest', 'Middle', 'Oldest']],
    'unknown direction sorts descending' => ['?sort_by=year&sort_by_direction=random', ['Newest', 'Middle', 'Oldest']],
    'direction alone sorts by id' => ['?sort_by_direction=desc', ['Newest', 'Oldest', 'Middle']],
]);

test('a single membrane or method id narrows passive interactions like a list of them', function () {
    $datasets = createPassiveInteractionsOnDifferentDatasets()['datasets'];

    expect(passiveNotes('?membrane_ids='.$datasets['DOPC']->membrane_id))->toBe(['DOPC/PAMPA'])
        ->and(passiveNotes('?method_ids='.$datasets['SOPC']->method_id))->toBe(['SOPC/CACO']);
});

test('an id: search with something other than a number finds no publication', function (string $query) {
    createApiPublication(['title' => 'Any']);

    $this->getJson(apiRoutePath('api/publication').'?query='.urlencode($query))
        ->assertOk()
        ->assertJsonCount(0, 'data');
})->with(['id:abc', 'id:', 'id:1 or 1=1', 'id:-1']);

test('a sort key that is not a column of the listed records is ignored instead of reaching the SQL', function (string $endpoint, string $sortBy) {
    createApiPublication();
    createApiPassiveInteraction(['structure' => createApiStructure(['identifier' => 'MM02000'])]);
    createApiActiveInteraction(['structure' => createApiStructure(['identifier' => 'MM02001'])]);

    $this->getJson(apiRoutePath($endpoint).'?sort_by='.urlencode($sortBy).'&sort_by_direction=asc')
        ->assertOk()
        ->assertJsonCount($endpoint === 'api/publication' ? Publication::query()->count() : 1, 'data');
})->with([
    'passive' => 'api/interactions/passive/structure/MM02000',
    'active' => 'api/interactions/active/structure/MM02001',
    'publications' => 'api/publication',
])->with([
    'unknown column' => 'nonexistent',
    'json path injection' => "note->x' desc, (select 1/0)--",
    'joined table json path injection' => "met.parameters->x' desc, (select 1/0)--",
    'other table column' => 'users.password',
]);

test('passive interactions sorted by an ignored key fall back to their id', function () {
    createPassiveInteractionsOnDifferentDatasets();

    expect(passiveNotes('?sort_by=nonexistent&sort_by_direction=desc'))->toBe(['SOPC/CACO', 'DOPC/PAMPA', 'POPC/BLM']);
});

test('membrane and method ids that are not numbers match nothing instead of failing', function () {
    createPassiveInteractionsOnDifferentDatasets();

    expect(passiveNotes('?membrane_ids[]=abc'))->toBe([])
        ->and(passiveNotes('?method_ids[]=1%20or%201=1'))->toBe([])
        ->and(passiveNotes('?membrane_ids[0][]=1'))->toBe([]);
});

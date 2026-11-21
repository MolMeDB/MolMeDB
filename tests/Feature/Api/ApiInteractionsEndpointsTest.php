<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Dataset;

beforeEach(function () {
    prepareApiEndpointTestEnvironment();
});

afterEach(function () {
    resetApiRouteCdkDepictState();
    resetApiRouteRdkitState();
});

test('passive interactions by structure endpoint returns matching interactions', function () {
    $structure = createApiStructure(['identifier' => 'MM1001']);
    createApiPassiveInteraction([
        'structure' => $structure,
        'note' => 'Passive interaction for structure',
    ]);

    $this->getJson(apiRoutePath('api/interactions/passive/structure/MM1001'))
        ->assertOk()
        ->assertJsonFragment([
            'note' => 'Passive interaction for structure',
        ]);
});

test('active interactions by structure endpoint returns matching interactions', function () {
    $structure = createApiStructure(['identifier' => 'MM1002']);
    createApiActiveInteraction([
        'structure' => $structure,
        'note' => 'Active interaction for structure',
    ]);

    $this->getJson(apiRoutePath('api/interactions/active/structure/MM1002'))
        ->assertOk()
        ->assertJsonFragment([
            'note' => 'Active interaction for structure',
        ]);
});

test('interactions by structure carry the reference of their dataset as the secondary reference', function (string $type) {
    $structure = createApiStructure(['identifier' => 'MM1003']);
    $datasetPublication = createApiPublication(['citation' => 'Dataset reference']);
    $attributes = [
        'structure' => $structure,
        'dataset' => createApiDataset(['type' => $type === 'passive' ? Dataset::TYPE_PASSIVE : Dataset::TYPE_ACTIVE, 'publication' => $datasetPublication]),
    ];
    $type === 'passive' ? createApiPassiveInteraction($attributes) : createApiActiveInteraction($attributes);

    $this->getJson(apiRoutePath("api/interactions/{$type}/structure/MM1003"))
        ->assertOk()
        ->assertJsonPath('data.0.secondary_reference.id', $datasetPublication->id)
        ->assertJsonPath('data.0.secondary_reference.citation', 'Dataset reference');
})->with(['passive', 'active']);

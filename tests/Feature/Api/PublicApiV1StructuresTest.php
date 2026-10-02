<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Identifier;

test('structures index returns paginated structures', function () {
    createApiStructure(['identifier' => 'MM0001', 'canonical_smiles' => 'CCO']);
    createApiStructure(['identifier' => 'MM0002', 'canonical_smiles' => 'CCN']);

    $this->getJson('/api/v1/structures')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['identifier', 'name', 'canonical_smiles', 'molecular_weight', 'logp']],
            'links',
            'meta',
        ]);
});

test('structures index excludes structures without an identifier', function () {
    createApiStructure(['identifier' => 'MM0001']);
    createApiStructure(['identifier' => null]);

    $this->getJson('/api/v1/structures')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.identifier', 'MM0001');
});

test('structures index does not include detail-only fields', function () {
    createApiStructure(['identifier' => 'MM0001']);

    $response = $this->getJson('/api/v1/structures');

    $response->assertOk();
    expect($response->json('data.0'))->not->toHaveKeys(['inchi', 'inchikey', 'identifiers']);
});

test('structures index filters by free-text query over cross-referenced identifiers', function () {
    $structure = createApiStructure(['identifier' => 'MM0001']);
    createApiStructure(['identifier' => 'MM0002']);

    Identifier::create([
        'structure_id' => $structure->id,
        'value' => 'Caffeine',
        'type' => Identifier::TYPE_NAME,
        'state' => Identifier::STATE_VALIDATED,
    ]);

    $this->getJson('/api/v1/structures?query=caffeine')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.identifier', 'MM0001');
});

test('structures index finds an exact structure match regardless of SMILES notation', function () {
    createApiStructure(['identifier' => 'MM0001', 'canonical_smiles' => 'CCO']);
    createApiStructure(['identifier' => 'MM0002', 'canonical_smiles' => 'CCN']);

    $this->getJson('/api/v1/structures?smiles=OCC')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.identifier', 'MM0001');
});

test('structures index rejects an invalid SMILES', function () {
    $this->getJson('/api/v1/structures?smiles=this is not a smiles')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('smiles');
});

test('structures index finds substructure matches', function () {
    createApiStructure(['identifier' => 'MM0001', 'canonical_smiles' => 'CCO']);
    createApiStructure(['identifier' => 'MM0002', 'canonical_smiles' => 'CCN']);

    $this->getJson('/api/v1/structures?substructure=CO')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.identifier', 'MM0001');
});

test('structures index rejects per_page above 100', function () {
    // Unlike Membrane/Method/Protein/Publication (which silently clamp),
    // SearchStructureRequest validates per_page directly and rejects it.
    $this->getJson('/api/v1/structures?per_page=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

test('structures show returns full detail including identifiers', function () {
    $structure = createApiStructure(['identifier' => 'MM0001']);
    Identifier::create([
        'structure_id' => $structure->id,
        'value' => '2519',
        'type' => Identifier::TYPE_PUBCHEM,
        'state' => Identifier::STATE_ACTIVE,
    ]);

    $this->getJson('/api/v1/structures/MM0001')
        ->assertOk()
        ->assertJsonPath('data.identifier', 'MM0001')
        ->assertJsonPath('data.inchi', $structure->inchi)
        ->assertJsonPath('data.identifiers.0.value', '2519');
});

test('structures show returns 404 for an unknown identifier', function () {
    $this->getJson('/api/v1/structures/UNKNOWN')
        ->assertNotFound();
});

test('structures stats returns aggregate counts', function () {
    $structure = createApiStructure(['identifier' => 'MM0001']);
    createApiPassiveInteraction(['structure' => $structure]);
    createApiActiveInteraction(['structure' => $structure]);

    $this->getJson('/api/v1/structures/MM0001/stats')
        ->assertOk()
        ->assertJsonPath('data.structure.identifier', 'MM0001')
        ->assertJsonPath('data.total.interactions_passive', 1)
        ->assertJsonPath('data.total.interactions_active', 1);
});

test('structures interactions passive returns a live paginated listing', function () {
    $structure = createApiStructure(['identifier' => 'MM0001']);
    createApiPassiveInteraction(['structure' => $structure]);

    $this->getJson('/api/v1/structures/MM0001/interactions/passive')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data' => [['membrane', 'method', 'temperature']], 'meta']);
});

test('structures interactions active returns a live paginated listing', function () {
    $structure = createApiStructure(['identifier' => 'MM0001']);
    createApiActiveInteraction(['structure' => $structure]);

    $this->getJson('/api/v1/structures/MM0001/interactions/active')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data' => [['protein', 'temperature']], 'meta']);
});

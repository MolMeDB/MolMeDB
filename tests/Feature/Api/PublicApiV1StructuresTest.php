<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Identifier;
use Illuminate\Support\Facades\Http;

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

test('structure detail links its parent, forms, depiction and 3D structure', function () {
    config()->set('services.cdk_depict_url', 'https://cdk-depict.test');
    $parent = createApiStructure(['identifier' => 'MM00045', 'canonical_smiles' => 'CC(C)Cc1ccc(C(C)C(=O)O)cc1']);
    createApiStructure(['identifier' => 'MM00045.2', 'parent_id' => $parent->id, 'charge' => -1]);
    createApiStructure(['identifier' => 'MM00045.1', 'parent_id' => $parent->id]);
    createApiStructure(['identifier' => null, 'parent_id' => $parent->id]);

    $this->getJson('/api/v1/structures/MM00045')
        ->assertOk()
        ->assertJsonPath('data.parent', null)
        ->assertJsonPath('data.forms.*.identifier', ['MM00045.1', 'MM00045.2'])
        ->assertJsonPath('data.forms.0.url', 'https://molmedb.upol.cz/api/v1/structures/MM00045.1')
        ->assertJsonPath('data.depiction_url', 'https://cdk-depict.test/depict/cot/svg?smi=CC%28C%29Cc1ccc%28C%28C%29C%28%3DO%29O%29cc1&abbr=reagents&hdisp=bridgehead&showtitle=true&zoom=2.2&annotate=none')
        ->assertJsonPath('data.molfile_url', 'https://molmedb.upol.cz/api/v1/structures/MM00045/molfile');

    $this->getJson('/api/v1/structures/MM00045.2')
        ->assertOk()
        ->assertJsonPath('data.charge', -1)
        ->assertJsonPath('data.parent.identifier', 'MM00045')
        ->assertJsonPath('data.forms', []);
});

/**
 * A minimal valid V2000 molfile.
 */
function publicApiMolfile(string $name): string
{
    return <<<MOL
{$name}
  RDKit          3D

  2  1  0  0  0  0  0  0  0  0999 V2000
    0.0000    0.0000    0.0000 C   0  0  0  0  0  0  0  0  0  0  0  0
    1.2000    0.0000    0.0000 O   0  0  0  0  0  0  0  0  0  0  0  0
  1  2  1  0
M  END
MOL;
}

test('structure molfile returns the stored 3D structure', function () {
    createApiStructure(['identifier' => 'MM00040', 'molfile_3d' => publicApiMolfile('Stored')]);
    Http::fake();

    $response = $this->get('/api/v1/structures/MM00040/molfile', ['Accept' => '*/*']);

    $response->assertOk()
        ->assertHeader('Content-Type', 'chemical/x-mdl-molfile')
        ->assertHeader('Content-Disposition', 'inline; filename="MM00040.mol"');
    expect($response->getContent())->toBe(publicApiMolfile('Stored'));
    Http::assertNothingSent();
});

test('structure molfile is generated by RDKit once and stored', function () {
    config()->set('services.rdkit.url', 'https://rdkit.test');
    resetApiRouteRdkitState();
    Http::fake([
        'https://rdkit.test/test' => Http::response([], 200),
        'https://rdkit.test/structure/3d*' => Http::response(publicApiMolfile('Generated'), 200),
    ]);
    $structure = createApiStructure(['identifier' => 'MM00040', 'molfile_3d' => null]);

    $this->get('/api/v1/structures/MM00040/molfile', ['Accept' => '*/*'])->assertOk();

    expect($structure->refresh()->molfile_3d)->toBe(publicApiMolfile('Generated'));
    resetApiRouteRdkitState();
});

test('structure molfile answers 422 when RDKit cannot generate it', function () {
    config()->set('services.rdkit.url', 'https://rdkit.test');
    resetApiRouteRdkitState();
    Http::fake([
        'https://rdkit.test/test' => Http::response([], 200),
        'https://rdkit.test/structure/3d*' => Http::response('', 200),
    ]);
    createApiStructure(['identifier' => 'MM00040', 'molfile_3d' => null]);

    $this->getJson('/api/v1/structures/MM00040/molfile')->assertUnprocessable();
    $this->getJson('/api/v1/structures/MM99999/molfile')->assertNotFound();
    resetApiRouteRdkitState();
});

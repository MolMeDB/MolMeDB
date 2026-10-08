<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Identifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    resetApiRouteRdkitState();
});

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

test('structures index finds a molecule stored as aromatic by its Kekulé SMILES', function () {
    // RDKit, which canonicalizes the stored SMILES, perceives the rings of
    // caffeine as aromatic; Bingo alone does not match the Kekulé form to it.
    config()->set('services.rdkit.url', 'https://rdkit.test');
    Http::fake([
        'https://rdkit.test/test' => Http::response([]),
        'https://rdkit.test/structure/canonize*' => Http::response(['data' => 'Cn1c(=O)c2c(ncn2C)n(C)c1=O']),
    ]);
    createApiStructure(['identifier' => 'MM0001', 'canonical_smiles' => 'Cn1c(=O)c2c(ncn2C)n(C)c1=O']);
    createApiStructure(['identifier' => 'MM0002', 'canonical_smiles' => 'CCN']);

    $this->getJson('/api/v1/structures?smiles='.urlencode('CN1C=NC2=C1C(=O)N(C(=O)N2C)C'))
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

/**
 * A structure with one cross-reference.
 */
function structureWithIdentifier(string $identifier, int $type, string $value, int $state = Identifier::STATE_NEW): App\Models\Structure
{
    $structure = createApiStructure(['identifier' => $identifier]);
    Identifier::create(['structure_id' => $structure->id, 'type' => $type, 'value' => $value, 'state' => $state]);

    return $structure;
}

test('structures are found by their exact InChIKey', function () {
    createApiStructure(['identifier' => 'MM00040', 'inchikey' => 'RYYVLZVUVIJVGH-UHFFFAOYSA-N']);
    createApiStructure(['identifier' => 'MM00041', 'inchikey' => 'RYYVLZVUVIJVGH-UHFFFAOYSA-O']);

    $this->getJson('/api/v1/structures?inchikey=ryyvlzvuvijvgh-uhfffaoysa-n')
        ->assertOk()
        ->assertJsonPath('data.*.identifier', ['MM00040']);
});

test('structures are found by an external identifier of a public state', function (string $query, int $type, string $value, int $state, array $expected) {
    structureWithIdentifier('MM00040', $type, $value, $state);
    structureWithIdentifier('MM00041', $type, '999', Identifier::STATE_NEW);

    $this->getJson("/api/v1/structures?{$query}")
        ->assertOk()
        ->assertJsonPath('data.*.identifier', $expected);
})->with([
    'PubChem' => ['pubchem=2519', Identifier::TYPE_PUBCHEM, '2519', Identifier::STATE_NEW, ['MM00040']],
    'PubChem of the 2022 import (state 0)' => ['pubchem=2519', Identifier::TYPE_PUBCHEM, '2519', 0, ['MM00040']],
    'invalid PubChem' => ['pubchem=2519', Identifier::TYPE_PUBCHEM, '2519', Identifier::STATE_INVALID, []],
    'ChEMBL' => ['chembl=chembl113', Identifier::TYPE_CHEMBL, 'CHEMBL113', Identifier::STATE_NEW, ['MM00040']],
    'ChEBI stored with prefix' => ['chebi=27732', Identifier::TYPE_CHEBI, 'CHEBI:27732', Identifier::STATE_NEW, ['MM00040']],
    'ChEBI stored without prefix' => ['chebi=CHEBI:27732', Identifier::TYPE_CHEBI, '27732', Identifier::STATE_NEW, ['MM00040']],
    'DrugBank' => ['drugbank=DB00201', Identifier::TYPE_DRUGBANK, 'DB00201', Identifier::STATE_NEW, ['MM00040']],
    'PDB ligand' => ['pdb=CFF', Identifier::TYPE_PDB, 'CFF', Identifier::STATE_NEW, ['MM00040']],
]);

test('several structures are fetched at once by their identifiers', function () {
    createApiStructure(['identifier' => 'MM00040']);
    createApiStructure(['identifier' => 'MM00041']);
    createApiStructure(['identifier' => 'MM00042']);

    $this->getJson('/api/v1/structures?identifiers=MM00042, MM00040,MM99999')
        ->assertOk()
        ->assertJsonPath('data.*.identifier', ['MM00040', 'MM00042']);
});

test('invalid structure searches are rejected', function (string $query, string $field) {
    $this->getJson("/api/v1/structures?{$query}")->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'InChIKey' => ['inchikey=caffeine', 'inchikey'],
    'PubChem' => ['pubchem=CID2519', 'pubchem'],
    'DrugBank' => ['drugbank=2519', 'drugbank'],
    'identifier' => ['identifiers=MM00040,caffeine', 'identifiers'],
    'too many identifiers' => ['identifiers='.implode(',', array_map(fn (int $i): string => sprintf('MM%05d', $i), range(1, 101))), 'identifiers'],
]);

test('structure detail lists public cross-references and marks unverified ones', function () {
    $structure = structureWithIdentifier('MM00040', Identifier::TYPE_PUBCHEM, '2519', 0);
    Identifier::create(['structure_id' => $structure->id, 'type' => Identifier::TYPE_DRUGBANK, 'value' => 'DB00201', 'state' => Identifier::STATE_VALIDATED]);
    Identifier::create(['structure_id' => $structure->id, 'type' => Identifier::TYPE_CHEMBL, 'value' => 'CHEMBL1', 'state' => Identifier::STATE_INVALID]);

    $identifiers = $this->getJson('/api/v1/structures/MM00040')->assertOk()->json('data.identifiers');

    expect($identifiers)->toBe([
        ['type' => 'pubchem', 'value' => '2519', 'uri' => 'https://identifiers.org/pubchem.compound:2519', 'verified' => false],
        ['type' => 'drugbank', 'value' => 'DB00201', 'uri' => 'https://identifiers.org/drugbank:DB00201'],
    ]);
});

test('similar structures are listed with their similarity, most similar first', function () {
    createApiStructure(['identifier' => 'MM00040', 'canonical_smiles' => 'Cn1c(=O)c2c(ncn2C)n(C)c1=O']);
    createApiStructure(['identifier' => 'MM00048', 'canonical_smiles' => 'Cn1c(=O)c2[nH]cnc2n(C)c1=O']);
    createApiStructure(['identifier' => 'MM00998', 'canonical_smiles' => 'Cn1c(=O)c2c([nH]c(=O)n2C)n(C)c1=O']);
    createApiStructure(['identifier' => 'MM00010', 'canonical_smiles' => 'CCCCCCCCCCCCCCCC(=O)O']);
    createApiStructure(['identifier' => null, 'canonical_smiles' => 'Cn1c(=O)c2[nH]cnc2n(C)c1=O']);

    $response = $this->getJson('/api/v1/structures/MM00040/similar')->assertOk();

    expect($response->json('data.*.identifier'))->toEqualCanonicalizing(['MM00048', 'MM00998'])
        ->and($response->json('data.*.similarity'))->each->toBeGreaterThanOrEqual(0.8)
        ->and($response->json('data.0.similarity'))->toBeGreaterThanOrEqual($response->json('data.1.similarity'))
        ->and($response->json('meta'))->not->toHaveKey('total');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Similarity search needs PostgreSQL with Bingo.');

test('the similarity threshold is between 0.7 and 1', function (string $threshold, int $status) {
    createApiStructure(['identifier' => 'MM00040', 'canonical_smiles' => 'CCO']);

    $this->getJson("/api/v1/structures/MM00040/similar?threshold={$threshold}")->assertStatus($status);
})->with([
    'too low' => ['0.6', 422],
    'above one' => ['1.1', 422],
    'not a number' => ['high', 422],
]);

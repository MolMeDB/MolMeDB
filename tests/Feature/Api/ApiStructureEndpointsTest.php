<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Category;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    prepareApiEndpointTestEnvironment();
});

afterEach(function () {
    resetApiRouteCdkDepictState();
    resetApiRouteRdkitState();
});

test('structure show endpoint returns structure resource', function () {
    $structure = createApiStructure([
        'identifier' => 'MM3001',
    ]);

    $this->getJson(apiRoutePath('api/structure/MM3001'))
        ->assertOk()
        ->assertJsonPath('data.id', $structure->id)
        ->assertJsonPath('data.identifier', 'MM3001');
});

test('structure mol 3d endpoint returns stored mol content', function () {
    $molfile = validApiMolfile();
    $structure = createApiStructure([
        'identifier' => 'MM3002',
        'molfile_3d' => $molfile,
    ]);

    $response = $this->get(apiRoutePath("api/structure/mol/3d/{$structure->identifier}"));

    $response->assertOk();
    expect($response->getContent())->toBe($molfile)
        ->and($response->headers->get('content-type'))->toContain('chemical/x-mdl-molfile');
});

test('structure mol 3d endpoint regenerates invalid stored mol content', function () {
    $molfile = validApiMolfile('Regenerated');
    $structure = createApiStructure([
        'identifier' => 'MM3003',
        'molfile_3d' => 'BROKEN MOLFILE',
    ]);

    Http::fake([
        'https://rdkit.test/test' => Http::response([], 200),
        'https://rdkit.test/structure/3d*' => Http::response($molfile, 200),
    ]);

    $response = $this->get(apiRoutePath("api/structure/mol/3d/{$structure->identifier}"));

    $response->assertOk();
    expect($response->getContent())->toBe($molfile)
        ->and($structure->refresh()->molfile_3d)->toBe($molfile);
});

test('structure mol 3d endpoint does not return invalid generated mol content', function () {
    $structure = createApiStructure([
        'identifier' => 'MM3004',
        'molfile_3d' => 'BROKEN MOLFILE',
    ]);

    Http::fake([
        'https://rdkit.test/test' => Http::response([], 200),
        'https://rdkit.test/structure/3d*' => Http::response('', 200),
    ]);

    $this->getJson(apiRoutePath("api/structure/mol/3d/{$structure->identifier}"))
        ->assertUnprocessable()
        ->assertJsonPath('message', '3D structure could not be generated.');

    expect($structure->refresh()->molfile_3d)->toBeNull();
});

test('structure canonize smiles endpoint is public and returns a canonized smiles', function () {
    // This route carries no auth:sanctum middleware (routes/api.php) — it's
    // a public read-only utility endpoint, unlike most of this group.
    $this->getJson(apiRoutePath('api/structure/mol/canonize_smiles/CCO'))
        ->assertOk()
        ->assertJsonPath('request_smiles', 'CCO')
        ->assertJsonStructure(['request_smiles', 'canonized_smiles']);
});

function validApiMolfile(string $name = 'Valid'): string
{
    return <<<MOL
{$name}
  MolMeDB          3D

  2  1  0  0  0  0            999 V2000
    0.0000    0.0000    0.0000 C   0  0  0  0  0  0  0  0  0  0  0  0
    1.2000    0.0000    0.0000 O   0  0  0  0  0  0  0  0  0  0  0  0
  1  2  1  0
M  END
MOL;
}

test('structure canonize smiles endpoint returns canonized smiles for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->getJson(apiRoutePath('api/structure/mol/canonize_smiles/CCO'))
        ->assertOk()
        ->assertJson([
            'request_smiles' => 'CCO',
            'canonized_smiles' => 'OCC',
        ]);
});

test('structure form select membranes endpoint returns grouped membrane tree', function () {
    $membraneRoot = createApiRootCategory(Category::TYPE_MEMBRANE, 'Phospholipids');
    $membraneChild = createApiChildCategory($membraneRoot, 'Bilayers');
    $methodRoot = createApiRootCategory(Category::TYPE_METHOD, 'Transport methods');
    $methodChild = createApiChildCategory($methodRoot, 'PAMPA methods');

    $membrane = createApiMembrane(category: $membraneChild);
    $method = createApiMethod(category: $methodChild);
    $structure = createApiStructure(['identifier' => 'MM4001']);
    $dataset = createApiDataset([
        'membrane' => $membrane,
        'method' => $method,
        'type' => Dataset::TYPE_PASSIVE,
    ]);

    createApiPassiveInteraction([
        'structure' => $structure,
        'dataset' => $dataset,
    ]);

    $this->getJson(apiRoutePath('api/structure/MM4001/form/select/membranes'))
        ->assertOk()
        ->assertJsonFragment([
            'placeholder' => 'Phospholipids',
        ])
        ->assertJsonFragment([
            'label' => $membrane->abbreviation,
        ]);
});

test('structure form select methods endpoint returns grouped method tree', function () {
    $membraneRoot = createApiRootCategory(Category::TYPE_MEMBRANE, 'Phospholipids');
    $membraneChild = createApiChildCategory($membraneRoot, 'Bilayers');
    $methodRoot = createApiRootCategory(Category::TYPE_METHOD, 'Transport methods');
    $methodChild = createApiChildCategory($methodRoot, 'PAMPA methods');

    $membrane = createApiMembrane(category: $membraneChild);
    $method = createApiMethod(category: $methodChild);
    $structure = createApiStructure(['identifier' => 'MM4002']);
    $dataset = createApiDataset([
        'membrane' => $membrane,
        'method' => $method,
        'type' => Dataset::TYPE_PASSIVE,
    ]);

    createApiPassiveInteraction([
        'structure' => $structure,
        'dataset' => $dataset,
    ]);

    $this->getJson(apiRoutePath('api/structure/MM4002/form/select/methods'))
        ->assertOk()
        ->assertJsonFragment([
            'placeholder' => 'Transport methods',
        ])
        ->assertJsonFragment([
            'label' => $method->abbreviation,
        ]);
});

test('structure similarities endpoint returns related structures payload', function () {
    $parent = createApiStructure([
        'identifier' => 'MM5001',
    ]);
    createApiStructure([
        'identifier' => 'MM5002',
        'parent_id' => $parent->id,
    ]);

    $this->getJson(apiRoutePath('api/structure/MM5001/similarities'))
        ->assertOk()
        ->assertJsonStructure([
            'related_structures',
            'similar_structures',
        ]);
});

test('structure similarities endpoint lists the most similar structures apart from related ones', function () {
    $caffeine = createApiStructure(['identifier' => 'MM00040', 'canonical_smiles' => 'Cn1c(=O)c2c(ncn2C)n(C)c1=O']);
    createApiStructure(['identifier' => 'MM00040.1', 'parent_id' => $caffeine->id, 'canonical_smiles' => 'Cn1c(=O)c2c(ncn2C)n(C)c1=O']);
    $theophylline = createApiStructure(['identifier' => 'MM00048', 'canonical_smiles' => 'Cn1c(=O)c2[nH]cnc2n(C)c1=O']);
    createApiPassiveInteraction(['structure' => $theophylline]);
    createApiStructure(['identifier' => 'MM00010', 'canonical_smiles' => 'CCCCCCCCCCCCCCCC(=O)O']);
    createApiStructure(['identifier' => null, 'canonical_smiles' => 'Cn1c(=O)c2c(ncn2C)n(C)c1=O']);

    $this->getJson(apiRoutePath('api/structure/MM00040/similarities'))
        ->assertOk()
        ->assertJsonPath('related_structures.0.identifier', 'MM00040.1')
        ->assertJsonPath('related_structures.0.similarity', null)
        ->assertJsonPath('similar_structures.*.identifier', ['MM00048'])
        ->assertJsonPath('similar_structures.0.similarity.tanimoto', fn (float $tanimoto): bool => $tanimoto >= 0.8 && $tanimoto < 1)
        ->assertJsonPath('similar_structures.0.total.interactions_passive', 1)
        ->assertJsonPath('similar_structures.0.total.interactions_active', 0);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Similarity search needs PostgreSQL with Bingo.');

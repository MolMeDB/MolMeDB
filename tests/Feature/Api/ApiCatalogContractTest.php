<?php

require_once __DIR__.'/api_contract_seed.php';

use App\Console\Commands\Database\BackupDbIdsm;
use App\Models\Author;
use App\Models\Config;
use App\Models\DocumentArticle;
use App\Models\Filesystem;
use App\Models\Identifier;
use App\Models\Stats;
use Illuminate\Support\Facades\Storage;

/*
 * Pins the response of the internal catalog endpoints used by the MolMeDB
 * frontend (membranes, methods, proteins, publications, structures, search,
 * statistics, documentation) — see Tests\Support\ApiContract.
 */

/**
 * The shared contract world, with its publication authors filled completely
 * (the internal endpoints return last name, full name and affiliation too).
 *
 * @return array<string, mixed>
 */
function seedCatalogContractWorld(): array
{
    $world = seedApiContractWorld();

    foreach (Author::query()->orderBy('id')->get() as $author) {
        $author->update([
            'last_name' => $author->first_name === 'Doe J' ? 'Doe' : 'Roe',
            'full_name' => $author->first_name === 'Doe J' ? 'John Doe' : 'Richard Roe',
            'affiliation' => 'Palacký University Olomouc',
        ]);
    }

    return $world;
}

beforeEach(function () {
    prepareApiEndpointTestEnvironment();
});

afterEach(function () {
    resetApiRouteCdkDepictState();
    resetApiRouteRdkitState();
});

test('catalog endpoint keeps its contract', function (string $route, Closure $path) {
    $world = seedCatalogContractWorld();

    $this->getJson($path($world))->assertApiContract($route);
})->with([
    'membrane categories' => ['GET api/membrane/categories', fn () => '/api/membrane/categories'],
    'membrane' => ['GET api/membrane/{membrane}', fn ($w) => "/api/membrane/{$w['membrane']->id}"],
    'membrane stats' => ['GET api/membrane/{membrane}/stats', fn ($w) => "/api/membrane/{$w['membrane']->id}/stats"],
    'method categories' => ['GET api/method/categories', fn () => '/api/method/categories'],
    'method' => ['GET api/method/{method}', fn ($w) => "/api/method/{$w['method']->id}"],
    'method stats' => ['GET api/method/{method}/stats', fn ($w) => "/api/method/{$w['method']->id}/stats"],
    'protein categories' => ['GET api/protein/categories', fn () => '/api/protein/categories'],
    'protein' => ['GET api/protein/{protein}', fn ($w) => "/api/protein/{$w['protein']->id}"],
    'protein stats' => ['GET api/protein/{protein}/stats', fn ($w) => "/api/protein/{$w['protein']->id}/stats"],
    'protein interactions download' => ['GET api/protein/{protein}/download/interactions', fn ($w) => "/api/protein/{$w['protein']->id}/download/interactions"],
    'publications' => ['GET api/publication', fn () => '/api/publication'],
    'publication' => ['GET api/publication/{publication}', fn ($w) => "/api/publication/{$w['publication']->id}"],
    'publication stats' => ['GET api/publication/{publication}/stats', fn ($w) => "/api/publication/{$w['publication']->id}/stats"],
    'search datasets' => ['GET api/search/datasets', fn () => '/api/search/datasets'],
    'search membranes' => ['GET api/search/membranes', fn () => '/api/search/membranes'],
    'search methods' => ['GET api/search/methods', fn () => '/api/search/methods'],
    'search proteins' => ['GET api/search/proteins', fn () => '/api/search/proteins'],
    'search structures' => ['GET api/search/structures', fn () => '/api/search/structures'],
    'canonized smiles' => ['GET api/structure/mol/canonize_smiles/{smiles}', fn () => '/api/structure/mol/canonize_smiles/CCO'],
    'structure' => ['GET api/structure/{identifier}', fn () => '/api/structure/MM00040'],
    'structure membranes select' => ['GET api/structure/{identifier}/form/select/membranes', fn () => '/api/structure/MM00040/form/select/membranes'],
    'structure methods select' => ['GET api/structure/{identifier}/form/select/methods', fn () => '/api/structure/MM00040/form/select/methods'],
    'structure similarities' => ['GET api/structure/{identifier}/similarities', fn () => '/api/structure/MM00040/similarities'],
    'structure active interactions' => ['GET api/interactions/active/structure/{identifier}', fn () => '/api/interactions/active/structure/MM00040'],
    'structure passive interactions' => ['GET api/interactions/passive/structure/{identifier}', fn () => '/api/interactions/passive/structure/MM00040'],
]);

test('structure 3D molfile endpoint keeps its contract', function () {
    $world = seedCatalogContractWorld();
    $world['structure']->update(['molfile_3d' => <<<'MOL'
Caffeine
  MolMeDB          3D

  2  1  0  0  0  0            999 V2000
    0.0000    0.0000    0.0000 C   0  0  0  0  0  0  0  0  0  0  0  0
    1.2000    0.0000    0.0000 O   0  0  0  0  0  0  0  0  0  0  0  0
  1  2  1  0
M  END
MOL]);

    $this->get('/api/structure/mol/3d/MM00040')->assertApiContract('GET api/structure/mol/3d/{identifier}');
});

test('structure identifier status endpoint keeps its contract', function () {
    $world = seedCatalogContractWorld();

    // A merged identifier fills replaced_by, the most complete status answer.
    Identifier::create([
        'structure_id' => $world['structure']->id,
        'type' => Identifier::TYPE_MOLMEDB,
        'value' => 'MM00099',
        'state' => Identifier::STATE_OBSOLETE,
    ]);

    $this->getJson('/api/structure/MM00099/status')->assertApiContract('GET api/structure/{identifier}/status');
});

test('documentation endpoint keeps its contract', function (string $route, string $path) {
    $guide = DocumentArticle::create([
        'title' => 'User guide',
        'slug' => 'user-guide',
        'content' => '<h2>Getting started</h2><p>How to search MolMeDB.</p>',
        'position' => 1,
    ]);

    DocumentArticle::create([
        'parent_id' => $guide->id,
        'title' => 'Searching structures',
        'slug' => 'searching-structures',
        'content' => '<p>Search by name, identifier or SMILES.</p>',
        'position' => 1,
    ]);

    DocumentArticle::create([
        'parent_id' => $guide->id,
        'title' => 'Draft chapter',
        'slug' => 'draft-chapter',
        'content' => '<p>Not published yet.</p>',
        'position' => 2,
        'is_published' => false,
    ]);

    DocumentArticle::create([
        'title' => 'Data model',
        'slug' => 'data-model',
        'content' => '<p>Membranes, methods and interactions.</p>',
        'position' => 2,
    ]);

    $this->getJson($path)->assertApiContract($route);
})->with([
    'tree' => ['GET api/docs/tree', '/api/docs/tree'],
    'first article' => ['GET api/docs/article', '/api/docs/article'],
    'root article' => ['GET api/docs/article/{parentSlug}', '/api/docs/article/user-guide'],
    'child article' => ['GET api/docs/article/{parentSlug}/{childSlug}', '/api/docs/article/user-guide/searching-structures'],
]);

test('statistics endpoint keeps its contract', function (string $route, string $path) {
    seedCatalogContractWorld();

    foreach ([
        Stats::TYPE_COUNTS => [
            'total_passive_interactions' => 1,
            'total_active_interactions' => 1,
            'total_structures' => 2,
            'total_membranes' => 1,
            'total_methods' => 1,
            'total_proteins' => 1,
        ],
        Stats::TYPE_INTERACTION_SUBSTANCE_HISTORY => [
            ['date' => '2025-01', 'value1' => 1, 'value2' => 2],
            ['date' => '2025-02', 'value1' => 2, 'value2' => 2],
        ],
        Stats::TYPE_DATABASES_BAR_COUNTS => [
            ['name' => 'PubChem', 'value1' => 1, 'value2' => 2],
            ['name' => 'ChEBI', 'value1' => 1, 'value2' => 2],
        ],
        Stats::TYPE_PROTEIN_BAR_COUNTS => [
            ['name' => 'O15244', 'value1' => 1, 'value2' => 1],
        ],
        Stats::TYPE_PUBLICATIONS_BY_YEAR_STATS => [
            ['date' => 2004, 'value1' => 1, 'value2' => 1],
        ],
        Stats::TYPE_PUBLICATIONS_BY_JOURNAL_STATS => [
            ['name' => 'J Pharm Sci', 'value1' => 1, 'value2' => 1],
        ],
    ] as $type => $content) {
        Stats::query()->create(['type' => $type, 'content' => $content]);
    }

    $this->getJson($path)->assertApiContract($route);
})->with([
    'database' => ['GET api/stats', '/api/stats'],
    'publications' => ['GET api/stats/publications', '/api/stats/publications'],
]);

test('IDSM dump info endpoint keeps its contract', function () {
    $filesystem = Filesystem::where('type', Filesystem::TYPE_DB_PUBLIC_BACKUP)->firstOrFail();
    Storage::fake($filesystem->systemName);

    Config::set('db_backup_idsm_last', '2026-01-15 03:00:00');
    Storage::disk($filesystem->systemName)->put(BackupDbIdsm::datePath('2026-01-15').'backup-idsm-2026-01-15.sql.gz', 'dump');

    $this->getJson('/api/dump/idsm/info')->assertApiContract('GET api/dump/idsm/info');
});

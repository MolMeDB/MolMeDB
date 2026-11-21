<?php

require_once __DIR__.'/api_contract_seed.php';
require_once __DIR__.'/../Mcp/mcp_tool_calls.php';

use App\Mcp\Servers\MolMeDBServer;
use App\Mcp\Tools\FindSimilarStructuresTool;
use App\Models\Author;
use App\Models\DatasetGroup;
use App\Models\Identifier;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\Fluent\AssertableJson;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Nothing internal leaks through the public API or the MCP server: not
 * users and who created what, internal names and comments of datasets,
 * storage paths, validation logs, deleted records or structures pending
 * curation. Every endpoint and tool answers from a world that contains
 * all of these, and its whole response is searched for their keys and
 * values.
 */

/**
 * Keys that must not appear anywhere in a public response.
 */
const SENSITIVE_KEYS = [
    'user', 'user_id', 'users', 'created_by', 'email', 'password', 'token', 'guest_token',
    'comment', 'dataset', 'dataset_id', 'datasets', 'dataset_group', 'dataset_group_id',
    'storage', 'files', 'logs', 'source_id', 'source_type', 'deleted_at', 'molfile_3d',
];

/**
 * Paths of sensitive keys that are allowed: the pagination path is a URL and
 * the number of datasets of a publication is only a count.
 */
const ALLOWED_SENSITIVE_PATHS = ['$.meta.path', '$.data.total.datasets', '$.total.datasets'];

/**
 * The contract world plus internal data with values easy to search for.
 *
 * @return array{world: array<string, mixed>, values: array<string, string>}
 */
function seedSensitiveDataWorld(): array
{
    $world = seedApiContractWorld();

    $owner = User::factory()->create(['name' => 'Secret Owner', 'email' => 'secret.owner@example.org']);
    $group = DatasetGroup::factory()->create(['name' => 'Secret dataset group']);

    foreach (['passiveDataset', 'activeDataset'] as $dataset) {
        $world[$dataset]->update([
            'name' => 'Secret dataset name',
            'comment' => 'Secret dataset comment',
            'dataset_group_id' => $group->id,
            'created_by' => $owner->id,
        ]);
    }

    $world['structure']->update(['user_id' => $owner->id]);
    $world['publication']->authors()->attach(Author::create(['first_name' => 'Public A', 'email' => 'secret.author@example.org'])->id);
    Identifier::where('structure_id', $world['structure']->id)->update(['logs' => json_encode(['message' => 'Secret validation log'])]);
    Identifier::create(['structure_id' => $world['structure']->id, 'type' => Identifier::TYPE_CHEMBL, 'value' => 'CHEMBL999999', 'state' => Identifier::STATE_INVALID]);

    createApiPassiveInteraction(['structure' => $world['structure'], 'dataset' => $world['passiveDataset'], 'note' => 'Secret deleted interaction'])->delete();

    $pending = createApiStructure(['identifier' => null, 'canonical_smiles' => 'CN1C=NC2=C1C(=O)N(C(=O)N2C)CC']);
    createApiPassiveInteraction(['structure' => $pending, 'dataset' => $world['passiveDataset'], 'note' => 'Secret pending structure interaction']);

    return [
        'world' => $world,
        'values' => [
            'user name' => 'Secret Owner',
            'user email' => 'secret.owner@example.org',
            'author email' => 'secret.author@example.org',
            'dataset name' => 'Secret dataset name',
            'dataset comment' => 'Secret dataset comment',
            'dataset group' => 'Secret dataset group',
            'validation log' => 'Secret validation log',
            'invalid identifier' => 'CHEMBL999999',
            'deleted interaction' => 'Secret deleted interaction',
            'pending structure' => 'Secret pending structure interaction',
            'export file path' => 'exports/contract-',
        ],
    ];
}

/**
 * Paths of the sensitive keys found in a decoded response.
 *
 * @return array<int, string>
 */
function sensitiveKeysIn(mixed $data, string $path = '$'): array
{
    if (! is_array($data)) {
        return [];
    }

    $found = [];

    foreach ($data as $key => $value) {
        if (is_string($key) && in_array($key, SENSITIVE_KEYS, true) && ! in_array("{$path}.{$key}", ALLOWED_SENSITIVE_PATHS, true)) {
            $found[] = "{$path}.{$key}";
        }

        $found = [...$found, ...sensitiveKeysIn($value, is_int($key) ? "{$path}[]" : "{$path}.{$key}")];
    }

    return $found;
}

/**
 * Every public GET endpoint with its path parameters filled from the world.
 *
 * @param  array<string, mixed>  $world
 * @return array<int, string>
 */
function publicApiPaths(array $world): array
{
    $parameters = [
        'membrane' => $world['membrane']->id,
        'method' => $world['method']->id,
        'publication' => $world['publication']->id,
        'protein' => $world['protein']->id,
        'identifier' => 'MM00040',
    ];

    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true) && str_starts_with($route->uri(), 'api/v1/'))
        ->reject(fn (Route $route): bool => in_array($route->uri(), ['api/v1/docs', 'api/v1/openapi.json', 'api/v1/mcp'], true))
        ->map(function (Route $route) use ($parameters, $world): string {
            $interaction = str_contains($route->uri(), 'interactions/active') ? $world['active']->id : $world['passive']->id;

            return '/'.preg_replace_callback(
                '/\{(\w+)\}/',
                fn (array $match): string => (string) ($match[1] === 'interaction' ? $interaction : $parameters[$match[1]]),
                $route->uri(),
            );
        })
        ->values()
        ->all();
}

test('no public API endpoint returns internal data', function () {
    ['world' => $world, 'values' => $values] = seedSensitiveDataWorld();
    $this->withoutMiddleware(ThrottleRequests::class);
    $world['structure']->update(['molfile_3d' => mcpContractMolfile()]);

    $paths = publicApiPaths($world);
    $leaks = [];

    foreach ($paths as $path) {
        foreach (['application/json', 'application/ld+json'] as $accept) {
            // Bingo (similarity) is PostgreSQL only.
            if (str_ends_with($path, '/similar') && DB::getDriverName() !== 'pgsql') {
                continue;
            }

            $response = $this->get($path, ['Accept' => $accept]);
            $body = $response->baseResponse instanceof StreamedResponse
                ? $response->streamedContent()
                : (string) $response->getContent();

            $found = str_contains((string) $response->headers->get('Content-Type'), 'json')
                ? sensitiveKeysIn(json_decode($body, true))
                : [];

            foreach ($values as $name => $value) {
                if (str_contains($body, $value)) {
                    $found[] = "value of the {$name}";
                }
            }

            if ($found !== []) {
                $leaks[] = "{$path} ({$accept}): ".implode(', ', $found);
            }
        }
    }

    expect($paths)->not->toBeEmpty()
        ->and($leaks)->toBe([], 'Internal data in public responses');
});

test('no MCP tool returns internal data', function (string $tool, Closure $arguments) {
    if ($tool === FindSimilarStructuresTool::class && DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Similarity search needs PostgreSQL with Bingo.');
    }

    ['world' => $world, 'values' => $values] = seedSensitiveDataWorld();

    MolMeDBServer::tool($tool, $arguments($world))
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($values) {
            $content = json_decode(json_encode($json->toArray()), true);
            $body = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            expect(sensitiveKeysIn($content))->toBe([]);

            foreach ($values as $name => $value) {
                expect(str_contains($body, $value))->toBeFalse("The response contains the value of the {$name}.");
            }

            $json->etc();
        });
})->with(mcpToolCalls());

arch('public code does not read the prediction database')
    ->expect([
        'App\Http\Controllers\Api\Public',
        'App\Http\Resources\Api\Public',
        'App\Mcp',
        'App\Services\Interactions',
    ])
    ->not->toUse('Modules\PredictionWorkers');

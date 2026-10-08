<?php

require_once __DIR__.'/../Api/api_contract_seed.php';
require_once __DIR__.'/mcp_tool_calls.php';

use App\Mcp\Resources\MolMeDBOverviewResource;
use App\Mcp\Servers\MolMeDBServer;
use App\Mcp\Tools\FindSimilarStructuresTool;
use App\Mcp\Tools\GetInteractionTool;
use App\Mcp\Tools\GetMembraneTool;
use App\Mcp\Tools\GetStructureTool;
use App\Mcp\Tools\SearchInteractionsTool;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Support\ApiContract;

/*
 * Every tool of the MolMeDB MCP server answers from a seeded data world, and
 * the shape of its structured result is pinned like the API contracts
 * (tests/Fixtures/mcp-contracts, re-recorded with UPDATE_API_CONTRACTS=1):
 * AI agents rely on these field names as much as API clients do.
 */

const MCP_CONTRACTS = __DIR__.'/../../Fixtures/mcp-contracts';

test('MCP tool keeps its contract', function (string $tool, Closure $arguments, string $name) {
    $world = seedApiContractWorld();

    MolMeDBServer::tool($tool, $arguments($world))
        ->assertOk()
        ->assertHasNoErrors()
        ->assertStructuredContent(function (AssertableJson $json) use ($name) {
            // Nested API resources are only turned into arrays when the result is sent as JSON.
            ApiContract::assertRecorded(MCP_CONTRACTS.'/'.str_replace(' ', '-', $name).'.json', "MCP {$name}", [
                'structured_content' => ApiContract::shape(json_decode(json_encode($json->toArray()), true)),
            ]);

            $json->etc();
        });
})->with(collect(mcpToolCalls())->map(fn (array $call, string $name): array => [...$call, $name])->all());

test('every MCP tool registered on the server has a contract call', function () {
    $registered = (new ReflectionClass(MolMeDBServer::class))->getProperty('tools')->getDefaultValue();

    expect(collect(mcpToolCalls())->map(fn (array $call): string => $call[0])->unique()->sort()->values()->all())
        ->toBe(collect($registered)->sort()->values()->all());
});

test('the server is served under the public API and lists its tools by name', function () {
    $session = $this->postJson('/api/v1/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ])->assertOk()->headers->get('MCP-Session-Id');

    $tools = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['MCP-Session-Id' => $session])
        ->assertOk()
        ->json('result.tools.*.name');

    expect($tools)->toEqualCanonicalizing([
        'search-structures', 'get-structure', 'find-similar-structures', 'get-structure-molfile',
        'search-interactions', 'get-interaction',
        'search-membranes', 'get-membrane', 'search-methods', 'get-method',
        'search-proteins', 'get-protein',
        'search-publications', 'get-publication', 'list-categories',
    ]);
});

test('the MCP transport answers GET and DELETE with 405', function (string $method) {
    $this->call($method, '/api/v1/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
})->with(['GET', 'DELETE']);

test('unknown records are reported as tool errors', function (string $tool, array $arguments) {
    MolMeDBServer::tool($tool, $arguments)->assertHasErrors();
})->with([
    'structure' => [GetStructureTool::class, ['identifier' => 'MM99999999']],
    'membrane' => [GetMembraneTool::class, ['id' => 999999]],
]);

test('the overview resource describes the data', function () {
    seedApiContractWorld();

    MolMeDBServer::resource(MolMeDBOverviewResource::class)
        ->assertOk()
        ->assertSee('MolMeDB');
});

test('search-interactions applies the filters of the REST API', function () {
    $world = seedApiContractWorld();

    MolMeDBServer::tool(SearchInteractionsTool::class, ['type' => 'passive', 'structure' => 'MM00040', 'membrane' => $world['membrane']->id, 'logperm_min' => 1])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.0.id', $world['passive']->id)->etc());

    MolMeDBServer::tool(SearchInteractionsTool::class, ['type' => 'passive', 'structure' => 'MM00040', 'logperm_min' => 5])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('data', 0)->etc());
});

test('search-interactions rejects filters of the other interaction type and invalid values', function (array $arguments) {
    seedApiContractWorld();

    MolMeDBServer::tool(SearchInteractionsTool::class, $arguments)->assertHasErrors();
})->with([
    'membrane of active interactions' => [['type' => 'active', 'membrane' => 1]],
    'interaction type of passive interactions' => [['type' => 'passive', 'interaction_type' => 'Substrate']],
    'unknown value' => [['type' => 'passive', 'with_value' => 'km']],
    'non-numeric range' => [['type' => 'active', 'km_min' => 'high']],
    'missing type' => [['structure' => 'MM00040']],
]);

test('find-similar-structures rejects a threshold below 0.7', function () {
    seedApiContractWorld();

    MolMeDBServer::tool(FindSimilarStructuresTool::class, ['identifier' => 'MM00040', 'threshold' => 0.5])->assertHasErrors();
});

test('get-interaction does not find interactions of a deleted dataset', function () {
    $world = seedApiContractWorld();
    $world['passiveDataset']->delete();

    MolMeDBServer::tool(GetInteractionTool::class, ['type' => 'passive', 'id' => $world['passive']->id])->assertHasErrors();
});

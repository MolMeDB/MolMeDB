<?php

require_once __DIR__.'/../Api/api_contract_seed.php';

use App\Mcp\Resources\MolMeDBOverviewResource;
use App\Mcp\Servers\MolMeDBServer;
use App\Mcp\Tools\GetMembraneTool;
use App\Mcp\Tools\GetMethodTool;
use App\Mcp\Tools\GetProteinInteractionsTool;
use App\Mcp\Tools\GetProteinTool;
use App\Mcp\Tools\GetPublicationTool;
use App\Mcp\Tools\GetStructureInteractionsTool;
use App\Mcp\Tools\GetStructureTool;
use App\Mcp\Tools\ListCategoriesTool;
use App\Mcp\Tools\SearchMembranesTool;
use App\Mcp\Tools\SearchMethodsTool;
use App\Mcp\Tools\SearchProteinsTool;
use App\Mcp\Tools\SearchPublicationsTool;
use App\Mcp\Tools\SearchStructuresTool;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Support\ApiContract;

/*
 * Every tool of the MolMeDB MCP server answers from a seeded data world, and
 * the shape of its structured result is pinned like the API contracts
 * (tests/Fixtures/mcp-contracts, re-recorded with UPDATE_API_CONTRACTS=1):
 * AI agents rely on these field names as much as API clients do.
 */

const MCP_CONTRACTS = __DIR__.'/../../Fixtures/mcp-contracts';

/**
 * Tool calls by name: the tool and its arguments built from the seeded world.
 *
 * @return array<string, array{0: class-string, 1: Closure(array): array<string, mixed>}>
 */
function mcpToolCalls(): array
{
    return [
        'search structures' => [SearchStructuresTool::class, fn () => ['query' => 'Caffeine']],
        'get structure' => [GetStructureTool::class, fn () => ['identifier' => 'MM00040']],
        'structure passive interactions' => [GetStructureInteractionsTool::class, fn () => ['identifier' => 'MM00040', 'type' => 'passive']],
        'structure active interactions' => [GetStructureInteractionsTool::class, fn () => ['identifier' => 'MM00040', 'type' => 'active']],
        'search membranes' => [SearchMembranesTool::class, fn () => ['query' => 'EggPC']],
        'get membrane' => [GetMembraneTool::class, fn ($world) => ['id' => $world['membrane']->id]],
        'search methods' => [SearchMethodsTool::class, fn () => ['query' => 'PAMPA']],
        'get method' => [GetMethodTool::class, fn ($world) => ['id' => $world['method']->id]],
        'search proteins' => [SearchProteinsTool::class, fn () => ['query' => 'O15244']],
        'get protein' => [GetProteinTool::class, fn ($world) => ['id' => $world['protein']->id]],
        'protein interactions' => [GetProteinInteractionsTool::class, fn ($world) => ['id' => $world['protein']->id]],
        'search publications' => [SearchPublicationsTool::class, fn () => ['query' => 'caffeine']],
        'get publication' => [GetPublicationTool::class, fn ($world) => ['id' => $world['publication']->id]],
        'membrane categories' => [ListCategoriesTool::class, fn () => ['entity' => 'membrane']],
        'method categories' => [ListCategoriesTool::class, fn () => ['entity' => 'method']],
        'protein categories' => [ListCategoriesTool::class, fn () => ['entity' => 'protein']],
    ];
}

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

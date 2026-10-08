<?php

/*
 * Calls of every MCP tool, shared by the contracts (MolMeDBServerTest) and
 * the sensitive data checks (PublicApiSensitiveDataTest).
 */

use App\Mcp\Tools\FindSimilarStructuresTool;
use App\Mcp\Tools\GetInteractionTool;
use App\Mcp\Tools\GetMembraneTool;
use App\Mcp\Tools\GetMethodTool;
use App\Mcp\Tools\GetProteinTool;
use App\Mcp\Tools\GetPublicationTool;
use App\Mcp\Tools\GetStructureMolfileTool;
use App\Mcp\Tools\GetStructureTool;
use App\Mcp\Tools\ListCategoriesTool;
use App\Mcp\Tools\SearchInteractionsTool;
use App\Mcp\Tools\SearchMembranesTool;
use App\Mcp\Tools\SearchMethodsTool;
use App\Mcp\Tools\SearchProteinsTool;
use App\Mcp\Tools\SearchPublicationsTool;
use App\Mcp\Tools\SearchStructuresTool;

/**
 * A minimal valid V2000 molfile.
 */
function mcpContractMolfile(): string
{
    return <<<'MOL'
Caffeine
  RDKit          3D

  2  1  0  0  0  0  0  0  0  0999 V2000
    0.0000    0.0000    0.0000 C   0  0  0  0  0  0  0  0  0  0  0  0
    1.2000    0.0000    0.0000 O   0  0  0  0  0  0  0  0  0  0  0  0
  1  2  1  0
M  END
MOL;
}

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
        'search structures by external identifier' => [SearchStructuresTool::class, fn () => ['pubchem' => '2519']],
        'similar structures' => [FindSimilarStructuresTool::class, fn () => ['identifier' => 'MM00040']],
        'structure molfile' => [GetStructureMolfileTool::class, function ($world) {
            $world['structure']->update(['molfile_3d' => mcpContractMolfile()]);

            return ['identifier' => 'MM00040'];
        }],
        'structure passive interactions' => [SearchInteractionsTool::class, fn () => ['type' => 'passive', 'structure' => 'MM00040']],
        'structure active interactions' => [SearchInteractionsTool::class, fn () => ['type' => 'active', 'structure' => 'MM00040']],
        'passive interaction' => [GetInteractionTool::class, fn ($world) => ['type' => 'passive', 'id' => $world['passive']->id]],
        'active interaction' => [GetInteractionTool::class, fn ($world) => ['type' => 'active', 'id' => $world['active']->id]],
        'search membranes' => [SearchMembranesTool::class, fn () => ['query' => 'EggPC']],
        'get membrane' => [GetMembraneTool::class, fn ($world) => ['id' => $world['membrane']->id]],
        'search methods' => [SearchMethodsTool::class, fn () => ['query' => 'PAMPA']],
        'get method' => [GetMethodTool::class, fn ($world) => ['id' => $world['method']->id]],
        'search proteins' => [SearchProteinsTool::class, fn () => ['query' => 'O15244']],
        'get protein' => [GetProteinTool::class, fn ($world) => ['id' => $world['protein']->id]],
        'protein interactions' => [SearchInteractionsTool::class, fn ($world) => ['type' => 'active', 'protein' => $world['protein']->id, 'interaction_type' => 'Carrier-mediated']],
        'search publications' => [SearchPublicationsTool::class, fn () => ['query' => 'caffeine']],
        'get publication' => [GetPublicationTool::class, fn ($world) => ['id' => $world['publication']->id]],
        'membrane categories' => [ListCategoriesTool::class, fn () => ['entity' => 'membrane']],
        'method categories' => [ListCategoriesTool::class, fn () => ['entity' => 'method']],
        'protein categories' => [ListCategoriesTool::class, fn () => ['entity' => 'protein']],
    ];
}

<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\StructureResource;
use App\Models\Structure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/v1/structures/{identifier} + its stats endpoint
 * (StructureController::show/stats) combined into one call.
 */
#[Name('get-structure')]
#[Description('Get full detail (including identifiers and interaction counts) for one MolMeDB structure by its public identifier.')]
#[IsReadOnly]
#[IsIdempotent]
class GetStructureTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $structure = Structure::where('identifier', $validated['identifier'])->first();

        if (! $structure) {
            return Response::error('Structure not found.');
        }

        $structure->load(['identifiers', 'parent', 'children']);

        return Response::structured([
            'structure' => StructureResource::make($structure)->withDetails()->resolve(),
            'total' => [
                'interactions_passive' => $structure->interactionsPassive()->count(),
                'interactions_active' => $structure->interactionsActive()->count(),
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()
                ->description('The structure\'s public identifier (not the internal numeric id).')
                ->required(),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\MembraneResource;
use App\Models\Membrane;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/public/v1/membranes/{id} + its stats endpoint
 * (MembraneController::show/stats) combined into one call.
 */
#[Description('Get detail (including categories and interaction/structure counts) for one MolMeDB membrane by id.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMembraneTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $membrane = Membrane::find($validated['id']);

        if (! $membrane) {
            return Response::error('Membrane not found.');
        }

        $membrane->load('categories');

        return Response::structured([
            'membrane' => MembraneResource::make($membrane)->resolve(),
            'total' => [
                'interactions_passive' => $membrane->interactionsPassive()->count(),
                'structures' => $membrane->interactionsPassive()->distinct('structure_id')->count(),
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The membrane\'s numeric id.')
                ->required(),
        ];
    }
}

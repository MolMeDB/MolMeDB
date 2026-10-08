<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\ProteinResource;
use App\Models\Protein;
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
 * Mirrors GET /api/v1/proteins/{id} + its stats endpoint
 * (ProteinController::show/stats) combined into one call.
 */
#[Name('get-protein')]
#[Description('Get detail (including identifiers and interaction/structure counts) for one MolMeDB protein target by id.')]
#[IsReadOnly]
#[IsIdempotent]
class GetProteinTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $protein = Protein::find($validated['id']);

        if (! $protein) {
            return Response::error('Protein not found.');
        }

        $protein->load('identifiers');

        return Response::structured([
            'protein' => ProteinResource::make($protein)->resolve(),
            'total' => [
                'interactions_active' => $protein->interactionsActive()->count(),
                'structures' => $protein->structures()->count(),
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
                ->description('The protein\'s numeric id.')
                ->required(),
        ];
    }
}

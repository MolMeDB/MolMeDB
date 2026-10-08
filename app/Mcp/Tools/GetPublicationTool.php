<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\PublicationResource;
use App\Models\Publication;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/v1/publications/{id} + its stats endpoint
 * (PublicationController::show/stats) combined into one call.
 */
#[Description('Get full detail (journal/volume/authors, plus interaction/membrane/method/dataset counts) for one MolMeDB publication by id.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPublicationTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $publication = Publication::find($validated['id']);

        if (! $publication) {
            return Response::error('Publication not found.');
        }

        $publication->load('authors');
        $publication->loadCount([
            'interactionsPassive',
            'interactionsActive',
            'membranes',
            'methods',
            'datasets',
        ]);

        return Response::structured([
            'publication' => PublicationResource::make($publication)->withDetails()->resolve(),
            'total' => [
                'interactions_passive' => $publication->interactions_passive_count,
                'interactions_active' => $publication->interactions_active_count,
                'membranes' => $publication->membranes_count,
                'methods' => $publication->methods_count,
                'datasets' => $publication->datasets_count,
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
                ->description('The publication\'s numeric id.')
                ->required(),
        ];
    }
}

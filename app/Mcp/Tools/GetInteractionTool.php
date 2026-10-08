<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\InteractionActiveResource;
use App\Http\Resources\Api\Public\V1\InteractionPassiveResource;
use App\Services\Interactions\PublicInteractionQuery;
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
 * Mirrors GET /api/v1/interactions/{passive|active}/{id} (InteractionController).
 */
#[Name('get-interaction')]
#[Description('Get one passive or active MolMeDB interaction record by its id (the "id" of a search-interactions result).')]
#[IsReadOnly]
#[IsIdempotent]
class GetInteractionTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'type' => ['required', 'in:passive,active'],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $interaction = $validated['type'] === 'passive'
            ? PublicInteractionQuery::passive()->where('interactions_passive.id', $validated['id'])->first()
            : PublicInteractionQuery::active()->where('interactions_active.id', $validated['id'])->first();

        if (! $interaction) {
            return Response::error('Interaction not found.');
        }

        $resource = $validated['type'] === 'passive'
            ? InteractionPassiveResource::make($interaction)
            : InteractionActiveResource::make($interaction);

        return Response::structured(['interaction' => $resource->resolve()]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->enum(['passive', 'active'])
                ->description('Passive (structure-membrane) or active (structure-transporter) interaction.')
                ->required(),
            'id' => $schema->integer()
                ->description('The interaction id.')
                ->required(),
        ];
    }
}

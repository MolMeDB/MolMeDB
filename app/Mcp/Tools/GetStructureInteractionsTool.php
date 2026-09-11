<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\InteractionActiveResource;
use App\Http\Resources\Api\Public\V1\InteractionPassiveResource;
use App\Mcp\Support\PaginatesFilteredResults;
use App\Models\Structure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/public/v1/structures/{identifier}/interactions/{passive|active}
 * (StructureController::interactionsPassive/interactionsActive), unified
 * behind a `type` argument since both are already live-paginated JSON.
 */
#[Description('List the passive or active membrane-interaction records recorded for one MolMeDB structure.')]
#[IsReadOnly]
#[IsIdempotent]
class GetStructureInteractionsTool extends Tool
{
    use PaginatesFilteredResults;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:passive,active'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $structure = Structure::where('identifier', $validated['identifier'])->first();

        if (! $structure) {
            return Response::error('Structure not found.');
        }

        $perPage = $this->clampPerPage($validated['per_page'] ?? null);
        $page = $this->clampPage($validated['page'] ?? null);

        $paginator = $validated['type'] === 'passive'
            ? $structure->interactionsPassive()
                ->with(['dataset.membrane', 'dataset.method', 'dataset.publications', 'publication'])
                ->paginate($perPage, ['*'], 'page', $page)
            : $structure->interactionsActive()
                ->with(['protein', 'dataset.publications', 'publication'])
                ->paginate($perPage, ['*'], 'page', $page);

        $resourceClass = $validated['type'] === 'passive'
            ? InteractionPassiveResource::class
            : InteractionActiveResource::class;

        return Response::structured($this->paginatorToResult($paginator, $resourceClass));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()
                ->description('The structure\'s public identifier.')
                ->required(),
            'type' => $schema->string()
                ->enum(['passive', 'active'])
                ->description('Which interaction type to list.')
                ->required(),
            'per_page' => $schema->integer()
                ->description('Results per page (1-100, default 20).'),
            'page' => $schema->integer()
                ->description('Page number (default 1).'),
        ];
    }
}

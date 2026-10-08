<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\InteractionActiveResource;
use App\Mcp\Support\PaginatesFilteredResults;
use App\Models\Protein;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/v1/proteins/{id}/interactions
 * (ProteinController::interactions) — already live-paginated JSON.
 */
#[Description('List the active membrane-interaction records recorded for one MolMeDB protein target.')]
#[IsReadOnly]
#[IsIdempotent]
class GetProteinInteractionsTool extends Tool
{
    use PaginatesFilteredResults;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $protein = Protein::find($validated['id']);

        if (! $protein) {
            return Response::error('Protein not found.');
        }

        $paginator = $protein->interactionsActive()
            ->with(['protein', 'dataset.publications', 'publication'])
            ->paginate(
                $this->clampPerPage($validated['per_page'] ?? null),
                ['*'],
                'page',
                $this->clampPage($validated['page'] ?? null),
            );

        return Response::structured($this->paginatorToResult($paginator, InteractionActiveResource::class));
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
            'per_page' => $schema->integer()
                ->description('Results per page (1-100, default 20).'),
            'page' => $schema->integer()
                ->description('Page number (default 1).'),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\MembraneResource;
use App\Mcp\Support\PaginatesFilteredResults;
use App\Models\Membrane;
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
 * Mirrors GET /api/v1/membranes (MembraneController::index).
 */
#[Name('search-membranes')]
#[Description('Search/list MolMeDB membranes by name/abbreviation text and/or category.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchMembranesTool extends Tool
{
    use PaginatesFilteredResults;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $filters = array_filter([
            'query' => $validated['query'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
        ], fn (mixed $value): bool => filled($value));

        $result = $this->paginatedResult(
            Membrane::filter($filters),
            MembraneResource::class,
            $this->clampPerPage($validated['per_page'] ?? null),
            $this->clampPage($validated['page'] ?? null),
        );

        return Response::structured($result);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Free-text search across membrane name/abbreviation.'),
            'category_id' => $schema->integer()
                ->description('Restrict to membranes in this category (see list-categories).'),
            'per_page' => $schema->integer()
                ->description('Results per page (1-100, default 20).'),
            'page' => $schema->integer()
                ->description('Page number (default 1).'),
        ];
    }
}

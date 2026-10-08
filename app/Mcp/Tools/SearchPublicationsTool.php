<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\PublicationResource;
use App\Mcp\Support\PaginatesFilteredResults;
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
 * Mirrors GET /api/v1/publications (PublicationController::index).
 */
#[Description('Search/list MolMeDB publications by citation/title/DOI text, or by exact id using "id:<n>".')]
#[IsReadOnly]
#[IsIdempotent]
class SearchPublicationsTool extends Tool
{
    use PaginatesFilteredResults;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $filters = array_filter([
            'query' => $validated['query'] ?? null,
        ], fn (mixed $value): bool => filled($value));

        $result = $this->paginatedResult(
            Publication::filter($filters),
            PublicationResource::class,
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
                ->description('Free-text search across citation/title/DOI. Use "id:<n>" for an exact id lookup.'),
            'per_page' => $schema->integer()
                ->description('Results per page (1-100, default 20).'),
            'page' => $schema->integer()
                ->description('Page number (default 1).'),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Api\Public\V1\SimilarStructuresRequest;
use App\Http\Resources\Api\Public\V1\SimilarStructureResource;
use App\Mcp\Support\PaginatesFilteredResults;
use App\Mcp\Support\ThrottlesExpensiveSearches;
use App\Models\Structure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/v1/structures/{identifier}/similar
 * (StructureController::similar), with the same validation and limits.
 */
#[Name('find-similar-structures')]
#[Description('Find MolMeDB structures similar to a given one by the Tanimoto coefficient of their fingerprints (threshold 0.7-1, default 0.8), most similar first. Useful to find measured analogues of a molecule without its own data. Rate-limited.')]
#[IsReadOnly]
#[IsIdempotent]
class FindSimilarStructuresTool extends Tool
{
    use PaginatesFilteredResults;
    use ThrottlesExpensiveSearches;

    public function handle(Request $request, HttpRequest $httpRequest): Response|ResponseFactory
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:30'],
            ...(new SimilarStructuresRequest)->rules(),
        ]);

        $structure = Structure::where('identifier', $validated['identifier'])->first();

        if (! $structure) {
            return Response::error('Structure not found.');
        }

        // The same limits as RateLimiter::for('public-api-similarity') of the REST API.
        if (! $this->passesThrottle('similarity', $httpRequest->ip(), perMinute: 10, perHour: 60)) {
            return Response::error('Similarity search rate limit exceeded. Try again later.');
        }

        $paginator = Structure::similarTo(
            $structure->canonical_smiles,
            (float) ($validated['threshold'] ?? SimilarStructuresRequest::DEFAULT_THRESHOLD),
        )
            ->whereNotNull('structures.identifier')
            ->whereKeyNot($structure->id)
            ->simplePaginate(
                $this->clampPerPage($validated['per_page'] ?? null, max: SimilarStructuresRequest::MAX_PER_PAGE),
                ['*'],
                'page',
                $this->clampPage($validated['page'] ?? null),
            );

        return Response::structured($this->paginatorToResult($paginator, SimilarStructureResource::class));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'identifier' => $schema->string()
                ->description('The structure\'s public identifier, e.g. "MM00040".')
                ->required(),
            'threshold' => $schema->number()
                ->description('Minimum Tanimoto similarity, 0.7-1 (default 0.8).'),
            'per_page' => $schema->integer()
                ->description('Results per page (1-50, default 20).'),
            'page' => $schema->integer()
                ->description('Page number (default 1).'),
        ];
    }
}

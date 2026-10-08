<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\StructureResource;
use App\Mcp\Support\PaginatesFilteredResults;
use App\Models\Structure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/v1/structures (StructureController::index) —
 * same Structure::filter() + StructureFilter, same "no identifier yet"
 * exclusion, same substructure-search cost tradeoff (simple pagination,
 * extra rate limit).
 */
#[Description('Search MolMeDB molecular structures by free text (identifier/name), exact SMILES match, or substructure search (Bingo/Postgres). Excludes structures still pending curation (no public identifier yet).')]
#[IsReadOnly]
#[IsIdempotent]
class SearchStructuresTool extends Tool
{
    use PaginatesFilteredResults;

    public function handle(Request $request, HttpRequest $httpRequest): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:4000'],
            'smiles' => ['nullable', 'string', 'max:4000'],
            'substructure' => ['nullable', 'string', 'max:4000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach (['smiles', 'substructure'] as $field) {
            if ($this->hasInvalidSmiles($validated[$field] ?? null)) {
                return Response::error("The {$field} SMILES is invalid.");
            }
        }

        $hasSubstructure = filled($validated['substructure'] ?? null);

        if ($hasSubstructure && ! $this->passesSubstructureThrottle($httpRequest->ip())) {
            return Response::error('Substructure search rate limit exceeded. Try again later.');
        }

        $filters = array_filter([
            'query' => $validated['query'] ?? null,
            'smiles' => $validated['smiles'] ?? null,
            'substructure' => $validated['substructure'] ?? null,
        ], fn (mixed $value): bool => filled($value));

        $query = Structure::filter($filters)->whereNotNull('identifier');

        $result = $this->paginatedResult(
            $query,
            StructureResource::class,
            $this->clampPerPage($validated['per_page'] ?? null),
            $this->clampPage($validated['page'] ?? null),
            simple: $hasSubstructure,
        );

        return Response::structured($result);
    }

    private function hasInvalidSmiles(?string $smiles): bool
    {
        $smiles = trim((string) $smiles);

        if ($smiles === '' || DB::getDriverName() !== 'pgsql') {
            return false;
        }

        return filled(DB::scalar('SELECT bingo.checkMolecule(?)', [$smiles]));
    }

    /**
     * Mirrors RateLimiter::for('public-api-substructure', ...) in
     * AppServiceProvider (6/min + 30/hour per IP). That limiter is applied
     * as route middleware only to GET /structures; the MCP server has one
     * shared HTTP route for all 13 tools, so it can't be attached the same
     * way and is enforced here instead.
     */
    private function passesSubstructureThrottle(?string $ip): bool
    {
        $key = 'mcp-public-api-substructure:'.($ip ?? 'unknown');

        if (RateLimiter::tooManyAttempts("{$key}:minute", 6) || RateLimiter::tooManyAttempts("{$key}:hour", 30)) {
            return false;
        }

        RateLimiter::hit("{$key}:minute", 60);
        RateLimiter::hit("{$key}:hour", 3600);

        return true;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Free-text search across structure identifiers and names.'),
            'smiles' => $schema->string()
                ->description('Exact SMILES match.'),
            'substructure' => $schema->string()
                ->description('Substructure search (SMILES). More strictly rate-limited than other searches.'),
            'per_page' => $schema->integer()
                ->description('Results per page (1-100, default 20).'),
            'page' => $schema->integer()
                ->description('Page number (default 1).'),
        ];
    }
}

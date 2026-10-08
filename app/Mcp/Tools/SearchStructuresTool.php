<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Api\Public\V1\SearchStructureRequest;
use App\Http\Resources\Api\Public\V1\StructureResource;
use App\Mcp\Support\PaginatesFilteredResults;
use App\Mcp\Support\ThrottlesExpensiveSearches;
use App\Models\Structure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/v1/structures (StructureController::index) —
 * same validation (SearchStructureRequest), Structure::filter() +
 * StructureFilter, same "no identifier yet" exclusion, same
 * substructure-search cost tradeoff (simple pagination, extra rate limit).
 */
#[Name('search-structures')]
#[Description('Search MolMeDB molecular structures by free text (identifier/name), exact SMILES, substructure (rate-limited), exact InChIKey or an external identifier (PubChem, ChEMBL, ChEBI, DrugBank, PDB ligand), or fetch up to 100 structures by their MolMeDB identifiers. Excludes structures still pending curation (no public identifier yet). Use the identifier of a result in search-interactions.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchStructuresTool extends Tool
{
    use PaginatesFilteredResults;
    use ThrottlesExpensiveSearches;

    public function handle(Request $request, HttpRequest $httpRequest): Response|ResponseFactory
    {
        $validated = $request->validate((new SearchStructureRequest)->rules() + [
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach (['smiles', 'substructure'] as $field) {
            if ($this->hasInvalidSmiles($validated[$field] ?? null)) {
                return Response::error("The {$field} SMILES is invalid.");
            }
        }

        if (filled($validated['identifiers'] ?? null)) {
            $error = SearchStructureRequest::identifierListError($validated['identifiers']);

            if ($error !== null) {
                return Response::error($error);
            }
        }

        $hasSubstructure = filled($validated['substructure'] ?? null);

        // The same limits as RateLimiter::for('public-api-substructure') of the REST API.
        if ($hasSubstructure && ! $this->passesThrottle('substructure', $httpRequest->ip(), perMinute: 6, perHour: 30)) {
            return Response::error('Substructure search rate limit exceeded. Try again later.');
        }

        $filters = array_filter(
            Arr::except($validated, ['per_page', 'page']),
            fn (mixed $value): bool => filled($value),
        );

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
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Free-text search across structure identifiers and names.'),
            'smiles' => $schema->string()
                ->description('Exact SMILES match (the same molecule in any SMILES notation).'),
            'substructure' => $schema->string()
                ->description('Substructure search (SMILES). More strictly rate-limited than other searches.'),
            'inchikey' => $schema->string()
                ->description('Exact InChIKey, e.g. "RYYVLZVUVIJVGH-UHFFFAOYSA-N".'),
            'pubchem' => $schema->string()
                ->description('PubChem CID, e.g. "2519".'),
            'chembl' => $schema->string()
                ->description('ChEMBL id, e.g. "CHEMBL113".'),
            'chebi' => $schema->string()
                ->description('ChEBI id, with or without the prefix, e.g. "CHEBI:27732".'),
            'drugbank' => $schema->string()
                ->description('DrugBank id, e.g. "DB00201".'),
            'pdb' => $schema->string()
                ->description('PDB ligand (chemical component) code, e.g. "CFF".'),
            'identifiers' => $schema->string()
                ->description('Comma-separated MolMeDB identifiers (at most 100), e.g. "MM00040,MM00045".'),
            'per_page' => $schema->integer()
                ->description('Results per page (1-100, default 20).'),
            'page' => $schema->integer()
                ->description('Page number (default 1).'),
        ];
    }
}

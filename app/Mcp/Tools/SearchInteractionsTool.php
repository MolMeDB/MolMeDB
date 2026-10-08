<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Api\Public\V1\SearchActiveInteractionsRequest;
use App\Http\Requests\Api\Public\V1\SearchInteractionsRequest;
use App\Http\Requests\Api\Public\V1\SearchPassiveInteractionsRequest;
use App\Http\Resources\Api\Public\V1\InteractionActiveResource;
use App\Http\Resources\Api\Public\V1\InteractionPassiveResource;
use App\Mcp\Support\PaginatesFilteredResults;
use App\ModelFilters\PublicInteractionActiveFilter;
use App\ModelFilters\PublicInteractionPassiveFilter;
use App\Services\Interactions\PublicInteractionQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/v1/interactions/passive and /active
 * (InteractionController) and the interaction listings of structures,
 * membranes, methods, proteins and publications: the same validation,
 * filters and visibility (PublicInteractionQuery).
 */
#[Name('search-interactions')]
#[Description('Search MolMeDB interaction records: passive (a structure on a membrane, measured or calculated by a method: LogPerm, LogK, Gpen, Gwat, Xmin) or active (a structure with a transporter protein: pKm, pEC50, pKi, pIC50). Filter by structure identifier, membrane, method, protein, publication, conditions and value ranges; combine filters to narrow the result. Units are described in the molmedb-overview resource.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchInteractionsTool extends Tool
{
    use PaginatesFilteredResults;

    public function handle(Request $request): Response|ResponseFactory
    {
        $type = $request->validate(['type' => ['required', 'in:passive,active']])['type'];

        // "type" chooses passive or active records here, so the type of an
        // active interaction (its category, "type" in the REST API) is
        // "interaction_type".
        $arguments = collect($request->all())->except('type');

        if ($arguments->has('interaction_type')) {
            $arguments = $arguments->except('interaction_type')->put('type', $arguments->get('interaction_type'));
        }

        $rules = $this->form($type)->rules();
        $foreign = $arguments->keys()->reject(fn (string $key): bool => array_key_exists($key, $rules));

        if ($foreign->isNotEmpty()) {
            $names = $foreign->map(fn (string $key): string => $key === 'type' ? 'interaction_type' : $key);

            return Response::error("Filters not available for {$type} interactions: {$names->implode(', ')}.");
        }

        $validated = validator($arguments->all(), $rules)->validate();
        $filters = Arr::except($validated, ['per_page', 'page']);

        $query = $type === 'passive'
            ? PublicInteractionQuery::passive($filters)
            : PublicInteractionQuery::active($filters);

        $paginator = PublicInteractionQuery::paginate(
            $query,
            $this->clampPerPage($validated['per_page'] ?? null),
            $this->clampPage($validated['page'] ?? null),
        );

        return Response::structured($this->paginatorToResult(
            $paginator,
            $type === 'passive' ? InteractionPassiveResource::class : InteractionActiveResource::class,
        ));
    }

    private function form(string $type): SearchInteractionsRequest
    {
        return $type === 'passive' ? new SearchPassiveInteractionsRequest : new SearchActiveInteractionsRequest;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        $ranges = [];

        foreach ([
            'temperature' => 'temperature [°C]',
            'ph' => 'pH',
            'logperm' => 'LogPerm [log10 cm/s] (passive)',
            'logk' => 'LogK [log10 mol_m/mol_w] (passive)',
            'gpen' => 'Gpen [kcal/mol] (passive)',
            'gwat' => 'Gwat [kcal/mol] (passive)',
            'x_min' => 'Xmin [nm] (passive)',
            'km' => 'pKm [-log10 M] (active)',
            'ec50' => 'pEC50 [-log10 M] (active)',
            'ki' => 'pKi [-log10 M] (active)',
            'ic50' => 'pIC50 [-log10 M] (active)',
        ] as $column => $description) {
            $ranges["{$column}_min"] = $schema->number()->description("Minimum {$description}.");
            $ranges["{$column}_max"] = $schema->number()->description("Maximum {$description}.");
        }

        return [
            'type' => $schema->string()
                ->enum(['passive', 'active'])
                ->description('Passive (structure-membrane) or active (structure-transporter) interactions.')
                ->required(),
            'structure' => $schema->string()
                ->description('Structure identifier, e.g. "MM00040" (find it with search-structures).'),
            'publication' => $schema->integer()
                ->description('Publication id, as the primary reference or the reference of the dataset.'),
            'membrane' => $schema->integer()
                ->description('Membrane id (passive only; find it with search-membranes).'),
            'method' => $schema->integer()
                ->description('Method id (passive only; find it with search-methods).'),
            'protein' => $schema->integer()
                ->description('Protein id (active only; find it with search-proteins).'),
            'uniprot' => $schema->string()
                ->description('UniProt id of the protein, e.g. "O15245" (active only).'),
            'interaction_type' => $schema->string()
                ->description('Type of an active interaction, e.g. "Substrate", "Non-substrate", "Inhibitor", "Non-inhibitor" (active only, case-insensitive).'),
            'charge' => $schema->string()
                ->description('Charge of the measured form, e.g. "0", "1", "-1" ("1" also matches "+1").'),
            'with_value' => $schema->string()
                ->enum([...PublicInteractionPassiveFilter::valueColumns(), ...PublicInteractionActiveFilter::valueColumns()])
                ->description('Only records with this value measured (logperm, logk, gpen, gwat, x_min for passive; km, ec50, ki, ic50 for active).'),
            ...$ranges,
            'per_page' => $schema->integer()
                ->description('Results per page (1-100, default 20).'),
            'page' => $schema->integer()
                ->description('Page number (default 1).'),
        ];
    }
}

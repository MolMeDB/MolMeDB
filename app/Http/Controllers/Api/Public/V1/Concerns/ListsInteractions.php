<?php

namespace App\Http\Controllers\Api\Public\V1\Concerns;

use App\Http\Requests\Api\Public\V1\SearchActiveInteractionsRequest;
use App\Http\Requests\Api\Public\V1\SearchPassiveInteractionsRequest;
use App\Http\Resources\Api\Public\V1\InteractionActiveResource;
use App\Http\Resources\Api\Public\V1\InteractionPassiveResource;
use App\Services\Interactions\PublicInteractionQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Paginated interaction listings with the public filters, either across
 * the whole database or fixed to one entity (e.g. ['membrane' => 13]).
 */
trait ListsInteractions
{
    /**
     * @param  array<string, mixed>  $fixedFilters
     */
    protected function passiveInteractions(SearchPassiveInteractionsRequest $request, array $fixedFilters = []): AnonymousResourceCollection
    {
        $query = PublicInteractionQuery::passive([...$request->filters(), ...$fixedFilters]);

        return InteractionPassiveResource::collection(
            PublicInteractionQuery::paginate($query, $request->perPage())->withQueryString()
        );
    }

    /**
     * @param  array<string, mixed>  $fixedFilters
     */
    protected function activeInteractions(SearchActiveInteractionsRequest $request, array $fixedFilters = []): AnonymousResourceCollection
    {
        $query = PublicInteractionQuery::active([...$request->filters(), ...$fixedFilters]);

        return InteractionActiveResource::collection(
            PublicInteractionQuery::paginate($query, $request->perPage())->withQueryString()
        );
    }
}

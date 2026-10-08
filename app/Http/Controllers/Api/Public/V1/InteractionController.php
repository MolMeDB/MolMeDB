<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Http\Controllers\Api\Public\V1\Concerns\ListsInteractions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\V1\SearchActiveInteractionsRequest;
use App\Http\Requests\Api\Public\V1\SearchPassiveInteractionsRequest;
use App\Http\Resources\Api\Public\V1\InteractionActiveResource;
use App\Http\Resources\Api\Public\V1\InteractionPassiveResource;
use App\Services\Interactions\PublicInteractionQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only, unauthenticated public API: interaction records across the
 * whole database, filtered by structure, membrane, method, protein,
 * publication, conditions and measured values.
 */
class InteractionController extends Controller
{
    use ListsInteractions;

    public function indexPassive(SearchPassiveInteractionsRequest $request): AnonymousResourceCollection
    {
        return $this->passiveInteractions($request);
    }

    public function showPassive(int $interaction): InteractionPassiveResource
    {
        $record = PublicInteractionQuery::passive()
            ->where('interactions_passive.id', $interaction)
            ->first();

        abort_unless($record, 404, 'Interaction not found.');

        return InteractionPassiveResource::make($record);
    }

    public function indexActive(SearchActiveInteractionsRequest $request): AnonymousResourceCollection
    {
        return $this->activeInteractions($request);
    }

    public function showActive(int $interaction): InteractionActiveResource
    {
        $record = PublicInteractionQuery::active()
            ->where('interactions_active.id', $interaction)
            ->first();

        abort_unless($record, 404, 'Interaction not found.');

        return InteractionActiveResource::make($record);
    }
}

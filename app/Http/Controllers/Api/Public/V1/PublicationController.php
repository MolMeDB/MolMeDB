<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Http\Controllers\Api\Public\V1\Concerns\DownloadsExportFile;
use App\Http\Controllers\Api\Public\V1\Concerns\ListsInteractions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\V1\SearchActiveInteractionsRequest;
use App\Http\Requests\Api\Public\V1\SearchPassiveInteractionsRequest;
use App\Http\Resources\Api\Public\V1\PublicationResource;
use App\Models\File;
use App\Models\Publication;
use App\Services\Interactions\PublicInteractionQuery;
use Illuminate\Http\Request;

/**
 * Read-only, unauthenticated public API. Deliberately not sharing code with
 * App\Http\Controllers\PublicationController — internal changes there must
 * never silently change this public contract.
 */
class PublicationController extends Controller
{
    use DownloadsExportFile;
    use ListsInteractions;

    public function index(Request $request)
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $publications = Publication::filter($request->only(['query']))
            ->paginateFilter($perPage);

        return PublicationResource::collection($publications);
    }

    public function show(Publication $publication)
    {
        $publication->load('authors');

        return PublicationResource::make($publication)->withDetails();
    }

    public function stats(Publication $publication)
    {
        $publication->loadCount([
            'membranes',
            'methods',
            'datasets',
        ]);

        return response()->json([
            'data' => [
                'publication' => PublicationResource::make($publication),
                'total' => [
                    // Primary or secondary (dataset) reference, the same as the interaction listings.
                    'interactions_passive' => PublicInteractionQuery::total(PublicInteractionQuery::passive(['publication' => $publication->id])),
                    'interactions_active' => PublicInteractionQuery::total(PublicInteractionQuery::active(['publication' => $publication->id])),
                    'membranes' => $publication->membranes_count,
                    'methods' => $publication->methods_count,
                    'datasets' => $publication->datasets_count,
                ],
            ],
        ]);
    }

    /**
     * Passive interactions with this publication as their primary reference
     * or as the secondary reference of their dataset.
     */
    public function interactionsPassive(Publication $publication, SearchPassiveInteractionsRequest $request)
    {
        return $this->passiveInteractions($request, ['publication' => $publication->id]);
    }

    /**
     * Active interactions with this publication as their primary reference
     * or as the secondary reference of their dataset.
     */
    public function interactionsActive(Publication $publication, SearchActiveInteractionsRequest $request)
    {
        return $this->activeInteractions($request, ['publication' => $publication->id]);
    }

    public function exportPassive(Publication $publication)
    {
        return $this->downloadLatestExport($publication, File::TYPE_EXPORT_INTERACTIONS_PASSIVE_PUBLICATION);
    }

    public function exportActive(Publication $publication)
    {
        return $this->downloadLatestExport($publication, File::TYPE_EXPORT_INTERACTIONS_ACTIVE_PUBLICATION);
    }
}

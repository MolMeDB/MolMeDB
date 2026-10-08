<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Http\Controllers\Api\Public\V1\Concerns\DownloadsExportFile;
use App\Http\Controllers\Api\Public\V1\Concerns\ListsInteractions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\V1\SearchPassiveInteractionsRequest;
use App\Http\Resources\Api\Public\V1\CategoryTreeCollection;
use App\Http\Resources\Api\Public\V1\MembraneResource;
use App\Models\Category;
use App\Models\File;
use App\Models\Membrane;
use App\Services\Interactions\PublicInteractionQuery;
use Illuminate\Http\Request;

/**
 * Read-only, unauthenticated public API. Deliberately not sharing code with
 * App\Http\Controllers\MembraneController — internal changes there must
 * never silently change this public contract.
 */
class MembraneController extends Controller
{
    use DownloadsExportFile;
    use ListsInteractions;

    public function index(Request $request)
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $membranes = Membrane::filter($request->only(['query', 'category_id']))
            ->paginateFilter($perPage);

        return MembraneResource::collection($membranes);
    }

    public function show(Membrane $membrane)
    {
        $membrane->load('categories');

        return MembraneResource::make($membrane);
    }

    public function stats(Membrane $membrane)
    {
        $interactions = PublicInteractionQuery::passive(['membrane' => $membrane->id]);

        return response()->json([
            'data' => [
                'membrane' => MembraneResource::make($membrane),
                'total' => [
                    'interactions_passive' => PublicInteractionQuery::total($interactions),
                    'structures' => PublicInteractionQuery::structures($interactions),
                ],
            ],
        ]);
    }

    public function categories()
    {
        $categories = Category::where('type', Category::TYPE_MEMBRANE)
            ->with('membranes')
            ->orderBy('order', 'asc')
            ->get();

        return CategoryTreeCollection::forMembranes($categories);
    }

    public function interactions(Membrane $membrane, SearchPassiveInteractionsRequest $request)
    {
        return $this->passiveInteractions($request, ['membrane' => $membrane->id]);
    }

    public function export(Membrane $membrane)
    {
        return $this->downloadLatestExport($membrane, File::TYPE_EXPORT_INTERACTIONS_MEMBRANE);
    }
}

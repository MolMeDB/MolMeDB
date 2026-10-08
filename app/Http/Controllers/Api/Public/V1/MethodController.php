<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Http\Controllers\Api\Public\V1\Concerns\DownloadsExportFile;
use App\Http\Controllers\Api\Public\V1\Concerns\ListsInteractions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\V1\SearchPassiveInteractionsRequest;
use App\Http\Resources\Api\Public\V1\CategoryTreeCollection;
use App\Http\Resources\Api\Public\V1\MethodResource;
use App\Models\Category;
use App\Models\File;
use App\Models\Method;
use App\Services\Interactions\PublicInteractionQuery;
use Illuminate\Http\Request;

/**
 * Read-only, unauthenticated public API. Deliberately not sharing code with
 * App\Http\Controllers\MethodController — internal changes there must
 * never silently change this public contract.
 */
class MethodController extends Controller
{
    use DownloadsExportFile;
    use ListsInteractions;

    public function index(Request $request)
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $methods = Method::filter($request->only(['query', 'category_id']))
            ->paginateFilter($perPage);

        return MethodResource::collection($methods);
    }

    public function show(Method $method)
    {
        $method->load('categories');

        return MethodResource::make($method);
    }

    public function stats(Method $method)
    {
        $interactions = PublicInteractionQuery::passive(['method' => $method->id]);

        return response()->json([
            'data' => [
                'method' => MethodResource::make($method),
                'total' => [
                    'interactions_passive' => PublicInteractionQuery::total($interactions),
                    'interactions_active' => PublicInteractionQuery::total(PublicInteractionQuery::active()->where('datasets.method_id', $method->id)),
                    'structures' => PublicInteractionQuery::structures($interactions),
                ],
            ],
        ]);
    }

    public function categories()
    {
        $categories = Category::where('type', Category::TYPE_METHOD)
            ->with('methods')
            ->orderBy('order', 'asc')
            ->get();

        return CategoryTreeCollection::forMethods($categories);
    }

    public function interactions(Method $method, SearchPassiveInteractionsRequest $request)
    {
        return $this->passiveInteractions($request, ['method' => $method->id]);
    }

    public function export(Method $method)
    {
        return $this->downloadLatestExport($method, File::TYPE_EXPORT_INTERACTIONS_METHOD);
    }
}

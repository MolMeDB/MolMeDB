<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\V1\SearchStructureRequest;
use App\Http\Resources\Api\Public\V1\InteractionActiveResource;
use App\Http\Resources\Api\Public\V1\InteractionPassiveResource;
use App\Http\Resources\Api\Public\V1\StructureResource;
use App\Models\Structure;
use App\Services\Structures\StructureIdentifierStatus;
use App\Support\PublicApiUrl;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Read-only, unauthenticated public API. Deliberately not sharing code with
 * App\Http\Controllers\StructureController — internal changes there must
 * never silently change this public contract.
 */
class StructureController extends Controller
{
    public function index(SearchStructureRequest $request)
    {
        $filters = $request->filters();

        // Structures without an identifier yet (pending curation) can't be
        // fetched by any other public endpoint (all keyed by identifier) —
        // excluding them avoids listing dead-end records.
        $query = Structure::filter($filters)->whereNotNull('identifier');

        $structures = filled($filters['substructure'] ?? null)
            ? $query->simplePaginateFilter($request->perPage())
            : $query->paginateFilter($request->perPage());

        return StructureResource::collection($structures);
    }

    public function show(string $identifier)
    {
        $structure = $this->findStructure($identifier);
        $structure->load('identifiers');

        return StructureResource::make($structure)->withDetails();
    }

    public function stats(string $identifier)
    {
        $structure = $this->findStructure($identifier);

        return response()->json([
            'data' => [
                'structure' => StructureResource::make($structure),
                'total' => [
                    'interactions_passive' => $structure->interactionsPassive()->count(),
                    'interactions_active' => $structure->interactionsActive()->count(),
                ],
            ],
        ]);
    }

    public function interactionsPassive(string $identifier, Request $request)
    {
        $structure = $this->findStructure($identifier);

        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $interactions = $structure->interactionsPassive()
            ->with(['dataset.membrane', 'dataset.method', 'dataset.publications', 'publication'])
            ->paginate($perPage);

        return InteractionPassiveResource::collection($interactions);
    }

    public function interactionsActive(string $identifier, Request $request)
    {
        $structure = $this->findStructure($identifier);

        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $interactions = $structure->interactionsActive()
            ->with(['protein', 'dataset.publications', 'publication'])
            ->paginate($perPage);

        return InteractionActiveResource::collection($interactions);
    }

    /**
     * Identifiers are persistent: a merged one redirects (301) to the same
     * endpoint of the structure it was merged into, a removed one answers 410
     * with what is still known about it.
     */
    private function findStructure(string $identifier): Structure
    {
        $status = StructureIdentifierStatus::of($identifier);

        if ($status->status === StructureIdentifierStatus::ACTIVE) {
            return $status->structure;
        }

        if ($status->status === StructureIdentifierStatus::MERGED) {
            $path = Str::after(request()->path(), 'api/v1/');
            $target = preg_replace('#^structures/[^/]+#', 'structures/'.$status->replacedBy(), $path);
            $query = request()->getQueryString();

            throw new HttpResponseException(response()->json([
                'message' => "Structure {$identifier} was merged into {$status->replacedBy()}.",
                'data' => $status->toArray(),
            ], 301, ['Location' => PublicApiUrl::to($target).($query ? "?{$query}" : '')]));
        }

        if ($status->status === StructureIdentifierStatus::DELETED) {
            throw new HttpResponseException(response()->json([
                'message' => "Structure {$identifier} was removed from MolMeDB.",
                'data' => $status->toArray(),
            ], 410));
        }

        abort(404, 'Structure not found.');
    }
}

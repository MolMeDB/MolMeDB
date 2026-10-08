<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStructureRequest;
use App\Http\Requests\UpdateStructureRequest;
use App\Http\Resources\SimilarStructureResource;
use App\Http\Resources\StructureResource;
use App\Models\Category;
use App\Models\Structure;
use App\Services\Structures\Structure3dMolfile;
use App\Services\Structures\StructureIdentifierStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Rdkit\Rdkit;

class StructureController extends Controller
{
    private const SIMILARITY_THRESHOLD = 0.8;

    private const SIMILAR_STRUCTURES = 12;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreStructureRequest $request)
    {
        //
    }

    public function mol3D(string $identifier, Structure3dMolfile $molfiles)
    {
        $structure = Structure::where('identifier', $identifier)->first();

        if (! $structure?->id) {
            return response()->json([
                'message' => 'Structure not found',
            ], 404);
        }

        $molfile = $molfiles->of($structure);

        if ($molfile === null) {
            return response()->json([
                'message' => '3D structure could not be generated.',
            ], 422);
        }

        return response($molfile)
            ->header('Content-Type', Structure3dMolfile::CONTENT_TYPE);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $identifier)
    {
        $structure = Structure::where('identifier', $identifier)->first();

        if (! $structure?->id) {
            return response()->json([
                'message' => 'Structure not found',
            ], 404);
        }

        return StructureResource::make($structure);
    }

    /**
     * Whether an identifier is active, merged into another structure or removed.
     */
    public function status(string $identifier)
    {
        return response()->json(['data' => StructureIdentifierStatus::of($identifier)->toArray()]);
    }

    /**
     * Forms or the parent of a structure and the most similar structures
     * (Tanimoto coefficient of Bingo fingerprints), for the structure page.
     */
    public function similarities(string $identifier)
    {
        $structure = Structure::where('identifier', $identifier)->first();

        if (! $structure?->id) {
            return response()->json([
                'message' => 'Structure not found',
            ], 404);
        }

        $related = collect($structure->parent ? [$structure->parent] : $structure->children)
            ->each->loadCount(['interactionsPassive', 'interactionsActive']);

        return response()->json([
            'related_structures' => SimilarStructureResource::collection($related),
            'similar_structures' => SimilarStructureResource::collection(
                $this->similarStructures($structure, excluded: $related->pluck('id')->all())
            ),
        ]);
    }

    /**
     * The most similar structures apart from the excluded (related) ones,
     * cached for a day: a structure with many close analogues takes up to
     * ~0.5 s to score.
     *
     * @param  array<int, int>  $excluded
     * @return Collection<int, Structure>
     */
    private function similarStructures(Structure $structure, array $excluded): Collection
    {
        // Bingo is a PostgreSQL extension.
        if (DB::getDriverName() !== 'pgsql' || ! $structure->canonical_smiles) {
            return collect();
        }

        $similarities = Cache::remember(
            "structure-similarities:{$structure->id}:".implode(',', $excluded),
            now()->addDay(),
            fn (): array => Structure::similarTo($structure->canonical_smiles, self::SIMILARITY_THRESHOLD)
                ->whereNotNull('structures.identifier')
                ->whereKeyNot([$structure->id, ...$excluded])
                ->limit(self::SIMILAR_STRUCTURES)
                ->get()
                ->mapWithKeys(fn (Structure $similar): array => [$similar->id => (float) $similar->similarity])
                ->all(),
        );

        return Structure::query()
            ->whereKey(array_keys($similarities))
            ->withCount(['interactionsPassive', 'interactionsActive'])
            ->get()
            ->each(fn (Structure $similar) => $similar->setAttribute('similarity', $similarities[$similar->id]))
            ->sortBy([['similarity', 'desc'], ['id', 'asc']])
            ->values();
    }

    public function molCanonizeSmiles(string $smiles)
    {
        $rdkit = new Rdkit;

        if (! $rdkit->is_connected()) {
            return response()->json([
                'message' => 'Rdkit disconnected',
            ], 503);
        }

        return response()->json([
            'request_smiles' => $smiles,
            'canonized_smiles' => $rdkit->canonize_smiles($smiles),
        ]);
    }

    public function formSelectMembranes(string $identifier)
    {
        $structure = Structure::where('identifier', $identifier)
            ->with([
                'interactionsPassive.dataset.membrane.categories.parent',
            ])
            ->first();

        if (! $structure?->id) {
            return response()->json([
                'message' => 'Structure not found',
            ], 404);
        }

        $membranes = $structure->interactionsPassive
            ->pluck('dataset.membrane')
            ->filter()
            ->unique('id')
            ->values();

        $tree = [];

        foreach ($membranes as $membrane) {
            /** @var Category $subcategory */
            $subcategory = $membrane->categories->first();

            if (! $subcategory) {
                continue;
            }

            $mainCategory = $subcategory->parent;

            $mainId = $mainCategory?->id ?? null;
            $subId = $subcategory?->id ?? null;

            if ($mainId && ! isset($tree[$mainId])) {
                $tree[$mainId] = [
                    'placeholder' => $mainCategory->title,
                    'items' => [],
                ];
            }

            if ($subId && ! isset($tree[$mainId]['items'][$subId])) {
                $tree[$mainId]['items'][$subId] = [
                    'type' => 'category',
                    'category' => $subcategory->title,
                    'children' => [],
                ];
            }

            $tree[$mainId]['items'][$subId]['children'][] = [
                'type' => 'item',
                'value' => $membrane->id,
                'label' => $membrane->abbreviation,
                'totalInteractions' => $structure->interactionsPassive->where('dataset.membrane_id', $membrane->id)->count(),
            ];
        }

        foreach ($tree as $mainId => $mainCategory) {
            $tree[$mainId]['items'] = array_values($tree[$mainId]['items']);
        }

        return response()->json(array_values($tree));
    }

    public function formSelectMethods(string $identifier)
    {
        $structure = Structure::where('identifier', $identifier)
            ->with([
                'interactionsPassive.dataset.method.categories.parent',
            ])
            ->first();

        if (! $structure?->id) {
            return response()->json([
                'message' => 'Structure not found',
            ], 404);
        }

        $membraneIds = request()->query('membraneIds');

        $methods = $structure->interactionsPassive
            ->filter(function ($interactions) use ($membraneIds) {
                return $membraneIds === null || in_array($interactions->dataset->membrane_id, $membraneIds);
            })
            ->pluck('dataset.method')
            ->filter()
            ->unique('id')
            ->values();

        $tree = [];

        foreach ($methods as $method) {
            /** @var Category $subcategory */
            $subcategory = $method->categories->first();

            if (! $subcategory) {
                continue;
            }

            $mainCategory = $subcategory->parent;

            $mainId = $mainCategory?->id ?? null;
            $subId = $subcategory?->id ?? null;

            if ($mainId && ! isset($tree[$mainId])) {
                $tree[$mainId] = [
                    'placeholder' => $mainCategory->title,
                    'items' => [],
                ];
            }

            if ($subId && ! isset($tree[$mainId]['items'][$subId])) {
                $tree[$mainId]['items'][$subId] = [
                    'type' => 'category',
                    'category' => $subcategory->title,
                    'children' => [],
                ];
            }

            $tree[$mainId]['items'][$subId]['children'][] = [
                'type' => 'item',
                'value' => $method->id,
                'label' => $method->abbreviation,
                'totalInteractions' => $structure->interactionsPassive->where('dataset.method_id', $method->id)->count(),
            ];
        }

        foreach ($tree as $mainId => $mainCategory) {
            $tree[$mainId]['items'] = array_values($tree[$mainId]['items']);
        }

        return response()->json(array_values($tree));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateStructureRequest $request, Structure $structure)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Structure $structure)
    {
        //
    }
}

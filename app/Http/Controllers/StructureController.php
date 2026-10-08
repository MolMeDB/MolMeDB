<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStructureRequest;
use App\Http\Requests\UpdateStructureRequest;
use App\Http\Resources\StructureResource;
use App\Models\Category;
use App\Models\Structure;
use App\Services\Structures\StructureIdentifierStatus;
use Modules\Rdkit\Rdkit;

class StructureController extends Controller
{
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

    public function mol3D(string $identifier)
    {
        $structure = Structure::where('identifier', $identifier)->first();

        if (! $structure?->id) {
            return response()->json([
                'message' => 'Structure not found',
            ], 404);
        }

        if ($this->isValidMolfile($structure->molfile_3d)) {
            return response($structure->molfile_3d)
                ->header('Content-Type', 'chemical/x-mdl-sdfile');
        }

        if ($structure->molfile_3d !== null) {
            $structure->molfile_3d = null;
            $structure->save();
        }

        $rdkit = new Rdkit;

        $molContent = $rdkit->get_3d_structure($structure->canonical_smiles);

        if (! $this->isValidMolfile($molContent)) {
            return response()->json([
                'message' => '3D structure could not be generated.',
            ], 422);
        }

        $structure->molfile_3d = $molContent;
        $structure->save();

        return response($molContent)
            ->header('Content-Type', 'chemical/x-mdl-sdfile');
    }

    private function isValidMolfile(?string $molfile): bool
    {
        if (! $molfile || trim($molfile) === '') {
            return false;
        }

        $lines = preg_split('/\R/', trim($molfile));

        if (! is_array($lines) || count($lines) < 4) {
            return false;
        }

        $endLineExists = collect($lines)->contains(fn (string $line): bool => trim($line) === 'M  END');

        if (! $endLineExists) {
            return false;
        }

        foreach ($lines as $index => $line) {
            if (str_contains($line, 'V3000')) {
                return collect($lines)->contains(fn (string $line): bool => str_contains($line, 'M  V30 BEGIN CTAB'))
                    && collect($lines)->contains(fn (string $line): bool => str_contains($line, 'M  V30 END CTAB'));
            }

            if (! str_contains($line, 'V2000')) {
                continue;
            }

            if (preg_match('/^\s*(\d+)\s+(\d+).*V2000/', $line, $matches) !== 1) {
                return false;
            }

            $atomCount = (int) $matches[1];
            $bondCount = (int) $matches[2];

            return count($lines) >= $index + $atomCount + $bondCount + 2;
        }

        return false;
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

    public function similarities(string $identifier)
    {
        $structure = Structure::where('identifier', $identifier)->first();

        if (! $structure?->id) {
            return response()->json([
                'message' => 'Structure not found',
            ], 404);
        }

        $related = $structure->parent ? [$structure->parent] : $structure->children;

        $similar = []; // TODO

        return response()->json([
            'related_structures' => StructureResource::collection(collect($related)),
            'similar_structures' => StructureResource::collection(collect($similar)),
        ]);
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

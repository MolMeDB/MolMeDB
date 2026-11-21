<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInteractionPassiveRequest;
use App\Http\Requests\UpdateInteractionPassiveRequest;
use App\Http\Resources\InteractionPassiveResource;
use App\Models\InteractionPassive;
use App\Models\Structure;
use Illuminate\Http\Request;

class InteractionPassiveController extends Controller
{
    public function byStructure(string $identifier, Request $request)
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

        $perPage = min(max($request->integer('per_page', 10), 1), 100);

        $params = $request->all();
        $params['structureId'] = $structure->id;

        $interactions = InteractionPassive::filter($params)
            ->with(['dataset.publications.authors', 'publication.authors'])
            ->paginateFilter($perPage);

        return InteractionPassiveResource::collection($interactions);
    }

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
    public function store(StoreInteractionPassiveRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(InteractionPassive $interactionPassive)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateInteractionPassiveRequest $request, InteractionPassive $interactionPassive)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(InteractionPassive $interactionPassive)
    {
        //
    }
}

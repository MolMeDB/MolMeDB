<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInteractionActiveRequest;
use App\Http\Requests\UpdateInteractionActiveRequest;
use App\Http\Resources\InteractionActiveResource;
use App\Models\InteractionActive;
use App\Models\Structure;
use Illuminate\Http\Request;

class InteractionActiveController extends Controller
{
    public function byStructure(string $identifier, Request $request)
    {
        $structure = Structure::where('identifier', $identifier)
            ->with([
                'interactionsActive.dataset',
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

        $interactions = InteractionActive::filter($params)
            ->with(['dataset.publications.authors', 'publication.authors', 'category'])
            ->paginateFilter($perPage);

        return InteractionActiveResource::collection($interactions);
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
    public function store(StoreInteractionActiveRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(InteractionActive $interactionActive)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateInteractionActiveRequest $request, InteractionActive $interactionActive)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(InteractionActive $interactionActive)
    {
        //
    }
}

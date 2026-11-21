<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProteinRequest;
use App\Http\Requests\UpdateProteinRequest;
use App\Http\Resources\CategoryCollection;
use App\Http\Resources\ProteinResource;
use App\Http\Resources\Public\InteractionActivePublicResource;
use App\Libraries\Export\SpreadsheetSafeCsv;
use App\Models\Category;
use App\Models\Protein;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class ProteinController extends Controller
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
    public function store(StoreProteinRequest $request)
    {
        //
    }

    public function stats(Protein $protein)
    {
        return response()->json([
            'data' => [
                'protein' => ProteinResource::make($protein),
                'interactions_count' => $protein->interactionsActive()->count(),
                'structures_count' => $protein->structures()->count(),
            ],
        ]);
    }

    /**
     * Returns list of protein categories (with proteins)
     */
    public function categories()
    {
        $models = Category::where('type', Category::TYPE_PROTEIN)
            ->with('proteins')
            ->orderby('order', 'asc')
            ->get();

        return CategoryCollection::make($models);
    }

    /**
     * Downloads all protein associated interactions in CSV
     */
    public function downloadInteractions(Protein $protein)
    {
        $interactions = $protein->interactionsActive()->get();

        $csv_rows = InteractionActivePublicResource::collection($interactions)->resolve();

        $filename = 'molmedb_'.$protein->uniprot_id.'_'.date('Y-m-d').'.csv';

        $tmpDir = TemporaryDirectory::make();

        $handle = fopen($tmpDir->path($filename), 'w');

        fputcsv($handle, array_keys($csv_rows[0]), separator: ';', escape: '\\');

        foreach ($csv_rows as $row) {
            fputcsv($handle, SpreadsheetSafeCsv::row($row), separator: ';', escape: '\\');
        }

        fclose($handle);

        return response()->download($tmpDir->path($filename))->deleteFileAfterSend();
    }

    /**
     * Display the specified resource.
     */
    public function show(Protein $protein)
    {
        return ProteinResource::make($protein);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProteinRequest $request, Protein $protein)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Protein $protein)
    {
        //
    }
}

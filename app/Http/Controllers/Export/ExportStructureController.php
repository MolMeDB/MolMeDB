<?php

namespace App\Http\Controllers\Export;

use App\Http\Controllers\Controller;
use App\Libraries\Export\ExportFileHeader;
use App\Libraries\Export\ExportToFile;
use App\Models\Publication;
use App\Models\Structure;
use Generator;

class ExportStructureController extends Controller
{
    /**
     * Export passive interactions for structure.
     */
    public function passiveInteractions(Structure $record)
    {
        $filename = ($record->identifier ?: 'structure_'.$record->id).'_passiveInteractions.csv';
        $header = ExportFileHeader::make()
            ->structure()
            ->passiveInteraction();

        return ExportToFile::streamCsvDownload(
            $filename,
            $header,
            $this->buildPassiveInteractionRows($record),
        );
    }

    /**
     * Export active interactions for structure.
     */
    public function activeInteractions(Structure $record)
    {
        $filename = ($record->identifier ?: 'structure_'.$record->id).'_activeInteractions.csv';
        $header = ExportFileHeader::make()
            ->structure()
            ->activeInteraction();

        return ExportToFile::streamCsvDownload(
            $filename,
            $header,
            $this->buildActiveInteractionRows($record),
        );
    }

    protected function buildPassiveInteractionRows(Structure $record): Generator
    {
        $structureBase = $this->buildStructureBaseData($record);

        foreach ($record->interactionsPassive()
            ->with([
                'dataset.membrane',
                'dataset.method',
                'dataset.publications',
                'publication',
            ])
            ->lazyById(200, 'id') as $interaction) {
            $secondaryPublication = $interaction->dataset?->publications
                ?->first(fn (Publication $publication): bool => $publication->id !== $interaction->publication_id);

            yield (object) array_merge($structureBase, [
                'membrane' => $interaction->dataset?->membrane?->abbreviation,
                'method' => $interaction->dataset?->method?->abbreviation,
                'temperature' => $interaction->temperature,
                'ph' => $interaction->ph,
                'charge' => $interaction->charge,
                'note' => $interaction->note,
                'x_min' => $interaction->x_min,
                'x_min_accuracy' => $interaction->x_min_accuracy,
                'gpen' => $interaction->gpen,
                'gpen_accuracy' => $interaction->gpen_accuracy,
                'gwat' => $interaction->gwat,
                'gwat_accuracy' => $interaction->gwat_accuracy,
                'logk' => $interaction->logk,
                'logk_accuracy' => $interaction->logk_accuracy,
                'logperm' => $interaction->logperm,
                'logperm_accuracy' => $interaction->logperm_accuracy,
            ],
                ExportToFile::publicationFields('primary', $interaction->publication),
                ExportToFile::publicationFields('secondary', $secondaryPublication),
            );
        }
    }

    protected function buildActiveInteractionRows(Structure $record): Generator
    {
        $structureBase = $this->buildStructureBaseData($record);

        foreach ($record->interactionsActive()
            ->with([
                'dataset.publications',
                'publication',
                'protein',
            ])
            ->lazyById(200, 'id') as $interaction) {
            $secondaryPublication = $interaction->dataset?->publications
                ?->first(fn (Publication $publication): bool => $publication->id !== $interaction->publication_id);

            yield (object) array_merge($structureBase, [
                'protein' => $interaction->protein?->uniprot_id,
                'temperature' => $interaction->temperature,
                'ph' => $interaction->ph,
                'charge' => $interaction->charge,
                'note' => $interaction->note,
                'km' => $interaction->km,
                'km_accuracy' => $interaction->km_accuracy,
                'ec50' => $interaction->ec50,
                'ec50_accuracy' => $interaction->ec50_accuracy,
                'ki' => $interaction->ki,
                'ki_accuracy' => $interaction->ki_accuracy,
                'ic50' => $interaction->ic50,
                'ic50_accuracy' => $interaction->ic50_accuracy,
            ],
                ExportToFile::publicationFields('primary', $interaction->publication),
                ExportToFile::publicationFields('secondary', $secondaryPublication),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildStructureBaseData(Structure $record): array
    {
        return [
            'identifier' => $record->identifier,
            'name' => $record->name,
            'canonical_smiles' => $record->canonical_smiles,
            'inchikey' => $record->inchikey,
            'mw' => $record->molecular_weight,
            'logp' => $record->logp,
            'pubchem' => $record->pubchem,
            'pdb' => $record->pdb,
            'chembl' => $record->chembl,
            'chebi' => $record->chebi,
            'drugbank' => $record->drugbank,
        ];
    }
}

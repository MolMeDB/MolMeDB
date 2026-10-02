<?php

namespace App\Libraries\Export;

class ExportFileHeader
{
    /**
     * @var ExportFileColumn[]
     */
    public $items = [];

    public static function make(): self
    {
        return new self;
    }

    public function as_array(): array
    {
        return array_map(function ($item) {
            return $item->label;
        }, $this->items);
    }

    public function structure($prefix = '')
    {
        $prefix = $prefix ? $prefix.'.' : '';

        $this->items = [
            ...$this->items,
            ExportFileColumn::make($prefix.'identifier'),
            ExportFileColumn::make($prefix.'name'),
            ExportFileColumn::make($prefix.'canonical_smiles'),
            ExportFileColumn::make($prefix.'inchikey'),
            ExportFileColumn::make($prefix.'mw'),
            ExportFileColumn::make($prefix.'logp'),
            ExportFileColumn::make($prefix.'pubchem'),
            ExportFileColumn::make($prefix.'pdb'),
            ExportFileColumn::make($prefix.'chembl'),
            ExportFileColumn::make($prefix.'chebi'),
            ExportFileColumn::make($prefix.'drugbank'),
        ];

        return $this;
    }

    public function passiveInteraction($prefix = '')
    {
        $prefix = $prefix ? $prefix.'.' : '';

        $this->items = [
            ...$this->items,
            ExportFileColumn::make($prefix.'membrane', 'Membrane'),
            ExportFileColumn::make($prefix.'method', 'Method'),
            ExportFileColumn::make($prefix.'temperature'),
            ExportFileColumn::make($prefix.'ph'),
            ExportFileColumn::make($prefix.'charge'),
            ExportFileColumn::make($prefix.'note'),
            ExportFileColumn::make($prefix.'x_min', 'Xmin'),
            ExportFileColumn::make($prefix.'x_min_accuracy', '+/- Xmin'),
            ExportFileColumn::make($prefix.'gpen', 'Gpen'),
            ExportFileColumn::make($prefix.'gpen_accuracy', '+/- Gpen'),
            ExportFileColumn::make($prefix.'gwat', 'Gwat'),
            ExportFileColumn::make($prefix.'gwat_accuracy', '+/- Gwat'),
            ExportFileColumn::make($prefix.'logk', 'LogK'),
            ExportFileColumn::make($prefix.'logk_accuracy', '+/- LogK'),
            ExportFileColumn::make($prefix.'logperm', 'LogPerm'),
            ExportFileColumn::make($prefix.'logperm_accuracy', '+/- LogPerm'),
            ExportFileColumn::make($prefix.'primary_citation', 'Primary reference'),
            ExportFileColumn::make($prefix.'primary_doi', 'Primary reference DOI'),
            ExportFileColumn::make($prefix.'primary_pubmed_id', 'Primary reference PubMed ID'),
            ExportFileColumn::make($prefix.'secondary_citation', 'Secondary reference'),
            ExportFileColumn::make($prefix.'secondary_doi', 'Secondary reference DOI'),
            ExportFileColumn::make($prefix.'secondary_pubmed_id', 'Secondary reference PubMed ID'),
        ];

        return $this;
    }

    public function activeInteraction($prefix = '')
    {
        $prefix = $prefix ? $prefix.'.' : '';

        $this->items = [
            ...$this->items,
            ExportFileColumn::make($prefix.'protein', 'Protein'),
            ExportFileColumn::make($prefix.'temperature'),
            ExportFileColumn::make($prefix.'ph'),
            ExportFileColumn::make($prefix.'charge'),
            ExportFileColumn::make($prefix.'note'),
            ExportFileColumn::make($prefix.'km', 'Km'),
            ExportFileColumn::make($prefix.'km_accuracy', '+/- Km'),
            ExportFileColumn::make($prefix.'ec50', 'EC50'),
            ExportFileColumn::make($prefix.'ec50_accuracy', '+/- EC50'),
            ExportFileColumn::make($prefix.'ki', 'Ki'),
            ExportFileColumn::make($prefix.'ki_accuracy', '+/- Ki'),
            ExportFileColumn::make($prefix.'ic50', 'IC50'),
            ExportFileColumn::make($prefix.'ic50_accuracy', '+/- IC50'),
            ExportFileColumn::make($prefix.'primary_citation', 'Primary reference'),
            ExportFileColumn::make($prefix.'primary_doi', 'Primary reference DOI'),
            ExportFileColumn::make($prefix.'primary_pubmed_id', 'Primary reference PubMed ID'),
            ExportFileColumn::make($prefix.'secondary_citation', 'Secondary reference'),
            ExportFileColumn::make($prefix.'secondary_doi', 'Secondary reference DOI'),
            ExportFileColumn::make($prefix.'secondary_pubmed_id', 'Secondary reference PubMed ID'),
        ];

        return $this;
    }
}

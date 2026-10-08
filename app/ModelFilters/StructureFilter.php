<?php

namespace App\ModelFilters;

use App\Http\Requests\Api\Public\V1\SearchStructureRequest;
use App\Models\Identifier;
use EloquentFilter\ModelFilter;

class StructureFilter extends ModelFilter
{
    public function query($name)
    {
        $query = trim((string) $name);

        return $this->leftJoin('identifiers as i', 'i.structure_id', '=', 'structures.id')
            ->where(function ($builder) use ($query): void {
                $builder
                    ->whereRaw('LOWER(i.value) LIKE ?', ['%'.mb_strtolower($query).'%'])
                    ->orWhere('structures.canonical_smiles', $query);
            })
            ->selectRaw('DISTINCT ON (structures.id) structures.*, i.value as matched_identifier');
    }

    public function smiles($smiles)
    {
        $smiles = trim((string) $smiles);

        if ($smiles === '') {
            return $this;
        }

        return $this->exactMolecule($smiles);
    }

    public function substructure($smiles)
    {
        $smiles = trim((string) $smiles);

        if ($smiles === '') {
            return $this;
        }

        return $this->containingSubstructure($smiles);
    }

    public function inchikey(string $inchikey): void
    {
        $this->where('structures.inchikey', strtoupper(trim($inchikey)));
    }

    public function pubchem(string $value): void
    {
        $this->withExternalIdentifier(Identifier::TYPE_PUBCHEM, [$value]);
    }

    public function chembl(string $value): void
    {
        $this->withExternalIdentifier(Identifier::TYPE_CHEMBL, [strtoupper($value)]);
    }

    /**
     * ChEBI ids are stored both as "CHEBI:15365" and as the bare "15365".
     */
    public function chebi(string $value): void
    {
        $number = preg_replace('/^CHEBI:/i', '', trim($value));

        $this->withExternalIdentifier(Identifier::TYPE_CHEBI, ["CHEBI:{$number}", $number]);
    }

    public function drugbank(string $value): void
    {
        $this->withExternalIdentifier(Identifier::TYPE_DRUGBANK, [strtoupper($value)]);
    }

    public function pdb(string $value): void
    {
        $this->withExternalIdentifier(Identifier::TYPE_PDB, [strtoupper($value)]);
    }

    /**
     * Several structures at once by their MolMeDB identifiers ("MM00040,MM00041").
     */
    public function identifiers(string $identifiers): void
    {
        $this->whereIn('structures.identifier', SearchStructureRequest::identifierList($identifiers));
    }

    /**
     * Structures with a public cross-reference of the type and one of the values.
     *
     * @param  array<int, string>  $values
     */
    private function withExternalIdentifier(int $type, array $values): void
    {
        $this->whereIn('structures.id', fn ($identifiers) => $identifiers
            ->select('structure_id')
            ->from('identifiers')
            ->where('type', $type)
            ->whereIn('value', array_map('trim', $values))
            ->whereNotIn('state', Identifier::NON_PUBLIC_STATES)
            ->whereNull('deleted_at'));
    }

    public function setup()
    {
        if ($this->input('substructure')) {
            return;
        }

        $this->defaultOrder();
    }

    public function defaultOrder()
    {
        $this->orderBy('id', 'asc');
    }
}

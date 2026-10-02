<?php

namespace App\Support;

use App\Models\Identifier;

/**
 * Resolves MolMeDB's internal (type, value) cross-reference pairs to full
 * https://identifiers.org/ URIs, so API consumers get a directly
 * dereferenceable, globally unique identifier instead of a bare code that
 * only means something inside MolMeDB's own type enum.
 *
 * Namespace slugs below were verified against the live registry
 * (https://registry.api.identifiers.org). ChEBI is a special case: new
 * uploads store the full compact id ("CHEBI:36927", see
 * App\Rules\UploadFile\Identifiers\ColumnChebi), but older/imported rows
 * were found (by hitting the real dev database) to hold the bare numeric
 * id ("36927") — both are normalized to "CHEBI:{n}" here since that's the
 * identifiers.org-embedded LUI format ChEBI's namespace expects.
 */
class ExternalIdentifierResolver
{
    public static function resolve(int $type, string $value): ?string
    {
        return match ($type) {
            Identifier::TYPE_PUBCHEM => "https://identifiers.org/pubchem.compound:{$value}",
            Identifier::TYPE_DRUGBANK => "https://identifiers.org/drugbank:{$value}",
            Identifier::TYPE_CHEBI => 'https://identifiers.org/'.self::normalizeChebi($value),
            // MolMeDB's TYPE_PDB stores RCSB *ligand* codes (e.g. "CFF"), not
            // full 4-char structure entries — those live in identifiers.org's
            // "pdb-ccd" (Chemical Component Dictionary) namespace, not "pdb".
            Identifier::TYPE_PDB => "https://identifiers.org/pdb-ccd:{$value}",
            Identifier::TYPE_CHEMBL => "https://identifiers.org/chembl.compound:{$value}",
            default => null,
        };
    }

    private static function normalizeChebi(string $value): string
    {
        return preg_match('/^CHEBI:/i', $value) ? $value : "CHEBI:{$value}";
    }

    public static function resolveUniprot(string $value): string
    {
        return "https://identifiers.org/uniprot:{$value}";
    }

    /**
     * The slash form (identifiers.org/molmedb/MM00040) is the IRI MolMeDB RDF
     * uses for substances, so JSON-LD and RDF name the same resource.
     */
    public static function resolveMolMeDb(string $identifier): string
    {
        $namespace = config('fair.identifiers_org_namespace');

        return "https://identifiers.org/{$namespace}/{$identifier}";
    }
}

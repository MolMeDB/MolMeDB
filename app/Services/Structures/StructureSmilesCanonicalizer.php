<?php

namespace App\Services\Structures;

use Illuminate\Support\Facades\Cache;
use Modules\Rdkit\Rdkit;

/**
 * Writes a searched SMILES the way the canonical_smiles of structures are
 * stored: in the canonical form of RDKit. Bingo perceives aromaticity by its
 * own model, so the Kekulé form of a ring that RDKit stored as aromatic
 * (caffeine, uracil) does not match it; the RDKit form of the same molecule
 * does.
 */
class StructureSmilesCanonicalizer
{
    private const TTL_SECONDS = 86400;

    /**
     * The RDKit canonical form of the SMILES, or the SMILES as given when
     * RDKit is not available or cannot read it.
     */
    public function canonicalOrOriginal(string $smiles): string
    {
        $smiles = trim($smiles);

        if ($smiles === '') {
            return $smiles;
        }

        $key = 'structure-search:canonical-smiles:'.hash('sha256', $smiles);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $canonical = (new Rdkit)->canonize_smiles($smiles);

        if (! is_string($canonical) || trim($canonical) === '') {
            return $smiles;
        }

        Cache::put($key, trim($canonical), self::TTL_SECONDS);

        return trim($canonical);
    }
}

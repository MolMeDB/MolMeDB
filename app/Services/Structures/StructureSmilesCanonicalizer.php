<?php

namespace App\Services\Structures;

use Illuminate\Support\Facades\Cache;
use Modules\Rdkit\Rdkit;

/**
 * Writes a searched SMILES the way the canonical_smiles of structures are
 * stored: in the canonical form of the RDKit service (RDKit canonical SMILES
 * in the Kekulé form). Bingo perceives aromaticity by its own model, so a
 * query in another notation of a ring (caffeine, uracil) may not match the
 * stored form; the same form of the same molecule does.
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

        return $this->canonical($smiles) ?? $smiles;
    }

    /**
     * The RDKit canonical form of a substructure query, or the query as given
     * when RDKit is not available or cannot read it.
     *
     * A bracketed hydrogen of an aromatic nitrogen ([nH]) is required by a
     * Bingo query: uracil would no longer match N-methyluracil, while a plain
     * N of the Kekulé form leaves it free. The canonical form is Kekulé, but
     * the service falls back to the aromatic form of a molecule it cannot
     * kekulize, so the hydrogen is still left out of the query.
     */
    public function substructureQueryOrOriginal(string $smiles): string
    {
        $smiles = trim($smiles);
        $canonical = $this->canonical($smiles);

        return $canonical === null ? $smiles : str_replace('[nH]', 'n', $canonical);
    }

    private function canonical(string $smiles): ?string
    {
        if ($smiles === '') {
            return null;
        }

        $key = 'structure-search:canonical-smiles:'.Rdkit::CANONICAL_SMILES_FORM.':'.hash('sha256', $smiles);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $canonical = (new Rdkit)->canonize_smiles($smiles);

        if (! is_string($canonical) || trim($canonical) === '') {
            return null;
        }

        Cache::put($key, trim($canonical), self::TTL_SECONDS);

        return trim($canonical);
    }
}

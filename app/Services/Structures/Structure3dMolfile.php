<?php

namespace App\Services\Structures;

use App\Models\Structure;
use Modules\Rdkit\Rdkit;

/**
 * 3D structure of a molecule as an MDL molfile, shared by the internal and
 * the public API. A stored molfile is returned as it is; otherwise it is
 * generated from the canonical SMILES by RDKit and stored for next time.
 */
class Structure3dMolfile
{
    public const CONTENT_TYPE = 'chemical/x-mdl-molfile';

    /**
     * The molfile, or null when RDKit cannot generate a valid one.
     */
    public function of(Structure $structure): ?string
    {
        if (self::isValid($structure->molfile_3d)) {
            return $structure->molfile_3d;
        }

        if ($structure->molfile_3d !== null) {
            $structure->molfile_3d = null;
            $structure->save();
        }

        $molfile = (new Rdkit)->get_3d_structure($structure->canonical_smiles);

        if (! is_string($molfile) || ! self::isValid($molfile)) {
            return null;
        }

        $structure->molfile_3d = $molfile;
        $structure->save();

        return $molfile;
    }

    /**
     * Whether the text is a complete V2000 or V3000 molfile.
     */
    public static function isValid(?string $molfile): bool
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
}

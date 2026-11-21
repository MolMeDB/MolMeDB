<?php

namespace App\Rules\UploadFile\ActiveInteractions;

use App\Rules\UploadFile\ColumnTypeInterface;
use App\Services\External\Protein\Uniprot;
use Closure;

class ColumnTarget implements ColumnTypeInterface
{
    public static string $key = 'active_target';

    public static string $label = 'Target (Uniprot ID)';

    public static int $maxLength = 255;

    /**
     * UniProtKB accession with the optional version, isoform and chain suffix.
     */
    const string REGEXP = '/^(?:[A-NR-Z][0-9](?:[A-Z][A-Z0-9]{2}[0-9]){1,2}|[OPQ][0-9][A-Z0-9]{3}[0-9])(\.\d+)?(-\d+)?(#PRO_\d+)?$/';

    public static function make(): static
    {
        return new static;
    }

    public function validate(string $attribute, $value, Closure $fail): void
    {
        $this->validate_fast($attribute, $value, $fail);

        if (! $this->exists_remotely($value)) {
            $fail('Column '.self::$label." contains Uniprot ID that does not exist: $value.");
        }
    }

    public function validate_fast(string $attribute, mixed $value, Closure $fail): void
    {
        $maxLength = self::$maxLength;
        if (! $value || strlen($value) <= 2 || strlen($value) > $maxLength) {
            $fail('Column '.self::$label." must be a string between 3 and $maxLength characters.");
        }

        if (! preg_match(self::REGEXP, $value)) {
            $fail('Column '.self::$label.' must be a valid Uniprot ID.');
        }
    }

    public function exists_remotely(string $value): mixed
    {
        $uniprot = new Uniprot;

        return $uniprot->existsById($value);
    }
}

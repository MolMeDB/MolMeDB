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
    const string REGEXP = '/^(?:[A-NR-Z][0-9](?:[A-Z][A-Z0-9]{2}[0-9]){1,2}|[OPQ][0-9][A-Z0-9]{3}[0-9])(\.\d+)?(-\d+)?(#PRO_\d+)?$/D';

    public static function make(): static
    {
        return new static;
    }

    public function validate(string $attribute, $value, Closure $fail): void
    {
        $valid = true;
        $this->validate_fast($attribute, $value, function (string $message) use ($fail, &$valid): void {
            $valid = false;
            $fail($message);
        });

        // A value that is no accession is not sent to UniProt at all.
        if ($valid && ! $this->exists_remotely($value)) {
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
        // UniProt looks up an accession or an isoform; it knows no entry for
        // a version (P08183.3) or a chain (P08183#PRO_0000093332) suffix.
        $accession = (string) preg_replace('/(\.\d+)?(-\d+)?(#PRO_\d+)?$/D', '$2', $value);

        return (new Uniprot)->existsById($accession);
    }
}

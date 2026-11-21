<?php

namespace App\Rules\UploadFile\ActiveInteractions;

use App\Rules\UploadFile\ColumnTypeInterface;
use Closure;

class ColumnProteinName implements ColumnTypeInterface
{
    public static string $key = 'protein_name';

    public static string $label = 'Protein name';

    public static int $maxLength = 255;

    public static function make(): static
    {
        return new static;
    }

    public function validate(string $attribute, $value, Closure $fail): void
    {
        $ml = self::$maxLength;
        if (! is_string($value) || strlen($value) > $ml) {
            $fail('Column '.self::$label." must be a string with a maximum of $ml characters.");
        }
    }

    public function validate_fast(string $attribute, mixed $value, Closure $fail): void
    {
        $this->validate($attribute, $value, $fail);
    }
}

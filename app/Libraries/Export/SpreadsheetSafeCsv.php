<?php

namespace App\Libraries\Export;

/**
 * Exported CSV files are opened in spreadsheets, which run a cell starting
 * with "=", "+", "-" or "@" as a formula (CSV injection). Free-text values
 * such as a contributor's comment are prefixed with an apostrophe, which the
 * spreadsheet hides; numbers, including negative ones, stay as they are.
 */
class SpreadsheetSafeCsv
{
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    public static function row(array $values): array
    {
        return array_map(self::cell(...), $values);
    }

    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], self::FORMULA_PREFIXES, true) ? "'".$value : $value;
    }
}

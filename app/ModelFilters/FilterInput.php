<?php

namespace App\ModelFilters;

/**
 * Checks of request input before it reaches a filter's query, so malformed
 * values match nothing instead of failing in the database.
 */
class FilterInput
{
    /**
     * The largest id any table holds: membrane and method ids are integer
     * columns, larger values would fail there.
     */
    public const MAX_ID = 2147483647;

    /**
     * Whether the value is a record id: digits only and within the integer
     * range.
     */
    public static function isId(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= self::MAX_ID;
        }

        return is_string($value)
            && ctype_digit($value)
            && strlen($value) <= 10
            && (int) $value <= self::MAX_ID;
    }
}

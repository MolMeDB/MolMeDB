<?php

/**
 * Works with arrays
 * 
 * @author Jakub Juracka
 */
class Arr
{
    /**
     * Sorts array by given key
     * !!! REMOVES GIVEN ARR KEYS
     * 
     * @param array $arr
     * @param string $key
     * 
     * @return array
     */
    public static function sort_by_key(array $arr, $key, int $type = SORT_STRING)
    {
        $__t = $result = [];

        foreach($arr as $k => $a)
        {
            $__t[$k] = $a[$key];
        }

        asort($__t, $type);

        foreach($__t as $k => $a)
        {
            $result[] = $arr[$k];
        }

        return $result;
    }
}
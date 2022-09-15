<?php

/**
 * Array helper
 */
class arr
{
    /**
     * Counts dimension of array
     * 
     * @param array $input
     * 
     * @return int
     */
    public static function dimension($input)
    {
        if (is_array(reset($array)))
        {
            $return = self::dimension(reset($array)) + 1;
        }

        else
        {
            $return = 1;
        }

        return $return;
    }

    /**
     * Union of multiple arrays
     * 
     * @param array $arrs
     * 
     * @return array
     */
    public static function union(...$arrs)
    {
        $result = [];

        foreach($arrs as $arr)
        {
            foreach($arr as $v)
            {
                $result[] = $v;
            }
        }

        return $result;
    }

    /**
     * Returns values from multidimensional array on given key (merged)
     * 
     * @param array $input
     * @param string $key
     * 
     * @return array
     */
    public static function get_values($input, $key)
    {
        if(!is_iterable($input) || !count($input) || !is_iterable($input[0]))
        {
            return [];
        }

        $result = [];

        foreach($input as $row)
        {
            $result[] = $row[$key];
        }

        return $result;
    }

    /**
    * Removes empty values from array
    * 
    * @param array $arr
    * @param bool $recursive
    * 
    * @return array
    */
    public static function remove_empty_values($arr = array(), $recursive = false)
    {
        if(!is_array($arr))
        {
            if(!strval($arr) !== "0" && !$arr)
            {
                return NULL;
            }
            else
            {
                return $arr;
            }
        }

        for($i = 0; $i < count($arr); $i++)
        {
            if($recursive && is_array($arr[$i]))
            {
                $arr[$i] = self::remove_empty_values($arr[$i]);
            }
            
            $arr[$i] = trim($arr[$i]);
            
            if($arr[$i] === '' || $arr[$i] === NULL)
            {
                unset($arr[$i]);
            }
        }
        
        return $arr;
    }

    /**
     * Get max value from array
     * 
     * @param array $data
     * 
     * @return mixed
     */
    public static function max($data)
    {
        return max($data);
    }

    /**
     * Sums numbers in array
     * 
     * @param array $arr
     * 
     * @return int|double
     */
    public static function sum($arr)
    {
        $total = 0;
        foreach($arr as $v)
        {
            if(is_numeric($v))
            {
                $total += floatval($v);
            }
        }
        return round($total, 2);
    }

    /**
     * Computes average from array
     * 
     * @param array $arr
     * 
     * @return int|double
     */
    public static function average($arr)
    {
        $total_numeric = self::total_numeric($arr);
        if($total_numeric == 0)
        {
            return 0;
        }
        return self::sum($arr) / $total_numeric;
    }

    /**
     * Returns total numeric values in array
     * 
     * @param array $arr
     * 
     * @return int
     */
    public static function total_numeric($arr)
    {
        $total_numeric = 0;
        foreach($arr as $v)
        {
            if(is_numeric($v))
            {
                $total_numeric++;
            }
        }

        return $total_numeric;
    }

    /**
     * Computes standard deviation from array
     * 
     * @param array $arr
     * 
     * @return int|double
     */
    public static function sd($arr)
    {
        return sqrt(self::variance($arr));
    }

    /**
     * Computes variance from array
     * 
     * @param array $arr
     * 
     * @return int|double
     */
    public static function variance($arr)
    {
        $total_numeric = self::total_numeric($arr);

        if($total_numeric == 0)
        {
            return 0;
        }

        $sum = 0;
        $avg = self::average($arr);

        foreach($arr as $v)
        {
            if(is_numeric($v))
            {
                $sum += pow(floatval($v)-$avg, 2);
            }
        }

        return (1/$total_numeric)*$sum;
    }

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
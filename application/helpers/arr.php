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
     * Returns only numeric values from array
     * 
     * @param array $arr
     * 
     * @return array
     */
    public static function numeric($arr)
    {
        return array_map("floatval", array_filter($arr, 'is_numeric'));
    }


    /**
     * Method bins
     */
    const METHOD_SD = 1;
    const METHOD_EQ_DIST = 2;

    /**
     * Returns bins for given settings
     * 
     * @param array $arr
     * @param int $total_bins
     * @param int $method
     * @param array $include_limits
     * 
     * @return array|false
     */
    public static function get_bins_limits($arr, $total_bins, $method = self::METHOD_SD, $include_limits = [])
    {
        $total = self::total_numeric($arr);
        $arr = self::numeric($arr);

        if($total < $total_bins || $total_bins < 4)
        {
            return false;
        }

        asort($arr);

        $avg = self::average($arr);
        $sd = self::sd($arr);

        $min = min($arr);
        $max = max($arr);

        $interval_limits = [];
        $exclude_intervals = [];

        if(is_array($include_limits) && !empty($include_limits))
        {
            foreach($include_limits as $limit)
            {
                $flag = false;
                if(preg_match('/^\s*\+\/-/', $limit))
                {
                    $limit = preg_replace('/^\s*\+\/-\s*/', '', $limit);
                    $flag = true;
                    $v = floatval($limit);
                    if($v > 0)
                    {
                        $exclude_intervals[] = [-$v, $v];
                    }
                    else 
                    {
                        $exclude_intervals[] = [$v, -$v];
                    }
                }

                $interval_limits[] = floatval($limit);

                if($flag)
                {
                    $interval_limits[] = -floatval($limit);
                }
            }
        }

        if($method == self::METHOD_SD)
        {
            if(!$sd)
            {
                return false;
            }

            if($total_bins == 4)
            {
                $interval_limits = array_merge($interval_limits, array
                (
                    round((-3*$sd)+$avg, 2),
                    round((-1.5*$sd)+$avg, 2),
                    round($avg, 2),
                    round((1.5*$sd)+$avg, 2),
                    round((3*$sd)+$avg, 2)
                ));  
            }
            else
            {
                $total_bins -= 2;

                $step = (6*$sd)/($total_bins);
                $c = (-3*$sd)+$avg;

                $interval_limits[] = -999;

                foreach(range(0, $total_bins) as $i)
                {
                    $interval_limits[] = round($c + ($step * $i), 2);
                }

                $interval_limits[] = 999;
            }
        }
        else
        {
            $min = floor($min);
            $max = ceil($max);

            $range = $max - $min;
            $step = $range / $total_bins;

            $c = $min;

            if($step <= 0 || $min >= $max)
            {
                return null;
            }

            while($c <= $max)
            {
                $interval_limits[] = round($c, 2);
                $c = $c + $step;
            }
        }

        foreach($exclude_intervals as $int)
        {
            $t = $interval_limits;
            foreach($t as $k => $limit)
            {
                if($limit > $int[0] && $limit < $int[1])
                {
                    unset($interval_limits[$k]);
                }
            }
        }

        $interval_limits = array_unique($interval_limits);

        if(!function_exists('comp_12345'))
        {
            function comp_12345($a, $b)
            {
                return floatval($a) > floatval($b);
            }
        }

        usort($interval_limits, 'comp_12345');

        return $interval_limits;
    }

    /**
     * Differs data to bins
     * 
     * @param array $arr
     * @param int $total_bins
     * 
     * @return array|false
     */
    public static function to_bins($arr, $total_bins, $method = self::METHOD_SD, $include_limits = [])
    {
        $interval_limits = self::get_bins_limits($arr, $total_bins, $method, $include_limits);

        $total = self::total_numeric($arr);
        $arr = self::numeric($arr);

        if($total < $total_bins || $total_bins < 4)
        {
            return false;
        }

        asort($arr);

        $avg = self::average($arr);

        $bins = [];

        foreach($arr as $v)
        {
            foreach(range(1, count($interval_limits)-1) as $i)
            {
                $key = $i-1;
                $upper_bound = floatval($interval_limits[$i]);
                $v = floatval($v);

                if(!isset($bins[$key]))
                {
                    $bins[$key] = [];
                }

                if($v < $upper_bound && $upper_bound < $avg)
                {
                    $bins[$key][] = $v;
                    break;
                }
                else if($v <= $upper_bound && $upper_bound >= $avg)
                {
                    $bins[$key][] = $v;
                    break;
                }
            }
        }

        foreach(range(0, count($interval_limits)-2) as $i)
        {
            if(!isset($bins[$i]))
            {
                $bins[$i] = [];
            }

            $itv = $interval_limits[$i] . ':' . $interval_limits[$i+1];

            $itv = $interval_limits[$i] < $avg ? '[' . $itv : '(' . $itv;
            $itv = $interval_limits[$i+1] < $avg ? $itv . ')' : $itv . ']';

            $bins[$itv] = $bins[$i];
        }
        
        $bins = array_filter($bins, 'is_string', ARRAY_FILTER_USE_KEY);

        return $bins;
    }

    /**
     * Differs data to bins
     * Save behaviour as to_bins, but returns keys of $arr
     * 
     * @param array $arr
     * @param int $total_bins
     * 
     * @return array|false
     */
    public static function to_bins_keys($arr, $total_bins, $method = self::METHOD_SD, $include_limits = [])
    {
        $interval_limits = self::get_bins_limits($arr, $total_bins, $method, $include_limits);

        $total = self::total_numeric($arr);
        $arr = self::numeric($arr);

        if($total < $total_bins || $total_bins < 4)
        {
            return false;
        }

        asort($arr);

        $avg = self::average($arr);

        $bins = [];

        foreach($arr as $kk => $v)
        {
            foreach(range(1, count($interval_limits)-1) as $i)
            {
                $key = $i-1;
                $upper_bound = floatval($interval_limits[$i]);
                $v = floatval($v);

                if(!isset($bins[$key]))
                {
                    $bins[$key] = [];
                }

                if($v < $upper_bound && $upper_bound < $avg)
                {
                    $bins[$key][] = $kk;
                    break;
                }
                else if($v <= $upper_bound && $upper_bound >= $avg)
                {
                    $bins[$key][] = $kk;
                    break;
                }
            }
        }

        foreach(range(0, count($interval_limits)-2) as $i)
        {
            if(!isset($bins[$i]))
            {
                $bins[$i] = [];
            }

            $itv = $interval_limits[$i] . ':' . $interval_limits[$i+1];

            $itv = $interval_limits[$i] < $avg ? '[' . $itv : '(' . $itv;
            $itv = $interval_limits[$i+1] < $avg ? $itv . ')' : $itv . ']';

            $bins[$itv] = $bins[$i];
        }
        
        $bins = array_filter($bins, 'is_string', ARRAY_FILTER_USE_KEY);

        return $bins;
    }

    /**
     * Returns k-quartil of the data
     * 
     * @param array $arr
     * @param int $k_quartile
     * 
     * @return float
     */
    public static function k_quartile($arr, $k_quartile)
    {
        $arr = array_map('floatval', $arr);
        sort($arr);

        if($k_quartile < 0 || $k_quartile > 4 || !count($arr))
        {
            return false;
        }

        if($k_quartile == 0)
        {
            return min($arr);
        }

        if($k_quartile == 4)
        {
            return max($arr);
        }

        $k_quartile = intval($k_quartile);

        $ratio = $k_quartile/4;

        $total = count($arr);

        $index = (($total+1)*$ratio)-1;

        if(intval($index) === $index)
        {
            return $arr[$index];
        }

        $fraction = $index - intval($index);

        // Interpolate
        $index = intval($index);
        return $arr[$index] + ($arr[$index+1] - $arr[$index])*$fraction;
    }

    /**
     * Computes inter-quartil range
     * 
     * @param array $arr
     * 
     * @return float
     */
    public static function iqr($arr)
    {
        return self::k_quartile($arr, 3) - self::k_quartile($arr, 1);
    }

    /**
     * Returns counts of array/subarrays with persisted keys
     * 
     * @param array $arr
     * @param bool $to_depth
     * 
     * @return $arr
     */
    public static function total_items($arr, $to_depth = false)
    {
        if(!is_array($arr))
        {
            return null;
        }

        if(!$to_depth)
        {
            return count($arr);
        }

        $arr = array_filter($arr, 'is_array');

        foreach($arr as $key => $items)
        {
            $arr[$key] = count($items);
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
        return round(self::sum($arr) / $total_numeric, 3);
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
        return round(sqrt(self::variance($arr)), 3);
    }

    /**
     * Computes n-th central moment
     * 
     * @param array $arr
     * @param int $n
     * 
     * @return double
     */
    public static function central_moment($arr, $n)
    {
        $total_numeric = self::total_numeric($arr);

        if($total_numeric == 0)
        {
            return 0;
        }

        $n = intval($n);

        $sum = 0;
        $avg = self::average($arr);

        foreach($arr as $v)
        {
            if(is_numeric($v))
            {
                $sum += pow(floatval($v)-$avg, $n);
            }
        }

        return round((1/($total_numeric))*$sum, 3);
    }

    /**
     * Approximates skewness of data
     * 
     * @param array $arr
     * 
     * @return double
     */
    public static function skewness($arr)
    {
        $sd = self::sd($arr);

        if(!$sd)
        {
            return 0;
        }

        return round((self::central_moment($arr, 3))/(pow($sd, 3)),3); 
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

        return round((1/($total_numeric-1))*$sum, 3);
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
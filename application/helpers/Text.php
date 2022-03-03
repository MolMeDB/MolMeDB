<?php

/**
 * Text helper
 */
class Text
{
    const range_pattern = '/\[\d+-\d+\]/';

    /**
     * Checks, if text contains range
     * 
     * @param string $text
     * @return array
     */
    public static function get_ranges($text)
    {
        $result = [];
        preg_match_all(self::range_pattern, $text, $result);
        return count($result) ? $result[0] : [];
    }

    /**
     * Parse range
     * 
     * @param $range
     * 
     * @return array
     */
    public static function parse_range($range)
    {
        if(!preg_match(self::range_pattern, $range))
        {
            return [];
        }

        $range = str_replace('[', '', $range);
        $range = str_replace(']', '', $range);

        $d = explode("-", $range);

        if(count($d) !== 2)
        {
            return [];
        }

        $i = intval($d[0]);
        $limit = intval($d[1]);

        $result = [];

        while($i <= $limit)
        {
            $result[] = $i;
            $i++;
        }

        return $result;
    }

    /**
     * String replace nth occurrence
     * 
     * @param string $search  	Search string
     * @param string $replace 	Replace string
     * @param string $subject 	Source string
     * @param int $occurrence 	Nth occurrence
     * @return string 		Replaced string
     */
    public static function str_replace_n($search, $replace, $subject, $occurrence)
    {
        $search = preg_quote($search);
        return preg_replace("/^((?:(?:.*?$search){".--$occurrence."}.*?))$search/", "$1$replace", $subject);
    }

    public static function str_replace_combine_all($search, $replace, $subject)
    {
        $s = preg_quote($search);
        $total = preg_match_all("/$s/", $subject);

        // Get combination of indices
        $t = range(1, $total);
        $combinations = self::uniqueCombination($t);
        $result = [];

        foreach($combinations as $comb)
        {
            $t = $subject;

            foreach($comb as $key => $i)
            {
                $t = self::str_replace_n($search, $replace, $t, $i - $key);
            }

            $result[implode(',', $comb)] = $t;
        }

        return $result;
    }

    private static function uniqueCombination($in, $minLength = 1, $max = 2000) 
    {
        $count = count($in);
        $members = pow(2, $count);
        $return = array();

        for($i = 0; $i < $members; $i ++) 
        {
            $b = sprintf("%0" . $count . "b", $i);
            $out = array();
            for($j = 0; $j < $count; $j ++) {
                $b[$j] == '1' and $out[] = $in[$j];
            }
            count($out) >= $minLength && count($out) <= $max and $return[] = $out;
        }
        return $return;
    }
}
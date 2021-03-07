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
}
<?php

/**
 * Class for easy comparing of times
 * 
 */
class Time
{
    /** Current time */
    protected $current;

    /**
     * Constructor
     * 
     */
    function __construct()
    {
        $this->current = strtotime('now');
    }

    /**
     * Reloads time
     */
    function reload()
    {
        $this->current = strtotime('now');
    }

    /**
     * Returns string datetime
     */
    function get_string($format = NULL)
    {
        if(!$format)
        {
            $format = 'H:i d. m. Y';
        }

        return date($format, $this->current);
    }

    /**
     * Checks if current HOUR is equal to the given
     * 
     * @param int $hour
     * 
     * @return boolean
     */
    public function is_hour($hour)
    {
        return intval(date('H', $this->current)) == $hour;
    }

    /**
     * Returns hour
     * 
     * @return int
     */
    public function get_hour()
    {
        return intval(date('H', $this->current));
    }

    /**
     * Returns minute
     * 
     * @return int
     */
    public function get_minute()
    {
        return intval(date('i', $this->current));
    }

    /**
     * Checks if current MINUTE is equal to given
     * 
     * @param int $minute
     * 
     * @return boolean
     */
    public function is_minute($minute)
    {
        return intval(date('i', $this->current)) == $minute;
    }

    /**
     * Returns interval months 
     * 
     * @param int $start_timestamp
     * @param int $end_timestamp
     * @param string $format
     * 
     * @author Jakub Juracka
     * 
     * @return null|array
     */
    public static function get_interval_months($start_timestamp, $end_timestamp, $format = 'Y-m-d')
    {
        if($start_timestamp > $end_timestamp || !$start_timestamp)
        {
            return null;
        }

        $start_month = date('m', $start_timestamp);
        $start_year = date('Y', $start_timestamp);

        $end_month = date('m', $end_timestamp);
        $end_year = date('Y', $end_timestamp);

        $current = strtotime(date("$start_year-$start_month-01"));

        $result = [];

        while($start_year < $end_year || ($start_month <= $end_month && $start_year == $end_year))
        {
            $result[] = date($format, $current);
            $current = strtotime('+1 month', $current);
            $start_month = date('m', $current);
            $start_year = date('Y', $current);
        }

        return $result;
    }
}
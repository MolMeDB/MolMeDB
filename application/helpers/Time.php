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
}
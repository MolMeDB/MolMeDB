<?php

/**
 * Regexp class
 * 
 * @author Jakub Juracka
 */
class Regexp
{
    /**
     * Check, if regular expression is valid
     * 
     * @param string $regexp
     * 
     * @return boolean
     */
    public static function is_valid_regexp($regexp)
    {
        $subject = ';wd@!';

        return @preg_match($regexp, $subject) !== false;
    }

    /**
     * Check if string is valid for given regexp
     * 
     * @param string $string
     * @param string $regexp
     * 
     * @return boolean
     */
    public static function check($string, $regexp)
    {
        if(!$string || !$regexp || !self::is_valid_regexp($regexp))
        {
            return FALSE;
        }

        return preg_match($regexp, $string);
    }
}
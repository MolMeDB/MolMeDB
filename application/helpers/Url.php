<?php

/**
 * Url class
 * 
 * @author Jakub Juracka
 */
class Url
{
    const URL_PATTERN = "/((([A-Za-z]{3,9}:(?:\/\/)?)(?:[-;:&=\+\$,\w]+@)?[A-Za-z0-9.-]+(:[0-9]+)?|(?:www.|[-;:&=\+\$,\w]+@)[A-Za-z0-9.-]+)((?:\/[\+~%\/.\w\-_]*)?\??(?:[-\+=&;%@.\w_]*)#?(?:[\w]*))?)/";

    /**
     * Checks, if given url is valid
     * 
     * @param string $url
     * 
     * @return boolean
     */
    public static function is_valid($url)
    {
        return preg_match(self::URL_PATTERN, $url);
    }

}
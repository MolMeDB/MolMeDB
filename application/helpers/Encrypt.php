<?php
/**
 * Class for encrypting 
 * 
*/
class Encrypt
{
    /**
     * Generate random password
     * 
     * @return string
     */
    public static function generate_password()
    {
        return Text::generateRandomString(7, true);
    }
}
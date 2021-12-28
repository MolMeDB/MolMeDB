<?php

/**
 * Session info handler
 * 
 * @author Jakub Juracka
 */
class session
{
    /**
     * Instance
     */
    public static function instance()
    {
        return new Iterable_object($_SESSION);
    }

    /**
     * Checks, if api token is set
     * 
     */
    public static function check_api_token()
    {
        $token = Config::get('api_access_token');

        if($token)
        {
            // Set to the session variable
            $_SESSION['api_internal_token'] = $token;
            return;
        }

        // Init new server token
        $name = 'server';
        $pass = Users::return_fingerprint(rand(100,10000));

        Config::set('api_access_token', base64_encode($name . ":" . $pass));
    }
}
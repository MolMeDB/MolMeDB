<?php

/**
 * Session info handler
 * 
 * @author Jakub Juracka
 */
class session
{
    /*
    * Instance
    */
    public static function instance()
    {
        return new Iterable_object($_SESSION);
    }

    /**
     * Returns user id, if logged in. Else NULL
     * 
     * @return int|null
     */
    public static function user_id()
    {
        return isset($_SESSION['user']) && isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : NULL;
    }

    /**
     * Returns user email
     * 
     * @return string|null
     */
    public static function user_email()
    {
        return isset($_SESSION['user']) && isset($_SESSION['user']['email']) ? $_SESSION['user']['email'] : NULL;
    }

    /**
     * Checks, if some user is logged in
     * 
     * @return boolean
     */
    public static function is_logged_in()
    {
        return isset($_SESSION['user']) && isset($_SESSION['user']['id']);
    }

    /**
     * Checks, if logged user is admin
     * 
     * @return boolean
     */
    public static function is_admin()
    {
        return isset($_SESSION['user']) && $_SESSION['user']['admin'] == 1;
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
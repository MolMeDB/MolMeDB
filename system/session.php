<?php

/**
 * Holds session info
 */
class session
{
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
}
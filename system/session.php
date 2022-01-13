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
}
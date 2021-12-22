<?php

/**
 * Server class for serving info about current running server
 * and session info
 * 
 * @author Jakub Juračka
 */
class Server
{
    public static function http_user_agent()
    {
        return $_SERVER['HTTP_USER_AGENT'];
    }

    public static function http_host()
    {
        return $_SERVER['HTTP_HOST'];
    }

    public static function server_name()
    {
        return $_SERVER['SERVER_NAME'];
    }

    public static function server_addr()
    {
        return $_SERVER['SERVER_ADDR'];
    }

    public static function server_port()
    {
        return $_SERVER['SERVER_PORT'];
    }

    public static function remote_addr()
    {
        return $_SERVER['REMOTE_ADDR'];
    }
    
    public static function content_length()
    {
        return @$_SERVER['CONTENT_LENGTH'];
    }

    public static function http_referer()
    {
        return (isset($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : '';
    }

    public static function http_accept_language()
    {
        if (array_key_exists('HTTP_ACCEPT_LANGUAGE', $_SERVER))
        {
            return $_SERVER['HTTP_ACCEPT_LANGUAGE'];
        }
        
        return FALSE;
    }
    
    /**
    * Returns request method
    * 
    * @author Jaromir Hradil
    * 
    * @return string
    */
    public static function request_method()
    {
        return isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : NULL;
    }

    /**
    * Returns accept type header value
    * 
    * @author Jaromir Hradil
    * 
    * @return string
    */
    public static function http_accept()
    {
        return isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : NULL;
    }
    /**
    * Returns accept encoding header value
    * 
    * @author Jaromir Hradil
    * 
    * @return string
    */
    public static function http_encoding()
    {
        return isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : NULL;
    }
    /**
    * Returns accept charset header value
    * 
    * @author Jaromir Hradil
    * 
    * @return string
    */
    public static function http_charset()
    {
        return isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? $_SERVER['HTTP_ACCEPT_CHARSET'] : NULL;
    }
    /**
    * Returns content type header value
    * 
    * @author Jaromir Hradil
    * 
    * @return string
    */
    public static function content_type()
    {
        return isset($_SERVER['CONTENT_TYPE']) ? explode(";",$_SERVER['CONTENT_TYPE'])[0] : NULL;
    }
    /**
    * Returns authorize header value
    * 
    * @author Jaromir Hradil
    * 
    * @return string
    */
    public static function http_authorize()
    {
        return isset($_SERVER['HTTP_AUTHORIZE']) ? $_SERVER['HTTP_AUTHORIZE'] : NULL;
    }

    /**
    * Returns basic auth credentials
    * 
    * @author Jaromir Hradil
    * 
    * @return array
    */
    public static function basic_auth_creds()
    {
        if(!array_key_exists('PHP_AUTH_USER', $_SERVER) ||
           !array_key_exists('PHP_AUTH_PW', $_SERVER))
        {
            return NULL;
        }
        else
        {
            return array
            (
                'username' => $_SERVER['PHP_AUTH_USER'],
                'password' => $_SERVER['PHP_AUTH_PW']
            );
        }
    }
    
    /**
     * Returns server software
     * 
     * @return string
     */
    public static function server_software()
    {
        return isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : NULL;
    }

    /**
     * Returns name of script.
     * 
     * @return string
     */
    public static function script_name()
    {
        return isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : NULL;
    }
    
    /**
     * Return request uri
     * 
     * @return string
     */
    public static function request_uri()
    {
        return isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : NULL;
    }
    
    /**
     * This function is based on current path of this file, please do not
     * relocate this file!!
     * 
     * @return string
     */
    public static function base_dir()
    {
        return dirname(dirname(__FILE__));
    }
}
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
        return $_SERVER['REQUEST_METHOD'];
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
        if(!array_key_exists('HTTP_ACCEPT', $_SERVER))
        {
            return NULL;
        }
        else
        {
            return $_SERVER['HTTP_ACCEPT'];
        }
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
        if(!array_key_exists('HTTP_ACCEPT_ENCODING', $_SERVER))
        {
            return NULL;
        }
        else
        {
            return $_SERVER['HTTP_ACCEPT_ENCODING'];
        }
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
        if(!array_key_exists('HTTP_ACCEPT_CHARSET', $_SERVER))
        {
            return NULL;
        }
        else
        {
            return $_SERVER['HTTP_ACCEPT_CHARSET'];
        }
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
        if(!array_key_exists('CONTENT_TYPE', $_SERVER))
        {
            return NULL;
        }
        else
        {
            return $_SERVER['CONTENT_TYPE'];
        }
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
        if(!array_key_exists('HTTP_AUTHORIZE', $_SERVER))
        {
            return NULL;
        }
        else
        {
            return $_SERVER['HTTP_AUTHORIZE'];
        }
    }
    /**
     * Returns server software
     * 
     * @return string
     */
    public static function server_software()
    {
        return $_SERVER['SERVER_SOFTWARE'];
    }

    /**
     * Returns name of script.
     * 
     * @return string
     */
    public static function script_name()
    {
        return $_SERVER['SCRIPT_NAME'];
    }
    
    /**
     * Return request uri
     * 
     * @return string
     */
    public static function request_uri()
    {
        return $_SERVER['REQUEST_URI'];
    }
    
    /**
     * Returns base directory of FreenetIS
     * 
     * This function is based on current path of this file, please do not
     * relocate this file!!
     * 
     * @author Ondřej Fibich
     * @return string
     */
    public static function base_dir()
    {
        return dirname(dirname(dirname(__FILE__)));
    }
}
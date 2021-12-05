<?php
/**
 * ApiRequest class for request header parsing
 * 
 * @author Jaromír Hradil
 */
class ResponseBuilder
{
    /** RESPONSE CODES */
    const CODE_OK = 200;
    const CODE_OK_NO_CONTENT = 204;
    const CODE_BAD_REQUEST = 400;
    const CODE_UNAUTHORIZED = 401;
    const CODE_FORBIDDEN = 403;
    const CODE_NOT_FOUND = 404;

    /**
     * Method for creating HTTP 400 response code and error message
     * 
     * @param string $err_text - OPTIONAL
     * 
     * @author 
     */
    public static function bad_request($err_text = NULL)
    {
        http_response_code(self::CODE_BAD_REQUEST);
        if($err_text)
        {
            echo('400 Bad Request: '. $err_text);
        }
        die;
    }

    /**
     * 
     * Method for creating HTTP 401 response code and error message
     * 
     * @param string $err_text - OPTIONAL
     * 
     * @author Jaromír Hradil
     */
    public static function unauthorized($err_text = NULL)
    {
        http_response_code(self::CODE_UNAUTHORIZED);
        if($err_text)
        {
            echo('401 Unauthorized: '.$err_text);
        }
        die;
    }

    /**
     * 
     * Method for creating HTTP 403 response code and error message
     * 
     * @param string $err_text - OPTIONAL
     * 
     * @author Jaromír Hradil
     */
    public static function forbidden($err_text = NULL)
    {
        http_response_code(self::CODE_FORBIDDEN);
        if($err_text)
        {
            echo('403 Forbidden: '.$err_text);
        }
        die;        
    }  

    /**
     * Method for creating HTTP 404 response code and error message
     * 
     * @param string $err_text - OPTIONAL
     * 
     * @author Jaromír Hradil
     */
    public static function not_found($err_text = NULL)
    {
        http_response_code(self::CODE_NOT_FOUND);
        if($err_text)
        {
            echo('404 Not Found: '.$err_text);
        }
        die;
    } 

}
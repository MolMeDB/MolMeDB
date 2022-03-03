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
    const CODE_SERVER_ERROR = 500;

    /**
     * Method for creating HTTP 400 response code and error message
     * 
     * @param string $err_text - OPTIONAL
     * 
     * @author Jaromír Hradil 
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
     * Method for creating HTTP 200 response 
     * 
     * @param string $response
     * @param array|string $header_content
     * 
     * @author Jaromír Hradil
     */
    public static function ok($response, $header_content = [])
    {
        if(!is_array($header_content))
        {
            $header_content = [$header_content];
        }

        foreach($header_content as $hc)
        {
            header($hc);
        }

        http_response_code(self::CODE_OK);

        if($response && is_string($response))
        {
            echo($response);
        }
        die;
    }

    /**
     * 
     * Method for creating HTTP 200 response code and error message
     * 
     * @author Jaromír Hradil
     */
    public static function ok_no_content()
    {
        http_response_code(self::CODE_OK_NO_CONTENT);
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

    /**
     * Method for creating HTTP 500 response code and error message
     * 
     * @param string|Exception $err_text - OPTIONAL
     *  
     * @author Jaromír Hradil
     */
    public static function server_error($err_text = NULL)
    {
        if($err_text instanceof Exception)
        {
            $err_text = $err_text->getMessage();
        }

        http_response_code(self::CODE_SERVER_ERROR);
        if($err_text && DEBUG)
        {
            echo('500 Server error: Internal error occured. ');
            echo $err_text;
        }
        //TODO logging of errors
        die;
    } 

}
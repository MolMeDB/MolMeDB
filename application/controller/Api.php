<?php

/**
 * API controller
 */
class ApiController extends Controller 
{

    /** RESPONSE CODES */
    const CODE_OK = 200;
    const CODE_OK_NO_CONTENT = 204;
    const CODE_BAD_REQUEST = 400;
    const CODE_UNAUTHORIZED = 401;
    const CODE_FORBIDDEN = 403;
    const CODE_NOT_FOUND = 404;
    
    /** Class variables */
    protected $user;
    protected $request_method;
    protected $parsed_request;
    protected $api_endpoint;

    private $requested_endpoint;
    private $requested_function;

    /**
     * Main constructor
     */
    function __construct($requested_endpoint = NULL, $requested_function = NULL)
    {
        parent::__construct();
        //Parsing request header
        $this->parsed_request = new HeaderParser();
        // Get request method
        $this->request_method = $_SERVER['REQUEST_METHOD'];

        $this->requested_endpoint = $requested_endpoint;
        $this->requested_function = $requested_function;
    }

    /**
     * Return server answer to request
     * 
     * @param string $message
     * @param integer $code
     * 
     */
    protected function answer($message, $code = self::CODE_OK)
    {
        if((!$message || $message == '') && $code == self::CODE_OK)
        {
            $code = self::CODE_OK_NO_CONTENT;
        }

        http_response_code($code);
        header('Content-Type: application/json');

        if($message || is_array($message))
        {
            echo(json_encode($message));
        }
        else
        {
            echo($code);
        }
        die;
    }

    /**
     * Sends BAD REQUEST shortcut
     */
    protected function bad_request()
    {
        return $this->answer(NULL, SELF::CODE_BAD_REQUEST);
    }

    /**
     * Main function for processing request
     */
    public function parse() 
    {   
        //Parsing requested endpoint
        $this->api_endpoint = new ApiEndpoint($this->requested_endpoint, $this->requested_function);
        //Calling parsed endpoint and its params
        $this->api_endpoint->call();
    }
    

    
    protected function remove_empty_values($arr = array(), $recursive = false)
    {
        if(!is_array($arr))
        {
            if(!strval($arr) !== "0" && !$arr)
            {
                return NULL;
            }
            else
            {
                return $arr;
            }
        }

        for($i = 0; $i < count($arr); $i++)
        {
            if($recursive && is_array($arr[$i]))
            {
                $arr[$i] = self::remove_empty_values($arr[$i]);
            }
            
            $arr[$i] = trim($arr[$i]);
            
            if($arr[$i] === '' || $arr[$i] === NULL)
            {
                unset($arr[$i]);
            }
        }
        
        return $arr;
    }
    
}
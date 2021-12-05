<?php

/**
 * API controller
 */
class ApiController extends Controller 
{
    /** Class variables */
    private $endpoint;
    private $function;
    protected $user;
    protected $request_method;
    protected $parsed_request;
    protected $endpoint_info;

    /**
     * Main constructor
     */
    function __construct($endPoint = NULL, $function = NULL)
    {
        parent::__construct();
        //Parsing request header
        $this->parsed_request = new HeaderParser();

        $this->endpoint_info = new ApiEndpoint($endPoint, $function);
        
        // Get request method
        $this->request_method = $_SERVER['REQUEST_METHOD'];
        $this->endpoint = $endPoint;
        $this->function = $function;
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
        try
        {
            $file = ucwords($this->endpoint);
            $className = "Api" . $file;
            $func = $this->function;

            if(file_exists(APP_ROOT . 'controller/Api/' . $file . '.php'))
            {
                $class = new $className();

                if(!method_exists($class, $func))
                {
                    throw new Exception();
                }

                // Get function setting and get required params
                $params = $this->check_validity_endpoint($className, $func);

                $class->$func(...$params);
            }
            else
            {
                $this->answer(NULL, self::CODE_NOT_FOUND);
            }
        }
        catch(Exception $ex)
        {
            $this->answer($ex->getMessage(), self::CODE_NOT_FOUND);
        }
    }
    
    /**
     * Checks if request if valid 
     * by given method params
     * 
     * @param string $class_name
     * @param string $function
     * 
     * @throws Exception
     * @return array
     */
    private function check_validity_endpoint($class_name, $function)
    {
        $rf_method = new ReflectionMethod($class_name, $function);

        // Split by "*" char
        $rf = array_map('trim', explode('*', $rf_method->getDocComment()));
        // Filter comments
        $rf = array_filter($rf, array($this, 'filter_comments'));

        $required_method = NULL;

        // Get required method
        foreach($this->valid_request_methods as $method)
        {
            foreach($rf as $key => $row)
            {
                if ($row == "@$method") 
                {
                    $required_method = $method;
                    unset($rf[$key]);
                    break;
                }
            }
        }

        if(!$required_method || $required_method != $this->request_method)
        {
            throw new Exception();
        }

        $remote_params = array();

        if($required_method === self::METHOD_GET)
        {
            $remote_params = $_GET;
        }
        else if($required_method === self::METHOD_POST)
        {
            $remote_params = $_POST;
        }

        $params = array();

        // Get required params
        foreach($rf as $row)
        {
            if(substr($row, 0, 6) != '@param')
            {
                continue;
            }

            $key = explode(" ", trim(str_replace('@param', '', $row)))[0];

            if(!isset($remote_params[$key]))
            {
                $value = NULL;
            }
            else
            {
                $value = $this->remove_empty_values($remote_params[$key]);
            }

            $params[] = $value;
        }

        return $params;
    }


    /**
     * Callback function for filtering comments
     * 
     * @param string $string
     * 
     * @return boolean
     */
    private function filter_comments($string)
    {
        $string = trim($string);

        if(substr($string, 0, 1) === '@')
        {
            return True;
        }

        return False;
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
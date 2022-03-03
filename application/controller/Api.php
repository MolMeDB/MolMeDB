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

    /** RESPONSE CODES */
    const CODE_OK = 200;
    const CODE_OK_NO_CONTENT = 204;
    const CODE_BAD_REQUEST = 400;
    const CODE_UNAUTHORIZED = 401;
    const CODE_FORBIDDEN = 403;
    const CODE_NOT_FOUND = 404;

    /** API ENDPOINTS */
    const COMPARATOR = 'comparator';
    const DETAIL = 'detail';
    const EDITOR = 'editor';
    const INTERACTIONS = 'interactions';
    const MEMBRANES = 'membranes';
    const METHODS   = 'methods';
    const PUBLICATION = 'publications';
    const S_ENGINE = 'searchEngine';
    const SMILES = 'smiles';
    const SETTINGS = 'settings';
    const STATS = 'stats';
    const UPLOADER = 'uploader';

    // Valid endpoints
    private $valid_endpoints = array
    (
        self::COMPARATOR,
        self::DETAIL,
        self::EDITOR,
        self::INTERACTIONS,
        self::MEMBRANES,
        self::METHODS,
        self::S_ENGINE,
        self::SETTINGS,
        self::SMILES,
        self::STATS,
        self::PUBLICATION,
        self::UPLOADER,
    );

    /** REQUEST METHODS */
    const METHOD_POST = 'POST';
    const METHOD_GET = 'GET';

    /** Now allowed just GET/POST requests */
    private $valid_request_methods = array
    (
        self::METHOD_GET,
        self::METHOD_POST
    );

    /**
     * Main constructor
     */
    function __construct($endPoint = NULL, $function = NULL)
    {
        parent::__construct();
        // If not valid entry
        if(!$endPoint || !$function)
        {
            $this->answer(NULL, self::CODE_NOT_FOUND);
        }

        // Is endpoint valid?
        if(!in_array($endPoint, $this->valid_endpoints))
        {
            $this->answer(NULL, self::CODE_NOT_FOUND);
        }

        // Get request method
        $this->request_method = $_SERVER['REQUEST_METHOD'];

        // Is request method valid?
        if(!in_array($this->request_method, $this->valid_request_methods))
        {
            $this->answer(NULL, self::CODE_BAD_REQUEST);
        }

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
    protected function answer($message, $code = self::CODE_OK, $type = 'json')
    {
        if((!$message || $message == '') && $code == self::CODE_OK)
        {
            $code = self::CODE_OK_NO_CONTENT;
        }

        http_response_code($code);

        switch($type)
        {
            case 'json':
                header('Content-Type: application/json');
                echo(json_encode($message));
                die;

            default:
                header('Content-Type: text/html');
                echo $message;
                die;
        }
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
    public function index() 
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

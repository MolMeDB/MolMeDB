<?php 

class ApiEndpoint
{

    /** Request Methods */
    const METHOD_POST = 'POST';
    const METHOD_GET = 'GET';

    //Supported content type
    const JSON = 'application/json';

    /** Endpoint function types */
    const ENDPOINT_INTERNAL = 'INTERNAL';
    const ENDPOINT_PRIVATE = 'PRIVATE';
    const ENDPOINT_PUBLIC = 'PUBLIC';

    private $allowed_methods = array
    (
       self::METHOD_GET,
       self::METHOD_POST
    );

    private $endpoint_function_types = array
    (
        self::ENDPOINT_INTERNAL,
        self::ENDPOINT_PRIVATE,
        self::ENDPOINT_PUBLIC
    );

    public $class_to_call = NULL;
    public $function_to_call = NULL;
    public $function_params = NULL;
    public $function_type = NULL;

    private static $loaded_endpoints = array();


    /*
    *  Constructor
    */
    public function __construct($requested_endpoint_path, $content_type=NULL)
    {
        //Checking if valid method and endpoint are used
        if(!$requested_endpoint_path)
        {
            ResponseBuilder::not_found('Endpoint not specified');
        }
        elseif(count($requested_endpoint_path) === 1)
        {
            ResponseBuilder::not_found('Method not specified');            
        }
        //Lowercasing endpoint for validation and error toleration
        if(!$this->check_valid_endpoint(strtolower($requested_endpoint_path[0])))
        {
            ResponseBuilder::not_found('Specified endpoint does not exist');
        }

        
        //Checking if endpoint was previously loaded
        $url_keeper = new URL_endpoint_keeper();
        $joined_path = strtolower(implode('/', $requested_endpoint_path));

        $loaded_endpoint = $url_keeper->find_endpoint($joined_path);

        if($loaded_endpoint)
        {
            try
            {   
                $this->get_loaded_function_params($loaded_endpoint, $content_type);
                return;
            }
            catch(ApiException $ex)
            {
                ResponseBuilder::bad_request($ex->getMessage());
            }
            catch(Exception $ex)
            {
                ResponseBuilder::server_error($ex->getMessage());
            }
        }                

        //Validating endpoint function and preparing it
        try
        {   
            $this->check_valid_function($requested_endpoint_path);
        }
        catch(ApiException $ex)
        {
            ResponseBuilder::bad_request($ex->getMessage());
        }
        catch(Exception $ex)
        {
            ResponseBuilder::server_error($ex->getMessage());
        }
    
    }




    public function call()
    {
        //Creating instance of requested class
        $this->class_to_call = new $this->class_to_call();
        //Calling requested function
        $function = $this->function_to_call;
        
        return $this->class_to_call->$function(...$this->function_params);
    }

    /**
    * Checks if requested endpoint really exists
    * 
    * @author Jaromir Hradil
    * 
    * @param string $endpoint_to_check
    * 
    * @return bool 
    */
    private function check_valid_endpoint($endpoint_to_check)
    {
        $dir = APP_ROOT . 'controller/Api';
        //Getting valid endpoints dynamically
        foreach (new DirectoryIterator($dir) as $file) 
        {
            if($file->isDot())
            {
                continue;
            }

            $existing_endpoint = $file->getFilename();
            $existing_endpoint = strtolower(explode('.', $existing_endpoint)[0]);  

            if($existing_endpoint == $endpoint_to_check)
            {
                return true;
            }   
        }

        return false;
    }

    /**
    * Checks if requested function really exists
    * 
    * @param string $endpoint_path
    * 
    * @throws Exception
    * @throws ApiException
    * 
    */
    private function check_valid_function($endpoint_path)
    {
        try
        {
            $file = ucwords($endpoint_path[0]);
            $class_name = 'Api' . $file;
            $joined_path = strtolower(implode('/', $endpoint_path));

            if(file_exists(APP_ROOT . 'controller/Api/' . $file . '.php'))
            {
                $this->class_to_call = $class_name;
                $class_functions = get_class_methods($this->class_to_call);

                foreach($class_functions as $class_function_item)
                {
                    $rf_method = new ReflectionMethod($this->class_to_call, $class_function_item);
                    // Split by "*" char
                    $rf = array_map('trim', explode('*', $rf_method->getDocComment()));
                    // Filter comments
                    $rf = array_filter($rf, array($this, 'filter_comments'));

                    $path_param_counter = 0;

                    foreach($rf as $row)
                    {   
                        //Checking Path(...) param
                        if(preg_match('/^\s*@Path\s*\(\s*\S+\s*\)\s*$/i', $row))
                        {   
                            $path_param_counter++;
                        }
                    }

                    if($path_param_counter === 0)
                    {
                        continue;
                    }
                    elseif($path_param_counter > 1) 
                    {
                        throw new Exception(sprintf('Multiple path parameters detected at %s function', 
                                                                                    $class_function_item));
                    }

                    foreach($rf as $row)
                    {   
                        //Checking Path(...) param
                        if(preg_match('/^\s*@Path\s*\(\s*\S+\s*\)\s*$/i', $row))
                        {   //Replacing Path() to get inner value 
                            $path_param = strtolower(preg_replace('/^\s*@Path\s*\(\s*|\s*\)\s*$/i', '', $row));

                            if($path_param == $joined_path)
                            {
                                $this->function_to_call = $class_function_item;
                                $this->get_function_params($rf, $joined_path, $content_type);
                                return;
                            }
                        }
                    }              
                }
                ResponseBuilder::bad_request('Requested function does not exist');
            }
            else
            {
                throw new ApiException("File with requested endpoint does not exist");
            }

        }
        catch(ApiException $ex)
        {
            ResponseBuilder::not_found($ex->getMessage());
        }
        catch(Exception $ex)
        {
            ResponseBuilder::server_error($ex->getMessage());
        }
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

        if(preg_match('/^\s*@/', $string))
        {
            return True;
        }

        return False;
    }


    /**
    *Prepares and checks loaded endpoint
    * 
    * @param array $endpoint_details
    * 
    * @throws Exception
    * @throws ApiException
    * 
    */
    private function get_loaded_function_params($endpoint_details, $content_type)
    {
        /*
        $endpoint_details = array
        (
            'params' => array(...),
            'function_type' => ...,
            'required_method' => ...,
            'class_name' => ...,
            'function_name' => ...,
        );*/

        $this->class_to_call = $endpoint_details['class_name'];
        $this->function_to_call = $endpoint_details['function_name'];

        $request_method = Server::request_method(); 

        if($endpoint_details['required_method'] != $request_method)
        {
            throw new ApiException(sprintf("Requested endpoint method requires HTTP %s method but HTTP %s 
                method was used instead", $endpoint_details['required_method'], $request_method));
        }

        $this->function_type = $endpoint_details['function_type'];
       
        $remote_params = array();

        if($endpoint_details['required_method'] === self::METHOD_GET)
        {
            $remote_params = $_GET;
        }
        else if($endpoint_details['required_method'] === self::METHOD_POST)
        {
            if($content_type == self::JSON)
            {
                $remote_params = file_get_contents('php://input');
                
                if($remote_params === false || !is_string($remote_params))
                {
                    throw new ApiException("Couldn't access JSON POST input");
                }

                $remote_params = json_decode($remote_params, true);

                if(!$remote_params)
                {
                    ResponseBuilder::bad_request("Invalid JSON post data");
                }
            }
            else
            {
               $remote_params = $_POST; 
            }
        }

        $remote_params = array_change_key_case($remote_params, CASE_LOWER);
        $params = array();

        //Checking method params
        foreach($endpoint_details['params'] as $param)
        {
            $key = $param['name'];
            $value = NULL;
            $required =  $param['required'];

            if(!isset($remote_params[$key]))
            {
                if($required)
                {
                    ResponseBuilder::bad_request(sprintf("Parameter %s is required", $key));                
                }
                else
                {
                    $value = NULL;
                }
            }
            else
            {
                $value = $this->remove_empty_values($remote_params[$key]);
            }

            $params[] = $value;
        }

        $this->function_params = $params;
    }



    /**
    *Gets parameters and function type
    * 
    * @param array $rf
    * @param string $endpoint_path
    * 
    * @throws Exception
    * @throws ApiException
    * 
    */
    private function get_function_params($rf, $endpoint_path, $content_type)
    {
        $endpoint_details = array
        (
            'params' => array(),
            'function_type' => NULL,
            'required_method' => NULL,
            'class_name' => $this->class_to_call,
            'function_name' => $this->function_to_call,
        );

        $request_method = Server::request_method(); 

        // Get required method
        foreach($this->allowed_methods as $method)
        {
            foreach($rf as $key => $row)
            {
                if(preg_match('/^\s*@'.$method.'/', $row)) 
                {
                    if(!$endpoint_details['required_method'])
                    {
                        $endpoint_details['required_method'] = $method;    
                    }
                    else
                    {
                        throw new ApiException("Multiple HTTP request types defined");
                    }
                }
            }
        }
        //Throwing exception here if 
        if(!$endpoint_details['required_method'])
        {
            throw new Exception("Required request method parameter is not specified");
        }
        elseif($endpoint_details['required_method'] != $request_method)
        {
            throw new ApiException(sprintf("Requested endpoint method requires HTTP %s method but HTTP %s 
                method was used instead", $endpoint_details['required_method'], $request_method));
        }

        //Array for possible multiple values handling
        $function_type = array();

        //Get function type tu determine who can access it
        foreach($rf as $row)
        {
            if(preg_match('/^\s*@INTERNAL\s*/i', $row))
            {
                $function_type[] = self::ENDPOINT_INTERNAL;
            }
            elseif(preg_match('/^\s*@PRIVATE\s*/i', $row))
            {
                $function_type[] = self::ENDPOINT_PRIVATE;
            }
            elseif(preg_match('/^\s*@PUBLIC\s*/i', $row))
            {
                $function_type[] = self::ENDPOINT_PUBLIC;
            }
        }

        //More function types error check
        if(count($function_type) > 1)
        {
           throw new ApiException("Multiple function types found, need to specify only one or none");
        }
        else
        {
            if(count($function_type) === 0)
            {
                $endpoint_details['function_type'] = self::ENDPOINT_PUBLIC;
            }
            else
            {
                $endpoint_details['function_type'] = $function_type[0];
            }

            $this->function_type = $endpoint_details['function_type'];
        }

        $remote_params = array();

        if($endpoint_details['required_method'] === self::METHOD_GET)
        {
            $remote_params = $_GET;
        }
        else if($endpoint_details['required_method'] === self::METHOD_POST)
        {
            $remote_params = $_POST;

            if($content_type == self::JSON)
            {
                $remote_params = json_decode($remote_params, true);
                
                if(!$remote_params)
                {
                    ResponseBuilder::bad_request("Invalid JSON post data");
                }
            }
        }

        $remote_params = array_change_key_case($remote_params, CASE_LOWER);
        $params = array();

        //Checking method params
        foreach($rf as $row)
        {
            if(!preg_match('/^\s*@param/i', $row))
            {
                 continue;
            }

            $key = NULL;
            $value = NULL;
            $required = false;

            //Checking "@param @REQUIRED <parameter>" syntax
            if(preg_match('/^\s*@param\s+@REQUIRED\s+\$\S+/i', $row))
            {
                $required = true;
            } 
           

            //Getting name of parameter
            $key = preg_replace('/^\s*(@param\s+@REQUIRED|@param)\s*/i', '', $row);
            preg_match('/^\s*\$\S+\s*/i', $key, $key);
            //Editing name for comparison
            $key = strtolower(trim($key[0]));
            $key = preg_replace('/\$/', '', $key);

            if(!isset($remote_params[$key]))
            {
                if($required)
                {
                    ResponseBuilder::bad_request(sprintf("Parameter %s is required", $key));                
                }
                else
                {
                    $value = NULL;
                }
            }
            else
            {
                $value = $this->remove_empty_values($remote_params[$key]);
            }

            $params[] = $value;
            $endpoint_details['params'][] = array
                                            (
                                              'name' => $key,  
                                              'required' => $required
                                            );
        }

        
        $this->function_params = $params;
        
        //Saving loaded endpoint for possible later use
        $url_keeper = new URL_endpoint_keeper();
        $url_keeper->add_new_endpoint($endpoint_path, $endpoint_details); 
    }



    /**
    * Removes empty values
    * 
    * @param array $arr
    * @param bool $recursive
    * 
    * @return array
    */
    private function remove_empty_values($arr = array(), $recursive = false)
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




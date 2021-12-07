<?php 

class ApiEndpoint
{

    /** Request Methods */
    const METHOD_POST = 'POST';
    const METHOD_GET = 'GET';

    private $allowed_methods = array
    (
       self::METHOD_GET,
       self::METHOD_POST
    );

    private $class_to_call = NULL;
    private $function_to_call = NULL;
    private $function_params = NULL;


    /*
    *  Constructor
    */
    public function __construct($requested_endpoint, $requested_function)
    {
        //Checking if valid method and endpoint are used
        if(!$requested_endpoint)
        {
            ResponseBuilder::not_found('Endpoint not specified');
        }
        elseif(!$requested_function)
        {
            ResponseBuilder::not_found('Funtion not specified');            
        }
        elseif(!$this->check_valid_endpoint($requested_endpoint))
        {
            ResponseBuilder::not_found('Specified endpoint does not exist');
        }

        $this->check_valid_function($requested_endpoint, $requested_function);
       
        try
        {   
            $this->get_function_params($this->class_to_call,$requested_function);
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
        $this->class_to_call->$function(...$this->function_params);
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
    * @param string $endpoint
    * @param string $function_to_check
    * 
    * @return bool 
    */
    private function check_valid_function($endpoint, $function_to_check)
    {
        try
        {
            $file = ucwords($endpoint);

            $className = "Api" . $file;
            $func = $function_to_check;

            if(file_exists(APP_ROOT . 'controller/Api/' . $file . '.php'))
            {
                $this->class_to_call = $className;

                if(!method_exists($this->class_to_call, $func))
                {
                    throw new ApiException("Specified method does not exist");
                }

            }
            else
            {
               return false;
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

        $this->function_to_call = $func;
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
    private function get_function_params($class_name, $function)
    {
        $rf_method = new ReflectionMethod($class_name, $function);

        // Split by "*" char
        $rf = array_map('trim', explode('*', $rf_method->getDocComment()));
        // Filter comments
        $rf = array_filter($rf, array($this, 'filter_comments'));

        $request_method = Server::request_method(); 
        $required_method = NULL;

        // Get required method
        foreach($this->allowed_methods as $method)
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
        //Throwing exception here if 
        if(!$required_method)
        {
            throw new Exception("Required request method parameter is not specified");
        }
        elseif($required_method != $request_method)
        {
            throw new ApiException("Required method parameter is same as request method");
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

        //Checking method params
        foreach($rf as $row)
        {
            if(substr($row, 0, 6) != '@param')
            {
                continue;
            }

            $param_details = explode(" ", trim(str_replace('@param', '', $row)));
            $possible_empty_value = true;



            while($possible_empty_value)
            {
                $idx = array_search('', $param_details);

                if($idx !== false) 
                {
                    unset($param_details[$idx]);
                }
                else
                {
                    $possible_empty_value = false; 
                }

            }


            if(count($param_details) != 1 && count($param_details) != 2)
            {
                ResponseBuilder::server_error("Params identifiers are unformatted");
            }


            $key = NULL;
            $value = NULL;
            $required = false;

            switch (count($param_details)) 
            {
                case 1:
                    $key = $param_details[0];
                    break;
                
                case 2:
                    if(substr($param_details[0], 0, 9) != '@REQUIRED')
                    {
                        ResponseBuilder::server_error(sprintf("Program expected @REQUIRED identifier but found %s", 
                            $param_details[0]));
                    }
                    else
                    {
                        $required = true;
                        $key = $param_details[1];
                    }
                    break;
            }

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




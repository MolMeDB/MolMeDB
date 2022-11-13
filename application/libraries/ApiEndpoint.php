<?php 

class ApiEndpoint
{
    /** Endpoint function types */
    const ENDPOINT_INTERNAL = 'INTERNAL';
    const ENDPOINT_PRIVATE = 'PRIVATE';
    const ENDPOINT_PUBLIC = 'PUBLIC';

    private $endpoint_access_types = array
    (
        self::ENDPOINT_INTERNAL,
        self::ENDPOINT_PRIVATE,
        self::ENDPOINT_PUBLIC
    );

    private $class_to_call = NULL;
    private $function_to_call = NULL;
    private $function_params = NULL;
    private $access_types = NULL;
    private $required_method;
    private $requested_path;

    /** @var HeaderParser */
    private $request;

    /**
     * Constructor
     * 
     * @param HeaderParser $request
     */
    public function __construct($request)
    {
        $this->requested_path = $request->requested_endpoint;
        $this->request = $request;

        $this->requested_path = array_map('trim', array_map('strtolower', $this->requested_path));
        
        //Checking if valid method and endpoint are used
        if(!$this->requested_path || count($this->requested_path) < 2)
        {
            ResponseBuilder::not_found('Requested endpoint was not found.');
        }

        //Lowercasing endpoint for validation and error toleration
        if(!$this->check_valid_endpoint($this->requested_path[0]))
        {
            ResponseBuilder::not_found('Specified endpoint does not exist');
        }

        $path = $this->requested_path;
        $path = implode("/", $path);

        //Checking if endpoint was previously loaded
        $endpoint_parser = new Api_endpoint_parser();
        $loaded_endpoint = $endpoint_parser->find($path, $this->request->method_type);

        if(!$loaded_endpoint)
        {
            ResponseBuilder::not_found();
        }

        $this->access_types = $loaded_endpoint['access_types'];

        // Check access
        $access = false;

        if(in_array(ApiEndpoint::ENDPOINT_PUBLIC, $this->access_types))
        {
            $access = true;
        }
        else if(in_array(ApiEndpoint::ENDPOINT_PRIVATE, $this->access_types))
        {
            // Check, if user is logged in
            if(session::is_admin())
            {
                $access = true;
            }
            else
            {
                $token = $this->request->auth_token;

                if(!$token)
                {
                    header("WWW-Authenticate: " . "Basic realm=\"MolMeDB Internal request\"");
                    header("HTTP/1.0 401 Unauthorized");
                    echo 'Invalid username or password.';
                }

                $user = Users::instance()->where(array
                    (
                        'token' => $token
                    ))->get_one();

                if(!$user->id)
                {
                    ResponseBuilder::unauthorized();
                }

                $access = true;
            }
        }

        if(!$access && in_array(ApiEndpoint::ENDPOINT_INTERNAL, $this->access_types))
        {
            $token = $this->request->auth_token;
            $server_token = Config::get('api_access_token');
            $access = ($token && $token === $server_token);

            if(!$access)
            {
                if(!$token)
                {
                    header("WWW-Authenticate: " . "Basic realm=\"MolMeDB Internal request\"");
                    header("HTTP/1.0 401 Unauthorized");
                    echo 'Invalid username or password.';
                }
                else // If not match server token, try admin login info
                {
                    if(!$this->request->auth_username || !$this->request->auth_password)
                    {
                        ResponseBuilder::unauthorized();
                    }

                    $pass = Users::return_fingerprint($this->request->auth_password);
                    $user = Users::instance()->where(array
                        (
                            'name' => $this->request->auth_username,
                            'password' => $pass
                        ))->get_one();

                    if(!$user->id)
                    {
                        $user = Users::instance()->where(array
                        (
                            'token' => $this->request->auth_token
                        ))->get_one();
                        
                        if(!$user->id)
                        {
                            ResponseBuilder::unauthorized();
                        }
                    }

                    $admin = Db::instance()->queryOne('
                        SELECT * FROM gp_usr
                        WHERE id_user = ? AND id_group = ?', 
                        array($user->id, 1));

                    if(!$admin->id_group) // Must be admin
                    {
                        ResponseBuilder::unauthorized();
                    }

                    $access = true;
                }
            }

            if(!$access && !$server_token)
            {
                ResponseBuilder::server_error('Internal requests are not currently supported.');
            }
        }

        if(!$access)
        {
            ResponseBuilder::unauthorized();
        }

        try
        {   
            $this->load_endpoint_params($loaded_endpoint);
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



    /**
    * Calls requested endpoint
    * 
    * @return content of endpoint
    */
    public function call()
    {
        //Creating instance of requested class
        $this->class_to_call = new $this->class_to_call();
        $this->class_to_call->parsed_request = $this->request;
        //Calling requested function
        $function = $this->function_to_call;

        return $this->class_to_call->$function(...array_values($this->function_params));
    }

    /**
     * Returns api endpoint responses
     * 
     * @return array
     */
    public function responses()
    {
        if(!$this->class_to_call)
        {
            return;
        }

        return $this->class_to_call->responses;
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
    * Prepares and checks loaded endpoint
    * 
    * @param array $endpoint_details
    * 
    * @throws Exception
    * @throws ApiException
    * 
    */
    private function load_endpoint_params($endpoint_details)
    {
        $this->class_to_call = $endpoint_details['class_name'];
        $this->function_to_call = $endpoint_details['function_name'];

        $r = new ReflectionMethod($this->class_to_call, $this->function_to_call);
        $method_params = $r->getParameters();

        $remote_params = $this->request->content;
        $remote_params = array_change_key_case($remote_params, CASE_LOWER);

        $uri_params = $endpoint_details['uri_params'];
        $params = [];

        foreach($endpoint_details['path_params'] as $p)
        {
            $params[$p['name']] = array_shift($uri_params);
        }

        foreach($endpoint_details['params'] as $param)
        {
            $key = $param['name'];
            $value = NULL;;
            $required =  $param['required'];

            if(isset($params[$key]) && $params[$key])
            {
                continue;
            }

            if(!isset($remote_params[$key]))
            {
                if(isset($param['default']))
                {
                    $value = $param['default'];
                }
                else if($required)
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
                $value = arr::remove_empty_values($remote_params[$key]);
            }

            $params[$key] = $value;
        }

        // reorder by method specification
        $final_params = [];

        foreach($method_params as $p)
        {
            $name = $p->name;

            if(!array_key_exists($name, $params))
            {
                if(DEBUG)
                {
                    throw new Exception('Method parameters count doesn\'t match endpoint specified parameters.');
                }

                ResponseBuilder::server_error("Invalid endpoint definition.");
            }

            $final_params[$name] = $params[$name];
        }
        
        $this->function_params = $final_params;
    }
}   

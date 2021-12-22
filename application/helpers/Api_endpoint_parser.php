<?php 

class Api_endpoint_parser
{
    // Path to file with access points
	const URL_ENDPOINT_KEEPER_PATH = '/tmp/api_endpoints';

    /**
     * Allowed access definitions
     */
    const ACCESS_PRIVATE =  'PRIVATE';
    const ACCESS_PUBLIC =   'PUBLIC';
    const ACCESS_INTERNAL = 'INTERNAL';

    /**
     * 
     */
    private static $access_types = array
    (
        self::ACCESS_PRIVATE,
        self::ACCESS_PUBLIC,
        self::ACCESS_INTERNAL
    );

	/** 
	* Constructor
	* @author Jaromir Hradil
	*/
	function __construct()
	{
		if(!file_exists(self::URL_ENDPOINT_KEEPER_PATH))
		{
			$res = file_put_contents(self::URL_ENDPOINT_KEEPER_PATH, json_encode(array()));
		
			if($res === false)
			{
				throw new Exception("Failed to write content into URL keeper file.");			
			}
		}
	}

	/**
    * Adds new endpoint into temporary file
	* 
	* @param string $endpoint_path
	* @param array $endpoint_params
	* 
	* @author Jaromir Hradil
	*/
	private function add_new_endpoint($data)
	{		
		$content = file_get_contents(self::URL_ENDPOINT_KEEPER_PATH);

		if($content === false)
		{
			throw new Exception("Failed to open URL keeper file");
		}

		$endpoints = json_decode($content, true);

		if($endpoints === NULL)
		{
			throw new Exception("Failed to decode content of URL keeper file");
		}		
		
        $endpoint_path = $data['method'] . "@" . $data['path'];

		$endpoints[$endpoint_path] = $data;
		
		$res = file_put_contents(self::URL_ENDPOINT_KEEPER_PATH, json_encode($endpoints));
		
		if($res === false)
		{
			throw new Exception("Failed to write content into URL keeper file");			
		}
	}

	/**
    * Checks if endpoint exists in loaded endpoints
	* 
	* @param string $endpoint
	* @param bool $fast_check
    *
	* @author Jaromir Hradil
	*/
	public function find($endpoint, $method, $fast_check = FALSE)
	{
		$content = file_get_contents(self::URL_ENDPOINT_KEEPER_PATH);

		if($content === false)
		{
			throw new Exception("Failed to open URL keeper file");
		}

		$endpoints = json_decode($content, true);

		if($endpoints === NULL)
		{
			throw new Exception("Failed to decode content of URL keeper file");
		}
        
        $endpoint_path = $method . "@" . $endpoint;

		if(in_array($endpoint_path, array_keys($endpoints)))
		{
            $data = $endpoints[$endpoint_path];
            // Check for changes
            $r = $this->get_method_detail($data['class_name'], $data['function_name']);
            
            // Update
            if(!$r || $r['path'] !== $endpoint)
            {
                file_put_contents(self::URL_ENDPOINT_KEEPER_PATH, json_encode($endpoints));
                return NULL;
            }

            $endpoints[$endpoint_path] = $r;
            file_put_contents(self::URL_ENDPOINT_KEEPER_PATH, json_encode($endpoints));
			return $endpoints[$endpoint_path];
		}
		else if(!$fast_check)
		{
			$this->update_all();
            return $this->find($endpoint, $method, TRUE);
		}
        else
        {
            return NULL;
        }
	}

	/**
	 * Updates all endpoints info
	 * 
	 * @author Jakub Juracka, Jaromir Hradil
	 */
	public function update_all()
	{
		try
        {
            // At first, clear old definitions
            $res = file_put_contents(self::URL_ENDPOINT_KEEPER_PATH, json_encode([]));

            if($res === false)
            {
                throw new ApiException("Writing into URL keeper file failed");
            }

            $files = scandir(APP_ROOT . 'controller/Api/');

            foreach($files as $file)
            {
                if(preg_match("/^\.+/", $file))
                {
                    continue;
                }

                $class_name = 'Api' . preg_replace("/\.php$/", "", $file);

                if(!class_exists($class_name))
                {
                    continue;
                }

                $all_methods = get_class_methods($class_name);

                foreach($all_methods as $method)
                {
                    if($method == '__construct')
                    {
                        continue;
                    }

                    $detail = $this->get_method_detail($class_name, $method);

                    if(!$detail)
                    {
                        continue;
                    }

                    $this->add_new_endpoint($detail);
                }
            }
        }
        catch(ApiException $ex)
        {
            ResponseBuilder::server_error($ex->getMessage());
        }
        catch(Exception $ex)
        {
            ResponseBuilder::server_error($ex->getMessage());
        }
	}

    /**
     * Returns detail of given endpoint method
     * 
     * @param string $class_name
     * @param string $method
     * 
     * @return array
     */
    private function get_method_detail($class_name, $method)
    {
        if(!class_exists($class_name) || !method_exists($class_name, $method))
        {
            return NULL;
        }

        $rf_method = new ReflectionMethod($class_name, $method);
        // Split by "*" char
        $rf = array_map('trim', explode('*', $rf_method->getDocComment()));
        // Filter comments
        $rf = array_filter($rf, array($this, 'filter_comments'));

        $path = $params = $req_method = null;
        $access_types = [];

        foreach($rf as $row)
        {   
            //Checking Path(...) param
            if(preg_match('/^\s*@Path\s*\(\s*\S+\s*\)\s*$/i', $row))
            {   //Replacing Path() to get inner value 
                $path = strtolower(preg_replace('/^\s*@Path\s*\(\s*|\s*\)\s*$/i', '', $row));
                continue;
            }
            //Get function type to determine who can access it
            foreach(self::$access_types as $at)
            {
                if(preg_match('/^\s*@' . $at . '\s*/i', $row))
                {
                    $access_types[] = $at;
                    break;
                }
            }
            // Get allowed method
            foreach(HeaderParser::$allowed_methods as $m)
            {
                if(preg_match('/^\s*@'.$m.'/', $row))
                {
                    $req_method = $m;
                    break;
                }
            }
        }
        
        // Badly defined function
        if(!$path || !$req_method)
        {
            return null;
        }

        // Default access type
        if(empty($access_types))
        {
            $access_types[] = self::ACCESS_PUBLIC;
        }

        $params = $this->get_function_params($rf);

        // Save detail
        return array
        (
            'path'  => $path,
            'params' => $params,
            'access_types' => $access_types,
            'method' => $req_method,
            'class_name' => $class_name,
            'function_name' => $method,
        );
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
    *Gets parameters and function type
    * 
    * @param array $rf
    * @param string $endpoint_path
    * 
    * @throws Exception
    * @throws ApiException
    * 
    */
    private function get_function_params($rf)
    {
        $params = array();

        //Checking method params
        foreach($rf as $row)
        {
            if(!preg_match('/^\s*@param/i', $row))
            {
                 continue;
            }

            $key = NULL;
            $required = false;

            //Checking "@param @REQUIRED <parameter>" syntax
            if(preg_match('/^\s*@param\s+@REQUIRED\s+\$\S+/i', $row))
            {
                $required = true;
            } 

            //Getting name of parameter
            $key = preg_replace('/^\s*(@param\s+@REQUIRED|@param)\s*/i', '', $row);
            preg_match('/^\s*\$\S+\s*/i', $key, $key);

            if(!count($key))
            {
                continue;
            }

            //Editing name for comparison
            $key = strtolower(trim($key[0]));
            $key = preg_replace('/^\$/', '', $key);

            $params[] = array
            (
                'name' => $key,  
                'required' => $required
            );
        }

        return $params;
    }

}
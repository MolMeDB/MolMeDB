<?php 

class URL_endpoint_keeper
{

	const URL_ENDPOINT_KEEPER_PATH = '/tmp/MolMeDb_url_keeper';

	/*
	*Constructor
	* @author Jaromir Hradil
	*/
	function __construct()
	{
		if(!file_exists(self::URL_ENDPOINT_KEEPER_PATH))
		{
			$res = file_put_contents(self::URL_ENDPOINT_KEEPER_PATH, json_encode(array()));
		
			if($res === false)
			{
				throw new ApiException("Failed to write content into URL keeper file");			
			}
		}
	}

	/**
    * Adds new endpoint into temporary file
	* 
	* @param string $endpoint_path
	* @param array $endpoint_params
	* 
	*  @author Jaromir Hradil
	*/
	public function add_new_endpoint($endpoint_path, $endpoint_params)
	{		
		if($this->find_endpoint($endpoint_path))
		{
			return;
		}

		$content = file_get_contents(self::URL_ENDPOINT_KEEPER_PATH);

		if($content === false)
		{
			throw new ApiException("Failed to open URL keeper file");
		}

		$endpoints = json_decode($content, true);

		if($endpoints === NULL)
		{
			throw new ApiException("Failed to decode content of URL keeper file");
		}		
		
		$endpoints[$endpoint_path] = $endpoint_params;
		
		$res = file_put_contents(self::URL_ENDPOINT_KEEPER_PATH, json_encode($endpoints));
		
		if($res === false)
		{
			throw new ApiException("Failed to write content into URL keeper file");			
		}

	}
	/**
    * Checks if endpoint exists in loaded endpoints
	* 
	* @param string $endpoint_to_check
	* 
	* 
	* @author Jaromir Hradil
	*/
	public function find_endpoint($endpoint_to_check)
	{
		
		$content = file_get_contents(self::URL_ENDPOINT_KEEPER_PATH);

		if($content === false)
		{
			throw new ApiException("Failed to open URL keeper file");
		}

		$endpoints = json_decode($content, true);

		if($endpoints === NULL)
		{
			throw new ApiException("Failed to decode content of URL keeper file");
		}

		if(in_array($endpoint_to_check, array_keys($endpoints)))
		{
			return $endpoints[$endpoint_to_check];
		}
		else
		{
			return NULL;
		}
	}
}
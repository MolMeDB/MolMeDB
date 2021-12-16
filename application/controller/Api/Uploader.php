<?php

class ApiUploader extends ApiController
{    
	/**
	 * Constructor
	 */
	function __construct()
	{
		
	}


	/**
	 * Return file content for given URL
	 * 
	 * @GET
	 * @param fileName
	 * @param delimiter - Default is ';'
	 */
    public function getFile($file_name, $delimiter)
    {
		$file = fopen("./" . $file_name, 'r') or die ("Can't open file!");
		
		if(!$delimiter)
		{
			$delimiter = ';';
		}
		
		if(!$file)
		{
			die("Error with loading file!");
		}
		
		$data = array();
		
		while($row = fgets($file))
		{
			$row = str_replace(array("\n","\r\n","\r"), '', $row);
			$data[] = explode($delimiter, $row);
		}
		
		//$this->answer($data);
    }
}
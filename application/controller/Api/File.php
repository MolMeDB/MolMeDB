<?php

/**
 * 
 */
class ApiFile
{
    /**
	 * Return file content for given URL
	 * 
	 * @GET
     * @internal
	 * 
	 * @param @required $file_name
	 * @param @DEFAULT[;] $delimiter
	 * 
	 * @PATH(/content)
	 */
    public function getFileContent($file_name, $delimiter)
    {
		$file = fopen("./" . $file_name, 'r') or die ("Can't open file!");
		
		if(!$delimiter)
		{
			$delimiter = ';';
		}
		
		if(!$file)
		{
            ResponseBuilder::not_found('File not found.');
		}
		
		$data = array();
		
		while($row = fgets($file))
		{
			$row = str_replace(array("\n","\r\n","\r"), '', $row);
			$data[] = explode($delimiter, $row);
		}
		
		return $data;
    }
}
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

    /**
	 * Return file preview for given id
	 * 
	 * @GET
	 * 
	 * @param @required $id
	 * 
	 * @PATH(/preview/<id:\d+>)
	 */
    public function get_file_preview($id)
    {
		$file = new Files($id);
		
		if(!$file->id)
		{
            ResponseBuilder::not_found('File not found.');
		}
		
		$total = 10;

		$f = fopen($file->path, 'r');

		if(!$f)
		{
			ResponseBuilder::not_found('File not exists.');
		}

		$data = array();
		
		while($total-- && $row = fgets($f))
		{
			$row = str_replace(array("\n","\r\n","\r"), '', $row);
			$data[] = $row;
		}
		
		return $data;
    }
}
<?php

class ApiTest extends ApiController
{    

	/**
	 * Return file content for given URL
	 * 
	 * @GET
	 * @param testString
	 */
    public function check($test_string)
    {
    	echo 'Passed string: '.$test_string;
    	$this->answer("OK");
    }
}
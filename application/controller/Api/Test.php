<?php

class ApiTest extends ApiController
{    

	/**
	* Return file content for given URL
	* 
	* @GET
	* @param test_string
	* @param @REQUIRED test_string1
	* @param test_string2
	*/
    public function check($test_string, $test_string1, $test_string2)
    {
    	echo 'Passed string: '.$test_string;
    }



	/**
    * Return file content for given URL
	* 
	* @GET
	* @param test_string
	* @param test_string1
	* @param test_string2
	*/
    public function check2($test_string, $test_string1, $test_string2)
    {
    	echo 'Passed string: '.$test_string;
    }
}
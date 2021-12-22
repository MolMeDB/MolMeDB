<?php

class ApiTest extends ApiController
{    

	/**
	* Return file content for given URL
	* 
	* @POST
	* @param $test_string
	* @param @required $TeST_string1
	* @param $test_string2
	* 
	* @path(test/check)
	* 
	*/
    public function check($test_string = NULL, $test_string1, $test_string2 = NULL)
    {
    	$arra = array
		(
			array
			(
				'id' => $test_string2,
				'value' => $test_string1,
			),
			array
			(
				'id' => $test_string,
				'value' => 1,
			),
			array
			(
				'id' => '',
				'value' => array('dwdw')
			),
			array
			(
				'id' => 1,
				'value' => 1,
			),
		);

		$arra = new Iterable_object([
			'id' => 1,
			'ta' => 'awdawd'
		]);

		return $arra;
    }



	/**
    * Return file content for given URL
	* 
	* @GET
	* @param $est_string
	* @param $test_string1
	* @param $test_string2
	* 
	* @path(test/check)
	* 
	*/
    public function check2($test_string, $test_string1, $test_string2)
    {
		$arra1 = array
		(
			array('id','value','value2' ),
			array(1, 1,'mol'),
			array('id', 'value', NULL),
			array('id', 1,1),
		);
    
    		$arra1 = array
		(
			array('id','value','value2' ),
			array(1, 1,'mol'),
			array('id', 'value', NULL),
			array('id', 1,1),
		);

		return $arra1;
    }
}
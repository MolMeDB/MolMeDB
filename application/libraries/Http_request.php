<?php
/*
 * This file is part of open source system FreenetIS
 * and it is released under GPLv3 licence.
 *
 * More info about licence can be found:
 * http://www.gnu.org/licenses/gpl-3.0.html
 *
 * More info about project can be found:
 * http://www.freenetis.org/
 *
 */


/**
 * Class api request for getting info from remote server
 *
 * @author Jakub Juračka
 */
class Http_request
{       
	/* 
	* Curl
	*/
	private $curl;

	/*
	* Allowed methods
	*/
	const METHOD_POST = 1;
	const METHOD_GET = 2;

	/*
	* Variables for authorization 
	*/
	private $user;
	private $token;

	/* 
	* Address of remote server
	*/
	private $remote_address;

	/**
	* Initializes main variables
	*
	* @author Jakub Juračka
	*/
	public function __construct(
				$remote_address = NULL,
				$user = NULL,
				$token = NULL) 
	{
		$this->remote_address = rtrim($remote_address, '/') . '/';
		$this->user = $user;
		$this->token = $token;

		$this->curl = new Curl_HTTP_Client();

		$this->curl->set_headers(array('Content-Type: application/json'));

		if($user && $token)
		{
			$this->curl->set_credentials($this->user, $this->token);
		}
	}


	public function set_authorization_header($token)
	{
		$this->curl->set_headers(array('Content-Type: application/json', 'Authorization: ' .$token));
	}

	/**
	* Test connection to remote server
	*
	* @author Jakub Juračka
	* @return bool
	*/
	public function test_connection($uri = '')
	{
		$timeout = 10;
		$ch = curl_init();

		$remote_address = $this->remote_address . $uri;

		curl_setopt ( $ch, CURLOPT_URL, $remote_address);
		curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt ( $ch, CURLOPT_TIMEOUT, $timeout );

		$http_respond = curl_exec($ch);
		$http_respond = trim( strip_tags( $http_respond ) );
		$http_code = strval(curl_getinfo( $ch, CURLINFO_HTTP_CODE ));

		if ($http_code[0] !== "4" && $http_code[0] !== 5) 
		{
			return true;
		} 
		else 
		{
			throw new Exception('Cannot connect to remote server.');
		}

		curl_close( $ch );
	}


	/**
	* Send request
	*
	* @param $uri       URI of api endpoint
	* @param $method    HTTP method
	* 
	* return mixed      Response data from API
	*
	* @author Jakub Juračka  
	*/
	public function request($uri = NULL, $method = self::METHOD_GET, $data = array(), $include_response_header = FALSE, $timeout = 10)
	{
		$this->curl->include_response_headers($include_response_header);

		$url = $this->remote_address . ltrim($uri, '/');

		$url = rtrim($url, '/');

		// Set GET params
		if($method == self::METHOD_GET)
		{
			$url .= '?';

			foreach($data as $key => $val)
			{
				$url .= $key . '=' . urlencode($val) . '&';
			}

			$url = rtrim($url, '&');
		}

		switch($method)
		{
			case self::METHOD_GET:
				$result = $this->curl->fetch_url($url, NULL, $timeout);
				break;

			case self::METHOD_POST:
				$result = $this->curl->send_post_data($url, $data);
				break;

			default:
				throw new Exception('Invalid HTTP request method.');
		}

		if($result === FALSE)
		{
			throw new Exception($this->curl->get_error_msg());
		}

		return json_decode($result);
	}
        
}
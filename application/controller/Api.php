<?php

/**
 * API controller
 * 
 * Usage:
 * Api endpoints must be defined in corresponding files in ./Api/ folder.
 * For defining of the new endpoint, create class method and define following 
 * params in method documentation:
 *  - @[METHOD] - required param specifiing HTTP METHOD, e.g. @POST/@GET
 *  - @path([PATH]) - required param specifying uri of defined endpoint, e.g. @Path(/get/byId)
 *  - @param [OPTIONS] $[NAME] - optional param specifying parameter given by client for method execution
 *      - can be included multiple times (once for each parameter)
 *      - $[NAME] - name has to correspond to exactly one method parameter name
 *      - OPTIONS:
 *          - @required - specifies, that parameter has to be passed by client side
 *          - @default[VALUE] - specifies default value of given param
 *              - if DEFAULT is set, REQUIRED is ignored
 *              - VALUE must match following regexp [0-9,a-z,_,\,]+
 *              - if multiple values required, use "," char as sepearator
 */
class ApiController extends Controller 
{
    /** Class variables */
    protected $user;
    protected $request_method;
    public $parsed_request;
    protected $api_endpoint;
    private $encoded_response;

    private $headers = [];

    /** Holds different return type data */
    public $responses = [];

    /**
     * Main constructor
     */
    function __construct($requested_endpoint = NULL)
    {
        parent::__construct();
        //Parsing request header
        $this->parsed_request = new HeaderParser($requested_endpoint);
    }

    /**
     * Main function for processing request
     */
    public function index() 
    {   
        try
        {
            $this->api_endpoint = new ApiEndpoint($this->parsed_request);

            //Calling parsed endpoint and its params
            $res = $this->api_endpoint->call();

            if($res)
            {
                $this->responses = ['default' => $res];
            }
            else
            {
                $this->responses = $this->api_endpoint->responses();
            }

            //No content was found
            if(empty($this->responses))
            {
                ResponseBuilder::ok_no_content();
            }

            $this->create_response();

            if($this->encoded_response === null)
            {
                ResponseBuilder::ok_no_content();
            }
        }
        catch(MmdbException $ex)
        {
            ResponseBuilder::server_error($ex);
        }
        catch(Exception $e)
        {
            ResponseBuilder::server_error(new ApiException($e->getMessage(), '500 SERVER ERROR', $e->getCode(), $e));
        }
             
        ResponseBuilder::ok($this->encoded_response, $this->headers);
    }
    

    /**
    * Method for creating HTTP response  
    * 
    * @author Jakub Juracka
    *
    * @throws ApiException 
    */
    protected function create_response()
    {
        foreach ($this->parsed_request->accept_type as $at_item) 
        {

            if(array_key_exists($at_item, HeaderParser::$accept_type_method))
            {
                try
                {
                    $nn = '!@#$%!@$';

                    $to_encode = array_key_exists($at_item, $this->responses) ? $this->responses[$at_item] : (isset($this->responses['default']) ? $this->responses['default'] : $nn);

                    if($to_encode === $nn)
                    {
                        continue;
                    }

                    $response_method = HeaderParser::$accept_type_method[$at_item];
                    $response_encoded = $response_method($to_encode);

                    if($response_encoded !== false)
                    {
                        $this->encoded_response = $response_encoded;
                        $this->headers[] = 'Content-Type: '.$at_item;
                        return;
                    }
                }
                catch(Exception $e)
                {
                    continue;
                }
            }
        }

        // If no response
        if(in_array(HeaderParser::ALL_TYPES, $this->parsed_request->accept_type))
        {
            // Try all
            foreach(HeaderParser::$accept_type_method as $type => $method)
            {
                try
                {
                    $to_encode = isset($this->responses[$type]) ? $this->responses[$type] : (isset($this->responses['default']) ? $this->responses['default'] : null);

                    if(!$to_encode)
                    {
                        continue;
                    }

                    $response_encoded = $method($to_encode);

                    if($response_encoded !== false)
                    {
                        $this->encoded_response = $response_encoded;
                        $this->headers[] = 'Content-Type: '. $type;
                        return;
                    }
                }
                catch(Exception $e)
                {
                    continue;
                }
            }
        }

        throw new ApiException("Cannot encode output to the required format. Please, try to expand your `Accept` request header options.");
    }

    /**
     * Returns list of requested accept types called by user
     *
     * @return array
     */
    public function get_accept_list()
    {
        return $this->parsed_request->accept_type;
    }

    /**
     * Returns preffered return type from given allow list
     *
     * @param array $options
     *
     * @return int|null
     */
    public function get_preffered_accept_from_list($options)
    {
        foreach($this->parsed_request->accept_type as $at)
        {
            if(in_array($at, $options))
            {
                return $at;
            }
        }

        if(in_array(HeaderParser::ALL_TYPES, $this->parsed_request->accept_type) &&
            count($options))
        {
            return $options[0];
        }
        
        return null;
    }
}
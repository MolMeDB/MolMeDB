<?php

/**
 * API controller
 */
class ApiController extends Controller 
{
    //Accept types for response
    const JSON = 'application/json';
    const CSV = 'text/csv';

    //Iterable object constant
    const ITERABLE_OBJECT = 'Iterable_object';


    protected $accept_type_methods = array
    (
        self::JSON => 'json_response',
        self::CSV => 'csv_response',
    );


    /** Class variables */
    protected $user;
    protected $request_method;
    protected $parsed_request;
    protected $api_endpoint;

    protected $response;
    protected $header;

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
    public function parse() 
    {   


        //Parsing requested endpoint
        if($this->parsed_request->content_type == self::JSON)
        {
            $this->api_endpoint = new ApiEndpoint($this->parsed_request->requested_endpoint, self::JSON);
        }
        else
        {
            $this->api_endpoint = new ApiEndpoint($this->parsed_request->requested_endpoint);
        }
        
        try
        {
            //Calling parsed endpoint and its params
            $this->response = $this->api_endpoint->call();
            
            //No content was found
            if(!$this->response)
            {
                ResponseBuilder::ok_no_content();
            }
            elseif(is_a($this->response, self::ITERABLE_OBJECT))
            {
                $this->response = $this->response->as_array();
            }
            if(!is_array($this->response))
            {
                throw new ApiException("Expected array response");
            }

            $this->create_response();
        }
        catch(ApiException $ex)
        {
            ResponseBuilder::server_error($ex->getMessage());
        }
        catch(Exception $ex)
        {
            ResponseBuilder::server_error($ex->getMessage());
        }
             
        ResponseBuilder::ok($this->header, $this->response);
    }
    

    /**
    * Method for creating HTTP response  
    * 
    * @author Jaromír Hradil
    *
    * @throws ApiException 
    */
    protected function create_response()
    {          
        foreach ($this->parsed_request->accept_type as $accept_type_item) 
        {
            if(in_array($accept_type_item, array_keys($this->accept_type_methods)))
            {
                $response_method = $this->accept_type_methods[$accept_type_item];
                $response_encoded = ApiController::$response_method($this->response);

                if($response_encoded !== false)
                {
                    $this->response = $response_encoded;
                    $this->header = 'Content-Type: '.$accept_type_item;
                    return;
                }
            }
        }
        throw new ApiException("Failed to create response, no valid response type found");
    }

    /**
    * Method for creating JSON output
    * 
    * @author Jaromír Hradil
    * 
    * @return bool
    */
    public static function json_response($response)
    {
        return json_encode($response);
    } 

    /**
    * Method for creating CSV output
    * 
    * @author Jaromír Hradil
    * 
    * @return bool
    */
    public static function csv_response($response)
    {
        return ApiController::create_csv($response);
    } 



    /**
    * Method for creating CSV output
    * 
    * @param array $arr
    *  
    * @author Jaromír Hradil
    */
    protected static function create_csv($arr)
    {
        if(!is_array($arr) || count($arr) === 0)
        {
            return false;
        }

        $keys = array_keys($arr[0]);

        $csv_output = "";

        $keys = array_keys($arr[0]);

        for ($i=0; $i < count($keys); $i++)
        { 
            $keys[$i] = strval($keys[$i]);
        }

        $csv_output = $csv_output.implode(";", $keys).PHP_EOL;

        $keys = array_keys($arr[0]);


        foreach($arr as $arr_item)
        {
            $arr_item_keys = array_keys($arr_item);
            $csv_content_line = array();

            //Csv keys are not the same
            if($arr_item_keys != $keys)
            {
                return false;
            }
            else
            {
                foreach($arr_item as $arr_item_content)
                {
                    //There are nested elements
                    if(is_array($arr_item_content))
                    {
                        return false;
                    }
            
                    $csv_content_line[] = strval($arr_item_content);
                }
            }
            
            $csv_output = $csv_output.implode(";", $csv_content_line).PHP_EOL;
        }
        
        return $csv_output;
    }
    
}
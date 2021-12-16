<?php
/**
 * HeaderParser class for request header parsing
 * 
 * @author Jaromir Hradil
 */
class HeaderParser
{    
    /** Request Methods */
    const METHOD_POST = 'POST';
    const METHOD_GET = 'GET';

    /*Constants for formats*/
    const ALL_TYPES = '*/*';
    const JSON = 'application/json';
    const CSV = 'text/csv';
    const MULTIPART = 'multipart/form-data';
    const GZIP = 'gzip';
    const COMPRESS = 'compress';
    const DEFLATE = 'deflate';
    const UTF8 = 'utf-8';


    /** Default values for not specified headers*/
    const DEFAULT_ENCODING = self::GZIP;
    const DEFAULT_CHARSET = self::UTF8;
    const DEFAULT_ACCEPT = self::JSON;
    const DEFAULT_CONTENT = self::JSON;



    //Private values for validity checking
    private $allowed_methods = array
    (
       self::METHOD_GET,
       self::METHOD_POST
    );

    /**
     * Allowed values in Accept header
     * Order implicates prefered values
     */
    private $allowed_accept_types = array
    ( 
        self::CSV,
        self::ALL_TYPES,
        self::JSON 
    );

    /**
     * Allowed values in Accept-Encoding header
     * Order implicates prefered values
     */
    private $allowed_accept_encoding = array
    (
        self::GZIP, 
        self::DEFLATE, 
        self::COMPRESS
    );

    /**
     * Allowed values in Accept-Charset header
     * Order implicates prefered values
     */
    private $allowed_accept_charset = array
    (
        self::UTF8
    );

    /**
     * Allowed values in Content-Type header
     * Order implicates prefered values
     */
    private $allowed_content_type = array
    (
        self::JSON,
        self::MULTIPART
    );


    //Public values for ResponseBuilder
    public $requested_path = NULL;
    public $method_type = NULL;
    public $accept_type = NULL;
    public $accept_encoding = NULL;
    public $accept_charset = NULL;
    public $content_type = NULL;
    public $auth_creds = NULL;


    /**
    * Constructor
    */    
    public function __construct($requested_endpoint)
    {
        $this->requested_endpoint = $requested_endpoint;
        $this->parse_headers();
    }

    /**
    * Returns parsed request headers
    * 
    * @author Jaromir Hradil
    * 
    */
    private function parse_headers()
    {
        //Checking if allowed HTTP request method is used
        $this->method_type = Server::request_method();

        if(!in_array($this->method_type, $this->allowed_methods))
        {
            ResponseBuilder::bad_request('Only allowed request methods are: POST, GET');    
        }

        $accept_header_content = Server::http_accept();

        //Checking if valid accept content is used
        if(!$accept_header_content)
        {
            $this->accept_type = array(self::DEFAULT_ACCEPT);
        }
        else
        {
            $valid_accept_type = false;

            $accept_header_content = preg_split('/(,|;)/i', strtolower($accept_header_content), -1, PREG_SPLIT_NO_EMPTY);

            for($idx=0; $idx<count($accept_header_content); $idx++)
            {
                $accept_header_content[$idx] = trim($accept_header_content[$idx]);                
            }

            $this->accept_type = array();

            foreach($this->allowed_accept_types as $accept_type_item)
            {
                if(in_array($accept_type_item, $accept_header_content))
                {             
                    if($accept_type_item == self::ALL_TYPES)
                    {
                        $all_type_idx = array_search(self::ALL_TYPES, $this->allowed_accept_types);
                        $tmp_allowed_accept_types = $this->allowed_accept_types;
                        unset($tmp_allowed_accept_types[$all_type_idx]);

                        foreach ($tmp_allowed_accept_types as $tmp_item) 
                        {
                            $this->accept_type[] = $tmp_item;
                        }
                    }
                    else
                    {
                        $this->accept_type[] = $accept_type_item;  
                    }  
                    $valid_accept_type = true;                    
                }
            }
            if(!$valid_accept_type)
            {
                ResponseBuilder::bad_request('Supported Accept-Type values are : '.implode
                                                                                  (',', $this->allowed_accept_types));
            }   

            $this->accept_type = array_unique($this->accept_type);
        }


        $this->accept_encoding = Server::http_encoding();

        //Checking if valid encoding is used
        if(!$this->accept_encoding)
        {
            $this->accept_encoding = self::DEFAULT_ENCODING;
        }
        else
        {
            $valid_encoding_type = false;

            $this->accept_encoding = preg_split('/(,|;)/i', strtolower($this->accept_encoding), -1, PREG_SPLIT_NO_EMPTY);

            for($idx=0; $idx<count($this->accept_encoding); $idx++)
            {
                $this->accept_encoding[$idx] = trim($this->accept_encoding[$idx]);                
            }

            foreach($this->allowed_accept_encoding as $accept_encoding_item)
            {
                if(in_array($accept_encoding_item, $this->accept_encoding))
                {             
                    $valid_encoding_type = true;
                    $this->accept_encoding = $accept_encoding_item;         
                    break;
                }
            }
            if(!$valid_encoding_type)
            {
                ResponseBuilder::bad_request('Only supported Accept-Encodings types are : '.implode
                                                                                          (',', $this->allowed_accept_encoding));
            }   
        }

        $this->accept_charset = Server::http_charset();

        //Checking if valid accept charset is used
        if(!$this->accept_charset)
        {
            $this->accept_charset = self::DEFAULT_CHARSET;
        }
        else
        {
            
            $valid_charset_type = false;

            $this->accept_charset = preg_split('/(,|;)/i', strtolower($this->accept_charset), -1, PREG_SPLIT_NO_EMPTY);

            for($idx=0; $idx<count($this->accept_charset); $idx++)
            {
                $this->accept_charset[$idx] = trim($this->accept_charset[$idx]);                
            }

            foreach($this->allowed_accept_charset as $accept_charset_item)
            {
                if(in_array($accept_charset_item, $this->accept_charset))
                {             
                    $valid_charset_type = true;
                    $this->accept_charset = $accept_charset_item;         
                    break;
                }
            }
            if(!$valid_charset_type)
            {
                ResponseBuilder::bad_request('Only supported Accept-Charset types are: '.implode
                                                                                        (',', $this->allowed_accept_charset));
            }   
        }


        $this->content_type = Server::content_type();

        //Checking if valid content type is used
        if(!$this->content_type)
        {
            $this->content_type = self::DEFAULT_CONTENT;
        }
        else
        {
            $valid_content_type = false;
            $valid_content_types_count = 0;
            $content_type_value = NULL;

            $this->content_type = preg_split('/(,|;)/i', strtolower($this->content_type), -1, PREG_SPLIT_NO_EMPTY);

            for($idx=0; $idx<count($this->content_type); $idx++)
            {
                $this->content_type[$idx] = trim($this->content_type[$idx]);                
            }

            foreach($this->allowed_content_type as $content_type_item)
            {
                if(in_array($content_type_item, $this->content_type))
                {             
                    $valid_content_type = true;
                    $content_type_value = $content_type_item;         
                    $valid_content_types_count++;
                }
            }
            if(!$valid_content_type)
            {
                ResponseBuilder::bad_request('Only supported Content-Type values are: '.implode
                                                                                        (',', $this->allowed_content_type));
            }
            elseif($valid_content_types_count != 1)
            {
                ResponseBuilder::bad_request('Only one Content-Type value per request permitted. Only supported Content-Type values are: '.implode
                                                                                        (',', $this->allowed_content_type));
            }   
            $this->content_type = $content_type_value;
        }


        $this->auth_creds = Server::http_authorize();

        //Checking if valid authorize type is used
        if($this->auth_creds)
        {
            $this->auth_creds = preg_split('/(,|;)/i', strtolower($this->auth_creds), -1, PREG_SPLIT_NO_EMPTY);

            for($idx=0; $idx<count($this->auth_creds); $idx++)
            {
                $this->auth_creds[$idx] = trim($this->auth_creds[$idx]);                
            }

            $valid_auth_creds = false;

            foreach($this->auth_creds as $auth_creds_item)
            {
                $content = preg_split('/\s+/', $auth_creds_item, -1, PREG_SPLIT_NO_EMPTY);

                if($content && count($content) == 2 && strtolower($content[0]) == 'basic')
                {
                    $this->auth_creds = base64_decode($content[1]);
                    $valid_auth_creds = true;
                    break;
                } 
            }  

            if(!$valid_auth_creds)
            {
                $this->auth_creds = NULL;
            }   
        }
    } 
}

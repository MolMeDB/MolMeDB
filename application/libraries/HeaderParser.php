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

    /** ALLOWED AUTHORIZATION TYPES */
    const AUTH_BASIC = 1;

    /** Default values for not specified headers*/
    const DEFAULT_ENCODING = self::GZIP;
    const DEFAULT_CHARSET = self::UTF8;
    const DEFAULT_ACCEPT_TYPE = self::JSON;
    const DEFAULT_CONTENT = self::JSON;

    //Private values for validity checking
    public static $allowed_methods = array
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
    public $requested_endpoint = NULL;
    public $method_type = NULL;
    public $accept_type = [];
    public $accept_encoding = NULL;
    public $accept_charset = NULL;
    public $content_type = NULL;
    public $content = [];

    /** Authorization info */
    public $auth_username = NULL;
    public $auth_token = NULL;
    public $auth_type = NULL;

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

        if(!in_array(strtoupper($this->method_type), self::$allowed_methods))
        {
            ResponseBuilder::bad_request('The server only accepts the following request methods: ' . implode(', ', self::$allowed_methods));    
        }

        $accept_header_content = Server::http_accept();

        //Checking if valid accept content is used
        if(!$accept_header_content)
        {
            $this->accept_type = array(self::DEFAULT_ACCEPT_TYPE);
        }
        else
        {
            $accept_header_content = preg_split('/(,|;)/i', strtolower($accept_header_content), -1, PREG_SPLIT_NO_EMPTY);

            // Trim all values
            $accept_header_content = array_map('trim', $accept_header_content);

            $this->accept_type = array();

            $has_all = FALSE;

            foreach($accept_header_content as $ac)
            {
                if(in_array($ac, $this->allowed_accept_types))
                {
                    if($ac == self::ALL_TYPES)
                    {
                        $has_all = TRUE;
                        continue;
                    }

                    $this->accept_type[] = $ac;
                }
            }

            // If no matter on return value, add default if not present
            if($has_all && !in_array(self::DEFAULT_ACCEPT_TYPE, $this->accept_type))
            {
                $this->accept_type[] = self::DEFAULT_ACCEPT_TYPE;
            }

            $this->accept_type = array_unique($this->accept_type);

            if(!count($this->accept_type))
            {
                ResponseBuilder::bad_request('The server only accepts the following Accept-Type values: '.implode(', ', $this->allowed_accept_types));
            }   
        }


        $accept_encodings = Server::http_encoding();

        //Checking if valid encoding is used
        if(!$accept_encodings)
        {
            $this->accept_encoding = self::DEFAULT_ENCODING;
        }
        else
        {
            $accept_encodings = preg_split('/(,|;)/i', strtolower($accept_encodings), -1, PREG_SPLIT_NO_EMPTY);

            // Trim all values
            $accept_encodings = array_map('trim', $accept_encodings);

            foreach($accept_encodings as $ae)
            {
                if(in_array($ae, $this->allowed_accept_encoding))
                {
                    $this->accept_encoding = $ae;
                    break;
                }
            }

            if(!$this->accept_encoding)
            {
                ResponseBuilder::bad_request('The server only accepts the following Accept-Encodings types: '.implode
                                                                                          (', ', $this->allowed_accept_encoding));
            }   
        }

        $accept_charsets = Server::http_charset();

        //Checking if valid accept charset is used
        if(!$accept_charsets)
        {
            $this->accept_charset = self::DEFAULT_CHARSET;
        }
        else
        {
            $accept_charsets = preg_split('/(,|;)/i', strtolower($accept_charsets), -1, PREG_SPLIT_NO_EMPTY);

            // Trim all values
            $accept_charsets = array_map('trim', $accept_charsets);

            foreach($accept_charsets as $ac)
            {
                if(in_array($ac, $this->allowed_accept_charset))
                {
                    $this->accept_charset = $ae;
                    break;
                }
            }
            
            if(!$this->accept_charset)
            {
                ResponseBuilder::bad_request('The server only accepts the following Accept-Charset types: '.implode
                                                                                        (', ', $this->allowed_accept_charset));
            }   
        }

        // Save content type if set
        $this->content_type = Server::content_type();

        //Checking if valid content type is used
        if($this->content_type)
        {
            if(!in_array($this->content_type, $this->allowed_content_type))
            {
                ResponseBuilder::bad_request('The server only accepts the following Content-Type values: '.implode
                                                                                        (', ', $this->allowed_content_type));
            }

            // Get request content
            switch($this->content_type)
            {
                case self::JSON:
                    $remote_params = file_get_contents('php://input');

                    if($remote_params === false || !is_string($remote_params))
                    {
                        $this->content = [];
                        break;
                    }

                    $this->content = json_decode($remote_params, true);
                    break;

                case self::MULTIPART:
                    if($this->method_type === self::METHOD_POST)
                    {
                        $this->content = $_POST;
                    }
                    break;

                default:
                    $this->content = [];
            }
        }

        if($this->method_type == self::METHOD_GET && empty($this->content))
        {
            $this->content = $_GET;
        }

        // Check authorization info
        $basic_auth = Server::basic_auth_creds();

        if(!empty($basic_auth))
        {
            $this->auth_type = self::AUTH_BASIC;
            $this->auth_username = $basic_auth['username'];
            $this->auth_token = $basic_auth['password'];
        }
    } 
}

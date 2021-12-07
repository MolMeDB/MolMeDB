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


    /** Default values for not specified headers*/
    const DEFAULT_ENCODING = 'gzip';
    const DEFAULT_CHARSET = 'utf-8';
    const DEFAULT_ACCEPT = 'application/json';
    const DEFAULT_CONTENT = 'application/json';


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
        '*/*',
        'application/json', 
        'text/csv'
    );

    /**
     * Allowed values in Accept-Encoding header
     * Order implicates prefered values
     */
    private $allowed_accept_encoding = array
    (
        'gzip', 
        'compress', 
        'deflate'
    );

    /**
     * Allowed values in Accept-Charset header
     * Order implicates prefered values
     */
    private $allowed_accept_charset = array
    (
        'utf-8'
    );

    /**
     * Allowed values in Content-Type header
     * Order implicates prefered values
     */
    private $allowed_content_type = array
    (
        'application/json'
    );


    //Public values for ResponseBuilder
    public $method_type = NULL;
    public $accept_type = NULL;
    public $accept_encoding = NULL;
    public $accept_charset = NULL;
    public $content_type = NULL;
    public $auth_token = NULL;


    /**
     * Constructor
     */    
    public function __construct()
    {
        $this->parseHeaders();
    }

     /**
     * Returns parsed request headers
     * 
     * @author Jaromir Hradil
     * 
     * 
     */
    private function parseHeaders()
    {
        //Checking if allowed HTTP request method is used
        $this->method_type = Server::request_method();

        if(!in_array($this->method_type, $this->allowed_methods))
        {
            ResponseBuilder::bad_request('Only allowed request methods are: POST, GET');    
        }

        $this->accept_type = Server::http_accept();

        //Checking if valid accept content is used
        if(!$this->accept_type)
        {
            $this->accept_type = self::DEFAULT_ACCEPT;
        }
        else
        {
            $valid_accept_type = false;

            $this->accept_type =  explode(',', $this->accept_type);

            for($idx=0; $idx<count($this->accept_type); $idx++)
            {
                $this->accept_type[$idx] = trim($this->accept_type[$idx]);                
            }

            foreach($this->allowed_accept_types as $accept_type_item)
            {
                if(in_array($accept_type_item, $this->accept_type))
                {             
                    if($accept_type_item == '*/*')
                    {
                        $this->accept_type = 'application/json';    
                    }
                    else
                    {
                        $this->accept_type = $accept_type_item; 
                    }        
                    $valid_accept_type = true;                    
                    break;
                }
            }
            if(!$valid_accept_type)
            {
                ResponseBuilder::bad_request('Supported Accept-Type values are : '.implode
                                                                                  (',', $this->allowed_accept_types));
            }   
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

            $this->accept_encoding =  explode(',', $this->accept_encoding);

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

            $this->accept_charset =  explode(',', $this->accept_charset);

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
            $this->content_type =  explode(',', $this->content_type);

            for($idx=0; $idx<count($this->content_type); $idx++)
            {
                $this->content_type[$idx] = trim($this->content_type[$idx]);                
            }

            foreach($this->allowed_content_type as $content_type_item)
            {
                if(in_array($content_type_item, $this->content_type))
                {             
                    $valid_content_type = true;
                    $this->content_type = $content_type_item;         
                    break;
                }
            }
            if(!$valid_content_type)
            {
                ResponseBuilder::bad_request('Only supported Content-Type values are: '.implode
                                                                                        (',', $this->allowed_content_type));
            }   
        }


        $this->auth_token = Server::http_authorize();

        //Checking if valid authorize type is used
        if($this->auth_token)
        {
            $content = trim($this->auth_token);
            $content = preg_split('/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);

            if(!$content || count($content) != 2 || $content[0] != 'Bearer')
            {
                ResponseBuilder::bad_request('Invalid Authorization format, only "Bearer <token>" format is supported');
            }   

            $this->auth_token = $content[1];
        }
    } 
}

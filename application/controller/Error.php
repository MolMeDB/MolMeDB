<?php

/**
 * Error controller
 */
class ErrorController extends Controller 
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function parse()
    {
        header("HTTP/1.0 404 Not Found");
        $this->header['title'] = 'Error 404';
        $this->view = 'error';
    }
}

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

    public function index()
    {
        header("HTTP/1.0 404 Not Found");
        $this->title = 'Error 404';
        $this->view = new View('error');
    }
}

<?php

/**
 * Error controller
 */
class ErrorController extends Controller 
{
    public function parse()
    {
        header("HTTP/1.0 404 Not Found");
        $this->header['title'] = 'Error 404';
        $this->view = 'error';
    }
}

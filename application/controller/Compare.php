<?php

/**
 * Comparator controller
 */
class CompareController extends Controller 
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
        $this->header['title'] = 'Comparator';
        $this->view = 'comparator';
    }
}
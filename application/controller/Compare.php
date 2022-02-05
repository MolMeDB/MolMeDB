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

    public function index() 
    {
        $this->title = 'Comparator';
        $this->view = new View('comparator');
    }
}
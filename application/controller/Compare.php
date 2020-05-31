<?php

/**
 * Comparator controller
 */
class CompareController extends Controller 
{    
    public function parse() 
    {
        $this->header['title'] = 'Comparator';
        $this->view = 'comparator';
    }
}
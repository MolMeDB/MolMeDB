<?php

/**
 * Class for rendering small shards used in views
 * 
 * @author Jakub Juracka
 */
abstract class Render
{
    /**
     * @var View
     */
    protected $view;

    /**
     * @var array|Iterable_object
     */
    protected $data;

    /**
     * Prepares variables to view
     * 
     */
    abstract function prepare();

    /**
     * Returns view as string
     * 
     * @return string
     */
    public function __toString()
    {
        $this->prepare();
        return $this->view->__toString();
    }
}
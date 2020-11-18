<?php

/**
 * Form handler
 * Parses GET/POST params and hold info about (non)submited form
 * 
 * @property bool $validated
 * @property Iterable_object $param
 * @property Iterable_object $file
 * @property int $method
 * 
 * @method bool is_post()
 * @method bool is_get()
 * @method bool has_file()
 * 
 * @author Jakub Juračka
 */
class Form
{
    /**
     * Valid methods
     */
    const METHOD_GET = 1;
    const METHOD_POST = 2;
    const METHOD_FILES = 3;

    /** Is currently any form validated? */
    public $validated = false;

    /** Holds info about given params */
    public $param = NULL;

    /** Holds info about used method */
    public $method = NULL;

    /**
     * Constructor
     */
    function __construct()
    {
        $this->init();
    }

    
    //Shortcuts for getting info about form methods

    /**
     * Is sumbited form with POST method?
     */
    public function is_post()
    {
        return $this->method == self::METHOD_POST;
    }

    /**
     * Is sumbited form with GET method?
     */
    public function is_get()
    {
        return $this->method == self::METHOD_GET;
    }

    /**
     * Is sumbited with file uploaded ?
     * 
     * @param string $name
     */
    public function has_file($name)
    {
        return $this->file->$name && !empty($this->file->$name->tmp_name);
    }


    /**
     * Initialize
     */
    private function init()
    {
        // If not validated, then return
        if(!$_POST && !$_GET && !$_FILES)
        {
            $this->param = new Iterable_object();
            return;
        }

        $this->validated = true;

        if($_POST)
        {
            $this->method = self::METHOD_POST;
            $this->param = new Iterable_object($_POST);
        }
        else if($_GET)
        {
            $this->method = self::METHOD_GET;
            $this->param = new Iterable_object($_GET);
        }

        if($_FILES)
        {
            $this->has_file = true;
            $this->file = new Iterable_object($_FILES, true);
        }

        // Proccess params
        foreach($this->param as $key => $val)
        {
            if(!is_array($val) && trim(strval($val)) == '')
            {
                $this->param->$key = NULL;
            }
        }

    }
}
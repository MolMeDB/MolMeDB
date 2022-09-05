<?php

/**
 * Exports data using JS
 * Good solution for big datasets
 * 
 * @author Jakub Juracka
 */
class Render_js_export extends Render
{
    /** Valid types */
    const TYPE_PASSIVE_INT = 1;
    const TYPE_ACTIVE_INT = 2;

    private static $valid_types = array
    (
        self::TYPE_ACTIVE_INT,
        self::TYPE_PASSIVE_INT
    );

    private $type;

    /** @var array */
    protected $data = [];

    /**
     * Constructor
     */
    function __construct($type)
    {
        if(!self::is_valid_type($type))
        {
            throw new MmdbException('Invalid export type.');
        }

        $this->type = $type;

        switch($type)
        {
            case self::TYPE_PASSIVE_INT:
                $this->data['filename'] = 'Mmdb_export.csv';
                break;
            case self::TYPE_ACTIVE_INT:
                $this->data['filename'] = 'Mmdb_export.csv';
                break;
            default:
                $this->data['filename'] = 'Mmdb_export.csv';
                break;
        }
    }

    private static function is_valid_type($type)
    {
        return in_array($type, self::$valid_types);
    }

    /**
     * Prepares data for view
     */
    function prepare()
    {
        return;
    }

    /**
     * Sets view attributes
     */
    public function __set($key, $value)
    {
        $this->data[$key] = $value;
    }

    /**
     * Sets view attributes
     */
    public function __get($key)
    {
        if(isset($this->data[$key]))
        {
            return $this->data[$key];
        }

        return null;
    }

    /**
     * Render view
     */
    public function render()
    {
        $view = new View('render/js_export');

        foreach($this->data as $key => $val)
        {
            $view->$key = $val;
        }

        $view->type = $this->type;
        $view->render(true);
    }
}
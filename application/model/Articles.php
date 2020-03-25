<?php

/**
 * Article model
 * 
 * @property integer $id
 * @property string $name
 * @property string $content
 * @property integer $user_id
 * @property datetime $createDateTime
 */
class Articles extends Db
{
    /** Article types */
    const T_INTRO = 1;
    const T_CONTACTS = 2;
    const T_DOCUMENTATION = 3;

    /** Article type array */
    private $enum_types = array
    (
        self::T_CONTACTS => 'contact',
        self::T_DOCUMENTATION => 'documentation',
        self::T_INTRO => 'intro'
    );

    function __construct($id = NULL)
    {
        $this->table = 'articles';
        parent::__construct($id);
    }

    /**
     * Checks if given type is valid
     * 
     * @param string $type
     */
    public function is_valid_enum_type($type)
    {
        return in_array($type, $this->enum_types);
    }

    /**
     * Get enum type
     * 
     * @param $type
     */
    public function get_enum_type($type)
    {
        if(!array_key_exists($type, $this->enum_types))
        {
            return NULL;
        }

        return $this->enum_types[$type];
    }

    /**
     * Gets article ID for given type
     * 
     * @param string $type
     * 
     */
    public function get_id_by_type($type)
    {
        if(!$this->is_valid_enum_type($type))
        {
            return NULL;
        }

        return array_search($type, $this->enum_types);
    }
}

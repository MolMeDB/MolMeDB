<?php

/**
 * Holds info about molecule fragment options
 * 
 * @property int $id
 * @property int $id_fragment
 * @property Fragments $fragment
 * @property int $id_enum_type
 * @property Enum_types $enum_type
 * 
 * @author Jakub Juracka
 */
class Fragments_enum_types extends Db
{
    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'fragments_enum_types';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_fragment' => array
        (
            'var' => 'fragment',
            'class' => 'Fragments'
        ),
        'id_enum_type' => array
        (
            'var' => 'enum_type',
            'class' => 'Enum_types'
        ),
    );

}
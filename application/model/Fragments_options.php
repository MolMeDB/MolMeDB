<?php

/**
 * Holds info about molecule fragment options
 * 
 * @property int $id
 * @property int $id_parent
 * @property Fragments $parent
 * @property int $id_child
 * @property Fragments $child
 * @property string $deletions
 * 
 * @author Jakub Juracka
 */
class Fragments_options extends Db
{
    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'fragments_options';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_parent' => array
        (
            'var' => 'parent',
            'class' => 'Fragments'
        ),
        'id_child' => array
        (
            'var' => 'child',
            'class' => 'Fragments'
        ),
    );
}
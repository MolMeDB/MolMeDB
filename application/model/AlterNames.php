<?php

/**
 * AlterNames model
 * 
 * @property integer $id
 * @property integer $id_substance
 * @property string $name
 * @property datetime $createDateTime
 * @property datetime $editDateTime
 * 
 * @property Substances $substance
 * 
 */
class AlterNames extends Db
{
    /** Links to other DB tables */
    public $has_one = array
    (
        'id_substance'
    );

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'alternames';
        parent::__construct($id);
    }
}

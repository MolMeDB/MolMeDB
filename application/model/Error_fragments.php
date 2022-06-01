<?php

/**
 * Holds error info related with fragmentation
 * 
 * @property int $id
 * @property int $id_substance
 * @property Substances $substance
 * @property int $id_fragment
 * @property Fragments $fragment
 * @property int $type
 * @property string $text
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Error_fragments extends Db
{
    /**
     * Error types
     */
    const TYPE_FRAGMENTATION_ERROR = 1;

    /**
     * Constructor
     */
    function __construct($id = null)
    {
        $this->table = 'error_fragments';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_substance',
        'id_fragment'
    );

    /**
     * Overload save function
     */
    public function save()
    {
        if(!$this->id_substance && !$this->id_fragment)
        {
            throw new DbException('Cannot save error_fragment record without id_substance and id_fragment values.');
        }

        return parent::save();
    }
}
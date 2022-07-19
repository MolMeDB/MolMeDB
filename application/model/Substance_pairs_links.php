<?php

/**
 * Holds info about substance pair links
 * 
 * @property int $id
 * @property int $id_substance_pair
 * @property Substance_pairs $pair
 * @property int $type
 * @property int $id_substance_1_fragment
 * @property Substances_fragments $substance_1_Fragment
 * @property int $id_substance_2_fragment
 * @property Substances_fragments $substance_2_Fragment
 * @property int $id_core
 * @property Fragments $core
 * 
 * @author Jakub Juracka
 */
class Substance_pairs_links extends Db
{
    /** TYPES */
    const TYPE_ADD = 1;
    const TYPE_SUBS = 2;

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'substance_fragmentation_pair_links';
        parent::__construct($id);
    }

    /**
     * Instance
     * 
     * @return Substance_pairs_links
     */
    public static function instance()
    {
        return parent::instance();
    }

    /**
     * Links to another tables
     */
    protected $has_one = array
    (
        'id_substance_pair' => array
        (
            'var' => 'pair',
            'class' => 'Substance_pairs'
        ),
        'id_substance_1_fragment' => array
        (
            'var' => 'substance_1_Fragment',
            'class' => 'Substances_fragments'
        ),
        'id_substance_2_fragment' => array
        (
            'var' => 'substance_2_Fragment',
            'class' => 'Substances_fragments'
        ),
        'id_core' => array
        (
            'var' => 'core',
            'class' => 'Fragments'
        ),
    );

   
}
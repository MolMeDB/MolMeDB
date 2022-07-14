<?php

/**
 * Holds info about substance paris
 * 
 * @property int $id
 * @property int $id_substance_1
 * @property Substances $substance_1
 * @property int $id_substance_2
 * @property Substances $substance_2
 * @property int $id_core
 * @property Fragments $core
 * @property int $substance_1_fragmentation_order
 * @property int $substnace_2_fragmentation_order
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Substance_pairs extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'substance_fragmentation_pairs';
        parent::__construct($id);
    }

    /**
     * Instance
     * 
     * @return Substance_pairs
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
        'id_substance_1' => array
        (
            'var' => 'substnace_1',
            'class' => 'Substances'
        ),
        'id_substance_2' => array
        (
            'var' => 'substnace_2',
            'class' => 'Substances'
        ),
        'id_core' => array
        (
            'var' => 'core',
            'class' => 'Fragments'
        ),
    );

    /**
     * Saves
     */
    public function save()
    {
        // check if exists
        $exists = $this->where(array
        (
            'id_substance_1' => $this->id_substance_1,
            'id_substance_2' => $this->id_substance_2,
        ))
        ->get_one();

        $reverse = false;

        if(!$exists->id)
        {
            $exists = $this->where(array
            (
                'id_substance_2' => $this->id_substance_1,
                'id_substance_1' => $this->id_substance_2,
            ))
            ->get_one();
            $reverse = true;
        }

        if($exists->id)
        {
            $f1_total = Substances_fragments::instance()->where(
                array
                (
                    'id_substance' => $this->id_substance_2,
                    'order_number' => $this->substance_2_fragmentation_order
                ))
                ->get_one()->total_fragments;

            $f2_total = Substances_fragments::instance()->where(
                array
                (
                    'id_substance' => $exists->id_substance_2,
                    'order_number' => $exists->substance_2_fragmentation_order
                ))
                ->get_one()->total_fragments;

            if($f1_total > $f2_total)
            {
                $exists->id_core = $this->id_core;
                $exists->substance_1_fragmentation_order = $this->substance_1_fragmentation_order;
                $exists->substance_2_fragmentation_order = $this->substnace_2_fragmentation_order;

                $exists->save();
            }
            parent::__construct($exists->id);
            return;
        }

        parent::save();
    }

    
    /**
     * Returns new molecules for matching
     * 
     * @param int $limit
     * 
     * @return Substances[]
     */
    public function get_unmatched_molecules($limit)
    {
       $ids = Db::instance()->queryAll("
            SELECT DISTINCT id
            FROM substances
            WHERE id NOT IN (
                SELECT id_substance_1
                FROM substance_fragmentation_pairs
                WHERE id_substance_1 = id_substance_2
            )
            LIMIT $limit
        ");

        $result = [];

        foreach($ids as $row)
        {
            $result[] = new Substances($row->id);
        }

        return $result;
    }
}
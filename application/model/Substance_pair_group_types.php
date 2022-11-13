<?php

/**
 * Holds info about substance pairs
 * 
 * @property int $id
 * @property int $id_group
 * @property Substance_pair_groups $group
 * @property int $id_membrane
 * @property Membranes $membrane
 * @property Methods $method
 * @property string $charge
 * @property int $id_target
 * @property Transporter_targets $target
 * @property string $stats
 * 
 * @author Jakub Juracka
 */
class Substance_pair_group_types extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'substance_pair_group_types';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_group' => array
        (
            'class' => 'Substance_pair_groups',
            'var'   => 'group'
        ),
        'id_membrane' => array
        (
            'class' => 'Membranes',
            'var'   => 'membrane'
        ),
        'id_method' => array
        (
            'class' => 'Methods',
            'var'   => 'method'
        ),
        'id_target' => array
        (
            'class' => 'Transporter_targets',
            'var'   => 'target'
        )
    );

    /**
     * Save 
     */
    public function save()
    {
        // Check for duplicities
        if(!$this->id)
        {
            // Passive
            if($this->id_membrane)
            {
                $exists = $this->where(array
                (
                    'id_membrane'   => $this->id_membrane,
                    'id_method'     => $this->id_method,
                    'charge'        => $this->charge,
                    'id_group'      => $this->id_group
                ))->get_one();
            }
            else // Active
            {
                $exists = $this->where(array
                (
                    'id_group' => $this->id_group,
                    'id_target' => $this->id_target
                ))->get_one();
            }

            if($exists->id)
            {
                $d = $this->stats;
                parent::__construct($exists->id);
                $this->stats = $d;
            }
        }

        parent::save();
    }   
}
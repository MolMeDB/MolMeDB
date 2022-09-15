<?php

/**
 * Holds info about substance pairs
 * 
 * @property int $id
 * @property int $id_group
 * @property int $id_membrane
 * @property int $id_method
 * @property string $charge
 * @property int $id_target
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
                parent::__construct($exists->id);
                return;
            }
        }

        parent::save();
    }   
}
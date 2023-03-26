<?php

/**
 * Holds info about molecule ionization runs
 * 
 * @property int $id
 * @property int $id_fragment
 * @property Fragments $fragment
 * - ↓↓↓ Returned by RDKIT method. Indicates the range of pH on which were molecules generated. ↓↓↓
 * @property double $ph_start
 * @property double $ph_end
 * @property string $datetime - Create datetime
 * 
 * @author Jakub Juračka
 */
class Run_ionization extends Db
{
    /**
     * Constructor
     */
    function __construct($id = null)
    {
        $this->table = 'run_ionization';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_fragment'
    );
}
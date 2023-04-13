<?php

/**
 * Holds info about COSMO upload data
 * 
 * @property int $id
 * @property string $comment
 * @property int $id_user
 * @property Users $user
 * 
 * @author Jakub Juračka
 */
class Run_cosmo_datasets extends Db
{
    /**
     * Constructor
     */
    function __construct($id = null)
    {
        $this->table = 'run_cosmo_datasets';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_user'
    );
}
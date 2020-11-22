<?php

/**
 * Scheduler errors handler
 * 
 * @property int $id
 * @property int $id_substance
 * @property string $error_text
 * @property int $count
 * @property string $last_update
 * 
 * @author Jakub Juračka
 */
class Scheduler_errors extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'scheduler_errors';
        parent::__construct($id);
    }
    
}
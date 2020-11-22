<?php

/**
 * Scheduler log model
 * 
 * @property integer $id
 * @property string $datetime
 * @property integer $error_count
 * @property integer $success_count
 * @property string $report_path
 * 
 * @author Jakub Juračka 
 * 
 */
class Log_scheduler extends Db
{

    /**
     * Constructor
     */
    public function __construct($id = NULL)
    {
        $this->table = 'log_scheduler';
        parent::__construct($id);
    }
}
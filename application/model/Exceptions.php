<?php

/**
 * Exception class for saving exception details
 * 
 * @property int $id
 * @property int $status
 * @property int $level
 * @property int $code
 * @property string $file
 * @property int $line
 * @property string $trace
 * @property string $message
 * @property int $id_user
 * @property Users $user
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Exceptions extends Db
{
    /** Exception statuses */
    const STATUS_NEW = 0;
    const STATUS_RESOLVED = 1;

    /**
     * Constructor
     */
    function __construct($id = null)
    {
        $this->table = 'exceptions';
        parent::__construct($id);
    }

    /**
     * FK links
     */
    protected $has_one = array
    (
        'id_user'
    );
}
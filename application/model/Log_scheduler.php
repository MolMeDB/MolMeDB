<?php

/**
 * Scheduler log model
 * 
 * @property integer $id
 * @property integer $type
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
    /** Log types */
    const T_SUBSTANCE_FILL = 1;
    const T_SUBSTANCE_AUTOREMOVE = 2;
    const T_INT_CHECK_DATASET = 3;

    private static $enum_types = array
    (
        self::T_SUBSTANCE_FILL => 'Substance validation',
        self::T_SUBSTANCE_AUTOREMOVE => 'Substance autoremove',
        self::T_INT_CHECK_DATASET => 'Interaction dataset check',
    );

    /**
     * Returns enum type
     * 
     * @param int $type
     * 
     * @return string|null
     */
    public static function get_enum_type($type)
    {
        if(!array_key_exists($type, self::$enum_types))
        {
            return null;
        }

        return self::$enum_types[$type];
    }

    /**
     * Constructor
     */
    public function __construct($id = NULL)
    {
        $this->table = 'log_scheduler';
        parent::__construct($id);
    }
}
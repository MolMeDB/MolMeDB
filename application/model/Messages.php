<?php

/**
 * Holds info about information messages
 * 
 * @property int $id
 * @property int $type
 * @property string $name
 * @property string $email_subject
 * @property string $email_text
 * 
 * @author Jakub Juracka
 */
class Messages extends Db 
{
    const TYPE_VALIDATION_WELCOME_MESSAGE   = 1;
    const TYPE_COSMO_DATASET_PROGRESS_NEW   = 2;
    const TYPE_COSMO_DATASET_PROGRESS_DONE  = 3;
    const TYPE_WELCOME_MESSAGE_ADMIN_NOTIFY  = 4;
    const TYPE_COSMO_STATS  = 5;

    /**
     * Constructor
     */
    public function __construct($id = null)
    {
        $this->table = 'messages';
        parent::__construct($id);
    }

    /**
     * Returns message by type
     * 
     * @param int $type
     * 
     * @return Messages
     */
    public static function get_by_type($type)
    {
        return self::instance()->where('type', $type)->get_one();
    }
}
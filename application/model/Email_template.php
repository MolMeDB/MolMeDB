<?php

/**
 * Email template model
 * 
 * @property int $id
 * @property string $name
 * @property string $subject
 * @property string $text
 */
class Email_template extends Db
{
    // Template names
    const REGISTRATION_EMAIL = 'registration_email';

    // Valid names
    private static $valid_names = array
    (
        self::REGISTRATION_EMAIL
    );

    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'email_templates';
        parent::__construct($id);
    }

    /**
     * Returns template object by name
     * 
     * @param string $name
     * 
     * @return Email_template|null
     */
    public function get_by_name($name)
    {
        if(!in_array($name, self::$valid_names))
        {
            throw new Exception('Invalid template name.');
        }

        $id = $this->where('name', $name)->get_one()->id;

        $record = new Email_template($id);

        $record->name = $name;
        return $record;
    }
}
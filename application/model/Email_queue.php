<?php

/**
 * Email queue model
 * 
 * @property int $id
 * @property string $recipient_email
 * @property string $subject
 * @property string $text
 * @property int $status
 * @property int $file_path
 * @property string $create_date
 * @property string $last_attemp_date
 * @property string $error_text
 * 
 * @author Jakub Juracka 
 */

 class Email_queue extends Db
 {
    /** Email statuses */
    CONST SENDING = 1;
    CONST SENT = 2;
    CONST ERROR = 3;

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'email_queue';
        parent::__construct($id);
    }
 }
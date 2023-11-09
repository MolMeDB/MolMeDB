<?php

/**
 * Main exception class for MolMeDB app
 */
class MmdbException extends Exception
{
    /**
     * @var string
     */
    private $printable_message;

    /**
     * Indicates error level
     * @var int
     */
    protected $level = ERROR_LVL_BASIC;

    /**
     * @var int 
     */
    private $error_id;

    /**
     * Constructor
     * 
     * @param string $message
     * @param string $printable
     * @param int $code
     * @param Exception $previous
     */
    function __construct($message="", $printable="Error occured during processing your request. Please, try again.", $code = 0, $previous = NULL)
    {
        $this->printable_message = $printable;
        parent::__construct($message, (int)$code, $previous);

        // Log an error, if has corresponding priority
        if($this->level > ERROR_LVL_BASIC)
        {
            $ex = $previous ? $previous : $this;

            if(System_config::$db_connected)
            {
                $e = new Exceptions($this->error_id);

                $e->status = Exceptions::STATUS_NEW;
                $e->level = $this->level;
                $e->code = (int)$ex->getCode();
                $e->file = $ex->getFile();
                $e->line = $ex->getLine();
                $e->trace = json_encode(debug_backtrace());
                $e->message = $ex->getMessage();
                $e->id_user = session::user_id();

                $e->save();
                $this->error_id = $e->id;
            }
        }
    }
    

    /**
     * Returns exception code
     * 
     * @return int
     */
    public function getExceptionId()
    {
        return $this->error_id;
    }


    /**
     * Returns printable message
     * 
     * @return string
     */
    public function getPrintable()
    {
        if($this->error_id)
        {
            return $this->printable_message . "\n\n" . "Code number: " . $this->error_id;
        }

        return $this->printable_message;
    }

    /**
     * Prints message for user
     */
    public function __toString(): string
    {
        return $this->getPrintable();
    }
}
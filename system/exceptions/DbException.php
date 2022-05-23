<?php

class DbException extends MmdbException
{
    protected $level = ERROR_LVL_DB;

    private $query;

    /**
     * Constructor
     */
    function __construct($message="", $query = "", $code = 0, $previous = NULL, $printable="Invalid database request. Please, contact server administrator.")
    {
        $this->query = $query;
        $message = $message . "\n"
            . "Query: " . $query;

        parent::__construct($message, $printable, $code, $previous);
    }

    /**
     * Returns query
     * 
     * @return string
     */
    public function getQuery()
    {
        return $this->query;
    }
}
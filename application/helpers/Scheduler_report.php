<?php

/**
 * Class for handling scheduler errors 
 * Produces cheduler report in CSV format
 */
class Scheduler_report
{
    /** Scheduler error TYPES */
    const T_SUBSTANCE = 1;

    /** HEADER COLUMNS */
    const FIRST_COL = 'Substance';
    const COL_REPORT = 'Error report';

    /** Holds info about current type */
    private $type;

    /** Holds info about errors */
    private $errors = array();

    /** File extension */
    private $extension = 'csv';

    /** Target folder */
    private $folder = MEDIA_ROOT . 'files/schedulerReports/';

    /** Holds file path of saved file */
    private $filePath;


    /**
     * Constructor
     * 
     * @param int $type Upload type
     */
    function __construct($type = NULL)
    {
        $this->type = $type;
    }

    /**
     * Adds new error report
     * 
     * @param int $line Line on which error ocurred
     * @param string $report Report about error
     */
    public function add($attr, $err)
    {
        if(!$err){
            $err = 'Undefined error';
        }
        $this->errors[] = array
        (
            self::FIRST_COL => $attr,
            self::COL_REPORT => $err
        );
    }

    /**
     * Checks, if instance contains any errors
     * 
     * @return bool
     */
    public function is_empty()
    {
        return count($this->errors) == 0;
    }

    /**
     * Get count of report lines
     * 
     * @return int
     */
    public function count()
    {
        return count($this->errors);
    }

    /**
     * Returns report with all errors (filepath) or FALSE
     * if no error was caught
     * 
     */
    public function save_report()
    {
        if(empty($this->errors))
        {
            return false;
        }

        $file = new File();
        $filePath = $this->folder . $file->generateName() . '.' . $this->extension;
        $file->create($filePath);

        // Add header
        array_unshift($this->errors, array(self::FIRST_COL, self::COL_REPORT));

        // Write file lines
        $file->writeCSV($this->errors);

        $this->filePath = $filePath;
    }

    /**
     * Returns file path
     * 
     * @return string
     */
    public function get_file_path()
    {
        if(!$this->filePath)
        {
            $this->save_report();
        }

        return $this->filePath;
    }

}
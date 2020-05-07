<?php

/**
 * Class for handling upload errors 
 * Produces upload report in CSV format
 */
class Upload_report
{
    /** UPLOAD TYPES */
    const T_DATASET = 1;
    const T_TRANSPORTERS = 2;

    /** HEADER COLUMNS */
    const COL_ROW = 'Line';
    const COL_REPORT = 'Error report';

    /** Holds info about current type */
    private $type;

    /** Holds info about errors */
    private $errors = array();

    /** File extension */
    private $extension = 'csv';

    /** Target folder */
    private $folder = MEDIA_ROOT . 'files/uploadReports/';


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
    public function add($line, $report)
    {
        if(!$line && $line != 0){
            $line = 'N/A';
        }
        if(!$report){
            $report = 'Not defined error';
        }
        $this->errors[] = array
        (
            self::COL_ROW => $line,
            self::COL_REPORT => $report
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
     * @return string|false Filepath
     */
    public function get_report()
    {
        if(empty($this->errors))
        {
            return false;
        }

        $file = new File();
        $filePath = $this->folder . $file->generateName() . '.' . $this->extension;
        $file->create($filePath);

        // Add header
        array_unshift($this->errors, array(self::COL_ROW, self::COL_REPORT));

        // Write file lines
        $file->writeCSV($this->errors);

        return $filePath;
    }

}
<?php

/**
 * Publications model
 * 
 * @property integer $id
 * @property string $citation
 * @property string $doi
 * @property integer $pmid
 * @property string $title
 * @property string $authors
 * @property string $journal
 * @property string $volume
 * @property string $issue
 * @property string $page
 * @property string $year
 * @property datetime $publicated_date
 * @property integer $user_id
 * @property datetime $createDateTime
 * @property datetime $editDateTime
 * 
 */
class Publications extends Db
{
    /**
     * Constructor
     * 
     * @param integer $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'publications';
        parent::__construct($id);
    }

    /**
     * Returns only references with assigned some interaction
     * 
     * @return Iterable
     */
    public function get_nonempty_refs()
    {
        return $this->queryAll('
            SELECT DISTINCT p.* 
            FROM interaction as i 
            JOIN publications as p ON i.id_reference = p.id 
            WHERE p.id > 0 AND i.visibility = 1 
            ORDER BY citation');
    }

    /**
     * Load data from file
     * 
     * deprecated
     */
    public function loadData($filename)
    {
        return file($filename);    
    }
}
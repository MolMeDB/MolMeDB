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
 * @property date $publicated_date
 * @property integer $user_id
 * @property string $pattern
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

    /**
     * Return reference for given $query
     * 
     * @param string $query
     * 
     * @return Publications
     */
    public function get_by_query($query)
    {
        if(!$query || $query == '')
        {
            return new Publications();
        }

        $row = $this->queryOne(
            'SELECT id 
            FROM publications
            WHERE pmid LIKE ? OR doi LIKE ? OR citation LIKE ?'
            , array
            (
                $query,
                $query,
                $query
            ));

        return new Publications($row['id']);
    }

    /**
     * Returns citation for given object params
     * 
     * @return string
     */
    public function make_citation()
    {
        $citation = $this->authors . ': ' . trim($this->title) . ' ';

        if($this->journal && $this->journal != '')
        {
            $citation = $citation . $this->journal . ", ";

            if($this->volume && $this->volume != '')
            {
                $citation = $citation . "Volume " . $this->volume;

                if($this->issue)
                {
                    $citation = $citation . " (" . $this->issue . ")";
                }

                $citation = $citation . ", ";
            }

            if($this->pages)
            {
                $citation = $citation . $this->pages . ", ";
            }
        }

        $citation = $citation . $this->year;

       return trim($citation);
    }
}
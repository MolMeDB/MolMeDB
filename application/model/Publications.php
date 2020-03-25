<?php

/**
 * Publications model
 * 
 * @property integer $id
 * @property string $reference
 * @property string $doi
 * @property string $description
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
            ORDER BY reference');
    }

    //Deprecated
    public function insertRef($content, $doi, $desc){
        $id = array();
        $id = $this->queryOne('SELECT id FROM publications WHERE reference = ? LIMIT 1', array($content));
        if (!isset($id['id']))
            return $this->insert('publications', array('reference' => $content, 'doi' => $doi, 'description' => $desc, 'user_id' => $_SESSION['user']['id']));
    }

    /**
     * Load data from file
     */
    public function loadData($filename)
    {
        return file($filename);    
    }
}
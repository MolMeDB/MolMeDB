<?php

/**
 * Transporter targets model
 * 
 * @param integer $id
 * @param string $name
 * @param string $uniprot_id
 * @param integer $id_user
 * @param datetime $create_datetime
 * 
 * @param Users $id_user 
 */
class Transporter_targets extends Db
{
    /**
     * Constructor
     * 
     * @param integer $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'transporter_targets';
        parent::__construct($id);
    }

    /**
     * Finds transporter targets
     * 
     * @param string $query
     * 
     * @return Transporter_targets
     */
    public function find($name, $uniprot)
    {
        $t = $this->queryOne(
            'SELECT id 
            FROM transporter_targets
            WHERE name LIKE ? OR uniprot_id LIKE ?
            LIMIT 1'
            ,array
            (
                $name,
                $uniprot
            )
        );
        
        $t = new Transporter_targets($t->id);
        
        return $t;
    }
}
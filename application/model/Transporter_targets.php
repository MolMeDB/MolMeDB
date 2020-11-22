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

    /**
     * Get data to the whisper
     * 
     * @param string $query
     */
    public function loadSearchWhisper($query)
    {
        return $this->queryAll('
            SELECT CONCAT(tt.name, " [", tt.uniprot_id, "]") as name
            FROM transporter_targets tt
            JOIN transporters t ON t.id_target = tt.id
            JOIN transporter_datasets td ON td.id = t.id_dataset
            WHERE (tt.name LIKE ? OR tt.uniprot_id LIKE ?) AND td.visibility = ?
            LIMIT 10
        ', array($query, $query, Transporter_datasets::VISIBLE), False);
    }
}
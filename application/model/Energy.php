<?php

/**
 * Energy model
 * 
 * @property integer $id
 * @property integer $id_substance
 * @property integer $id_membrane
 * @property integer $id_method
 * @property float $distance
 * @property float $energy
 * @property integer $user_id
 * @property datetime $createDateTime
 * @property Membranes $membrane
 * @property Methods $method
 * @property Substances $substance
 * 
 */
class Energy extends Db
{

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'energy';
        parent::__construct($id);
    }

    /** FK to other tables */
    public $has_one = array
    (
        'id_substance',
        'id_membrane',
        'id_method'
    );
   

    public function checkDataset($idSubstance, $idMembrane, $idMethod,  $distance)
    {
        $res = $this->queryOne('SELECT id FROM energy WHERE id_substance = ? AND id_membrane = ? AND id_method = ? AND distance = ? LIMIT 1', array($idSubstance, $idMembrane, $idMethod, $distance));
        if (empty($res['id']))
            return false;
        return true;
    }
    

    // public function linkRecords($id1, $id2)
    // {
    //     $idSubstance = $this->queryOne("SELECT id_substance FROM energy WHERE id_substance = ? LIMIT 1", array($id2))['idSubstance'];
    //     if(isset($idSubstance)){
    //         $this->query("UPDATE `energy` SET `id_substance` = ? WHERE `id_substance` = ?", array($id1, $id2));
    //         return;
    //     }
    //     return;   
    // }

    /**
     * Gets available free energy profiles for given substance id
     * 
     * @param integer $idSubstance
     * 
     * @return Iterable_object
     */
    public function get_available_by_substance($idSubstance)
    {
        $methodModel = new Methods();
        
        // Get all methods
        $methods = $methodModel->get_all();

        $res = array();

        foreach($methods as $m)
        {
            $temp = $this->queryOne('
                SELECT id 
                FROM energy 
                WHERE id_method = ? AND id_substance = ? 
                LIMIT 1', 
                array
                (
                    $m->id, 
                    $idSubstance
                )
                )->id;

            $res[$m->id] = $temp ? $temp : 0;
        }

        return $res;
    }
    
    /**
     * 
     * @param integer $idSubstance
     * @param integer $idMembrane
     * @param integer $idMethod
     * @param boolean $limit
    */
    public function getEnergyValues($idSubstance, $idMembrane, $idMethod, $limit = false)
    {
        if($limit)
        {
            return $this->queryOne("SELECT * "
                . "FROM energy "
                . "WHERE id_substance = ? "
                    . "AND id_membrane = ? "
                    . "AND id_method = ? "
                . "LIMIT 1"
              , array($idSubstance, $idMembrane, $idMethod));
        }
        
        return $this->queryAll("SELECT * "
                . "FROM energy "
                . "WHERE id_substance = ? "
                    . "AND id_membrane = ? "
                    . "AND id_method = ? "
              , array($idSubstance, $idMembrane, $idMethod));
    }
}

<?php

/**
 * Substances model
 * 
 * @property integer $id
 * @property string $identifier
 * @property string $name
 * @property string $uploadName
 * @property string $SMILES
 * @property float $MW
 * @property float $Area
 * @property float $Volume
 * @property float $LogP
 * @property string $pubchem
 * @property string $drugbank
 * @property string $chEBI
 * @property string $pdb
 * @property string $chEMBL
 * @property integer $user_id
 * @property boolean $validated
 * @property datetime $createDateTime
 * @property datetime $editDateTime
 * 
 */
class Substances extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'substances';
        parent::__construct($id);
    }    
    
    // /**
    //  * Gets all substances for given membranes/methods IDs
    //  * 
    //  * @param array $membranes - IDs of membranes
    //  * @param array $methods - IDs of methods
    //  * 
    //  * @return object
    //  */
    // public function get_by_membrane_method($membranes, $methods)
    // {
    //     $mem = !empty($membranes) ? 'idMembrane IN (' . implode( ',', $membranes) . ') AND ' : '';
    //     $met = !empty($methods) ? 'idMethod IN (' . implode(',', $methods) . ') AND ' : '';
        
    //     return $this->queryAll("
    //         SELECT name, s.id, identifier 
    //         FROM substances s
    //         WHERE id IN 
    //             (SELECT DISTINCT idSubstance 
    //             FROM interaction 
    //             WHERE $mem $met 1)");
    // }
    
    
    // /**
    //  * Get substance by pubchem ID
    //  * 
    //  * @param string $pubchem_id
    //  */
    // public function get_by_pubchem($pubchem_id = '')
    // {
    //     return $this->queryAll("SELECT idSubstance, pubchem, name, uploadName FROM substances WHERE pubchem != ?", array($pubchem_id));
    // }
    
    /**
     * Loads whisper detail to search engine
     * 
     * @param string $q - Query
     * 
     * @return array|object
     */
    public function loadSearchWhisper($q)
    {
        $data = $this->queryAll('
            SELECT DISTINCT name, "" as altername 
            FROM substances 
            WHERE name LIKE ? 
            
            UNION
            
            SELECT DISTINCT s.name name, a.name altername
            FROM alternames a
            JOIN substances s ON s.id = a.id_substance
            WHERE a.name LIKE ? LIMIT 20
            ', array($q, $q));
        
        $res = array();
        $used = array();
        
        foreach($data as $row)
        {
            if(in_array($row->name, $used))
            {
                continue;
            }

            $res[] = array
            (
                'name'      => $row->name,
                'pattern'   => trim($row->altername) != '' ? 'Alter. name: ' . $row->altername : ''
            );
            
            $used[] = $row->name;
        }
        
        return $res;
    }

    /**
     * Count search list for query
     * 
     * @param string $query
     * 
     * @return integer
     */
    public function search_count($query)
    {
        $query = '%' . $query . '%';

        return $this->queryOne('
            SELECT COUNT(*) as count 
            FROM
            (
                SELECT DISTINCT s.id
                FROM (
                    SELECT id
                    FROM substances s
                    WHERE name LIKE ?
                    
                    UNION

                    SELECT DISTINCT id_substance as id
                    FROM `alternames`
                    WHERE `name` LIKE ?) as a
                JOIN substances as s ON a.id = s.id
                ORDER BY IF(s.name RLIKE "^[a-z]", 1, 2), s.name
            ) tab ',
            array($query, $query)
        )->count;
    }

    /**
     * Search substance by given query
     * 
     * @param string $query
     * 
     * @return Iterable
     */
    public function search($query, $pagination = 1)
	{
        $query = '%' . $query . '%';
        
        $result = $this->queryAll('
            SELECT DISTINCT s.`name`, identifier, a.name as altername, s.`id`, `SMILES`, `MW`, `pubchem`, `drugbank`, `chEBI`, `pdb`, `chEMBL` 
            FROM (
                SELECT id, name
                FROM substances s
                WHERE name LIKE ?
                
                UNION

                SELECT DISTINCT id_substance as id, name as altername 
                FROM `alternames`
                WHERE `name` LIKE ?) as a
            JOIN substances as s ON a.id = s.id
            ORDER BY IF(s.name RLIKE "^[a-z]", 1, 2), s.name'
            , array($query, $query));

        return $this->parse_list($result, $pagination);
    }
    
    /**
     * Parsing search list detail
     * 
     * @param array $data
     * @param array $pagination
     * 
     * @return array
     */
    private function parse_list($data, $pagination)
    {
        $ids = array();
        $res = array();
        $iterator = 0;

        foreach($data as $detail)
        {
            if(!in_array($detail->id, $ids))
            {
                $res[$iterator] = $detail;
                $ids[$iterator++] = $detail->id;
            }
        }

        $res = array_slice($res, ($pagination-1)*10, 10);

        return $res;
    }

    /**
     * Search substances by SMILES
     * 
     * @param string $query
     * @param integer $pagination
     * 
     * @return array
     */
    public function search_by_smiles($query, $pagination)
    {
        $query = '%' . $query . '%';

        return $this->queryAll('
            SELECT DISTINCT name, identifier, id, SMILES, MW, pubchem, drugbank, chEBI, pdb, chEMBL
            FROM substances
            WHERE SMILES LIKE ?
            ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
            LIMIT ?,?', array($query,($pagination-1)*10,10));
    }

    /**
     * Count substances by SMILES
     * 
     * @param string $query
     * 
     * @return integer
     */
    public function search_by_smiles_count($query)
    {
        $query = '%' . $query . '%';

        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM substances
            WHERE SMILES LIKE ?', array($query))
        ->count;
    }
    
    /**
     * Search substances for given method name
     * 
     * @param string $query
     * @param integer $pagination
     * 
     * @return array
     */
    public function search_by_method($query, $pagination)
    {
        $query = '%' . $query . '%';
        return $this->queryAll('
            SELECT DISTINCT name, identifier, s.id, SMILES, MW, pubchem, drugbank, chEBI, pdb, chEMBL
            FROM substances as s
            JOIN interaction as i ON s.id = i.id_substance
            WHERE i.id_method IN (SELECT id
                                        FROM methods
                                        WHERE name LIKE ?)
                AND i.visibility = ?
            ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
            LIMIT ?,?', array
            (
                $query,
                Interactions::VISIBLE,
                ($pagination-1)*10,
                10
            ));
    }

    /**
     * Count substances for given method name
     * 
     * @param string $query
     * 
     * @return array
     */
    public function search_by_method_count($query)
    {
        $query = '%' . $query . '%';
        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM(
                SELECT DISTINCT s.id
                FROM substances as s
                JOIN interaction as i ON s.id = i.id_substance
                WHERE i.id_method IN (SELECT id
                                            FROM methods
                                            WHERE name LIKE ?)
                    AND i.visibility = ?) as tab', 
            array
            (
                $query,
                Interactions::VISIBLE
            ))->count;
    }

    /**
     * Search substances for given membrane name
     * 
     * @param string $query
     * @param integer $pagination
     * 
     * @return array
     */
    public function search_by_membrane($query, $pagination)
    {
        $query = '%' . $query . '%';
        return $this->queryAll('
            SELECT DISTINCT name, identifier, s.id, SMILES, MW, pubchem, drugbank, chEBI, pdb, chEMBL
            FROM substances as s
            JOIN interaction as i ON s.id = i.id_substance
            WHERE i.id_membrane IN (SELECT id
                                        FROM membranes
                                        WHERE name LIKE ?)
                AND i.visibility = ?
            ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
            LIMIT ?,?', array
            (
                $query,
                Interactions::VISIBLE,
                ($pagination-1)*10,
                10
            ));
    }

    /**
     * Count substances according to the membrane name
     * 
     * @param string $query
     * 
     * @return integer
     */
    public function search_by_membrane_count($query)
    {
        $query = '%' . $query . '%';

        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM 
                (SELECT DISTINCT s.id 
                FROM substances as s
                JOIN interaction as i ON s.id = i.id_substance
                WHERE i.id_membrane IN (SELECT id
                                            FROM membranes
                                            WHERE name LIKE ?)
                    AND i.visibility = ?) as tab',
            array
            (
                $query,
                Interactions::VISIBLE
            )
            )->count;
    }
    
    /**
     * Search substances by given reference ID
     * 
     * @param integer $idReference
     * @param integer $pagination
     * 
     * @return Iterable
     */
    public function get_by_reference_id($idReference, $pagination)
    {
        return $this->queryAll('
            SELECT DISTINCT name, identifier, s.id, SMILES, MW, pubchem, drugbank, chEBI, pdb, chEMBL
            FROM substances as s
            JOIN interaction as i ON s.id = i.id_substance
            WHERE i.id_reference = ? AND visibility = 1
            ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
            LIMIT ?,?', array($idReference, ($pagination-1)*10,10));
    }

    /**
     * Get count of substances for given reference
     * 
     * @param integer $idReference
     * 
     * @return integer
     */
    public function count_by_reference_id($idReference){
        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM 
                (SELECT DISTINCT s.id
                FROM substances as s
                JOIN interaction as i ON s.id = i.id_substance
                WHERE i.id_reference = ? AND visibility = 1) as tab1', array($idReference))->count;
    }
    
    /**
     * Insert new substances
     * Protected for duplicate key entry
     * 
     * @param string $name
     * @param string $uploadName
     * @param integer $MW
     * @param string $SMILES
     * @param integer $userID
     * @param float $Area
     * @param float $Volume
     * @param float $LogP
     * @param string $pdb
     * @param string $drugbank
     * @param string $pubchem
     * 
     * @return integer - ID of inserted/rewritten substance
     */
    public function insert_substance($name, $uploadName, $MW,  $SMILES, $userID, $Area, $Volume, $LogP, $pdb = NULL, $drugbank = NULL, $pubchem = NULL)
	{
        // Insert new record
        $this->query("
            INSERT INTO substances
            VALUES(DEFAULT,DEFAULT,?,?,?,?,?,?,?,?,'',?,'',?,?,?,DEFAULT, DEFAULT)
            ON DUPLICATE KEY
            UPDATE `SMILES` = IF(? = '', `SMILES`, ?), `MW` = IF(? = 0, `MW`, ?), `Area` = IF(? = 0, `Area`, ?), `Volume` = IF(? = 0, `Volume`, ?),
                    `LogP` = IF(? = 0, `LogP`, ?), `pubchem` = IF(? = '', `pubchem`, ?), `drugbank` = IF(? = '', `drugbank`, ?),
                    `pdb` = IF(? = '', `pdb`, ?)",
            array
            (
                $name,
                $SMILES,
                $MW,
                $Area,
                $Volume,
                $LogP,
                $pubchem,
                $drugbank,
                $pdb,
                $userID,
                0,
                $uploadName,
                $SMILES,$SMILES, 
                $MW,$MW, 
                $Area,$Area, 
                $Volume,$Volume, 
                $LogP,$LogP, 
                $pubchem,$pubchem, 
                $drugbank,$drugbank, 
                $pdb,$pdb
            ));
        
        // Get ID of inserted substance
        $idSubstance = $this->getLastIdSubstance_time()[0];
        
        // Check if name is saved in alternames yet
        $check = $this->queryOne('SELECT id_substance FROM alternames WHERE name = ?', array($name));

        if(!$check)
        {
            // If not, save new
            $this->insert('alternames', array('id_substance' => $idSubstance, 'name' => $name));
        }
        
        return $idSubstance;
    }
    
    /**
     * Checks if exists substances for given names
     * 
     * @param string $ligand
     * @param string $uploadName
     * 
     * @return integer|null ID of found substance
     */
    public function test_duplicates($ligand, $uploadName)
    {
        $result = $this->queryOne("
            SELECT id_substance 
            FROM alternames 
            WHERE name = ? OR name = ? 
            LIMIT 1", 
            array($ligand, $uploadName));
        
        if($result->id)
        {
            return $result->id;
        }

        $result = $this->queryOne("
            SELECT id FROM substances WHERE uploadName = ? OR name = ? OR uploadName = ? OR name = ? LIMIT 1", 
            array
            (
                $ligand, 
                $uploadName, 
                $uploadName, 
                $ligand
            ));

        return $result->id;
    }
    
    /**
	 * Get substances for given membrane x method
     * 
	 * @param string $idMembrane
	 * @param string $idMethod
	 */
    public function getSubstancesToStat($idMembrane, $idMethod)
    {
        if($idMembrane)
        {
            return $this->queryAll('
                SELECT id, name, identifier '
                . 'FROM substances '
                . 'WHERE id IN '
                    . '(SELECT DISTINCT id_substance '
                    . 'FROM interaction '
                    . 'WHERE id_membrane = ? AND id_method = ?) '
                    . 'ORDER BY name', array($idMembrane, $idMethod));
        }
        else
        {
            return $this->queryAll('
                SELECT id, name, identifier '
                . 'FROM substances '
                . 'WHERE id IN '
                    . '(SELECT DISTINCT id_substance '
                    . 'FROM interaction '
                    . 'WHERE id_method = ?) '
                    . 'ORDER BY name', array($idMethod));
        }
    }
    
	/**
     * Validate substance table
     * Fill identifier, if anywhere is not set
     * 
     */
	public function validate_table()
	{
        $rows = $this->queryAll('SELECT DISTINCT * FROM substances WHERE name IS NULL OR uploadName IS NULL OR identifier IS NULL');
		
		foreach($rows as $row)
		{
            $substance = new Substances($row->id);

            $identifier = Identifiers::generate_substance_identifier($row->id);
            
            $substance->identifier = $identifier;
            $substance->name = !empty($substance->name) ? $substance->name : $identifier; 
            $substance->uploadName = !empty($substance->uploadName) ? $substance->uploadName : $identifier; 

            $substance->save();
		}	
	}
}

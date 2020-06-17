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
     * Search substance by given params
     * 
     * @param string $identifier
     * @param string $smiles
     * @param string $name
     * @param string $pubchem
     * @param string $drugbank
     * @param string $chebi
     * @param string $chembl
     * @param string $pdb
     * @param int $limit 
     * @param int $offset
     * 
     * @return Iterable
     */
    public function search_by_params($identifier = NULL, $smiles = NULL, $name = NULL, $pubchem = NULL, $drugbank = NULL, $chebi = NULL, $chembl = NULL, $pdb = NULL, $limit = NULL, $offset = NULL)
	{
        $limit_str = '';
        $where = 'WHERE 0 ';
        $params = [];

        if($identifier && $identifier != "")
        {
            $where .= 'OR identifier = ? ';
            $params[] = $identifier;
        }

        if($smiles && $smiles != "")
        {
            // First canonize, if possible
            $rdkit = new Rdkit();
            if($rdkit->is_connected())
            {
                $smiles = $rdkit->canonize_smiles($smiles);
            }

            $where .= ' OR SMILES LIKE ? ';
            $params[] = $smiles;
        }

        if($name && $name != "")
        {
            $name = "%$name%";
            $where .= " OR name LIKE ? ";
            $params[] = $name;
        }

        if($pubchem && $pubchem != "")
        {
            $where .= ' OR pubchem LIKE ? ';
            $params[] = $pubchem;
        }

        if($drugbank && $drugbank != "")
        {
            $where .= ' OR drugbank LIKE ? ';
            $params[] = $drugbank;
        }

        if($chebi && $chebi != "")
        {
            $where .= ' OR chEBI LIKE ? ';
            $params[] = $chebi;
        }

        if($chembl && $chembl != "")
        {
            $where .= ' OR chEMBL LIKE ? ';
            $params[] = $chembl;
        }

        if($pdb && $pdb != "")
        {
            $where .= ' OR pdb LIKE ? ';
            $params[] = $pdb;
        }

        $where = trim($where);

        if($where == 'WHERE 0 OR')
        {
            return [];
        }

        if($limit && is_numeric($limit))
        {
            $limit_str = " LIMIT $limit ";
            if($offset !== NULL && is_numeric($offset))
            {
                $limit_str .= " OFFSET $offset ";
            }
        }

        $result = $this->queryAll("
            SELECT id
            FROM substances
            $where
            $limit_str"
            ,$params);

        $ids = [];

        foreach($result as $r)
        {
            $ids[] = $r->id;
        }

        if(!count($ids))
        {
            return [];
        }

        return $this->in('id', $ids)->get_all();
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
            SELECT DISTINCT tab.name, tab.identifier, tab.id, tab.SMILES, tab.MW, tab.pubchem, tab.drugbank, tab.chEBI, tab.pdb, tab.chEMBL
            FROM 
            (
                (SELECT DISTINCT s.*
                FROM substances as s
                JOIN interaction as i ON s.id = i.id_substance
                JOIN datasets d ON d.id = i.id_dataset
                WHERE (i.id_reference = ? OR d.id_publication = ?) AND d.visibility = 1)

                UNION

                (SELECT DISTINCT s.*
                FROM substances as s
                JOIN transporters t ON t.id_substance = s.id
                JOIN transporter_datasets td ON td.id = t.id_dataset
                WHERE (t.id_reference = ? OR td.id_reference = ?) AND td.visibility = 1)
            ) as tab
            ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
            LIMIT ?,?', array
            (
                $idReference, 
                $idReference, 
                $idReference, 
                $idReference, 
                ($pagination-1)*10,
                10
            )
        );
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
            (
                SELECT DISTINCT tab.id
                FROM 
                (
                    (SELECT DISTINCT s.*
                    FROM substances as s
                    JOIN interaction as i ON s.id = i.id_substance
                    JOIN datasets d ON d.id = i.id_dataset
                    WHERE (i.id_reference = ? OR d.id_publication = ?) AND d.visibility = 1)

                    UNION

                    (SELECT DISTINCT s.*
                    FROM substances as s
                    JOIN transporters t ON t.id_substance = s.id
                    JOIN transporter_datasets td ON td.id = t.id_dataset
                    WHERE (t.id_reference = ? OR td.id_reference = ?) AND td.visibility = 1)
                ) as tab
            ) as t'
            ,
            array(
                $idReference,
                $idReference,
                $idReference,
                $idReference,
            )
        )->count;
    }
    
    /**
     * Checks if substance already exists and return it
     * 
     * @param string $name
     * @param string $uploadName
     * @param string $SMILES
     * @param string $pdb
     * @param string $drugbank
     * @param string $pubchem
     * @param string $chEBI
     * @param string $chEMBL
     * 
     * @return Substances
     */
    public function exists($name, $SMILES, $pdb = NULL, $drugbank = NULL, $pubchem = NULL, $chEBI = NULL, $chEMBL = NULL)
	{
        $res = $this->queryAll(
            'SELECT DISTINCT s.id, s.identifier
            FROM substances s
            LEFT JOIN alternames a ON a.id_substance = s.id 
            WHERE SMILES LIKE ? OR pdb LIKE ? OR chEBI LIKE ? OR 
                chEMBL LIKE ? OR drugbank LIKE ? OR pubchem LIKE ? OR 
                s.name LIKE ? OR a.name LIKE ? OR uploadName LIKE ?',
            array
            (
                $SMILES,
                $pdb,
                $chEBI,
                $chEMBL,
                $drugbank,
                $pubchem,
                $name, 
                $name, 
                $name,
            )
        );

        $total = count($res);

        if($total > 1)
        {
            $ids = '';

            foreach($res as $row)
            {
                $ids .= $row->identifier  . ', ';
            }

            $ids = trim(trim($ids), ',');

            throw new UploadLineException("Error ocurred while trying to find existing molecule. Total $total possible molecules found. Try to check compounds with identifiers: $ids.");
        }

        if($total)
        {
            $res = $res[0];
        }

        return new Substances($res->id);
    }
    
    /**
     * Checks if exists substances for given names
     * DEPRECATED - USE exists method instead
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

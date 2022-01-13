<?php

/**
 * Substances model
 * 
 * @property integer $id
 * @property string $identifier
 * @property string $name
 * @property string $uploadName
 * @property string $SMILES
 * @property string $inchikey
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
 * @property string $createDateTime
 * @property string $editDateTime
 * 
 * @author Jakub Juracka
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
     * Checks current substance name, if contains some identifier
     * 
     * @return boolean - True, if found. False otherwise
     */
    public function check_identifiers_in_name()
    {
        if(!$this || !$this->id)
        {
            return false;
        }

        $name = trim($this->name);
        
        $vi = new Validator_identifiers();
        $source = $vi->find_source($this->id, $vi::ID_NAME);

        $vi->id_substance = $this->id;
        $vi->id_source = $source->id;
        $vi->value = $name;
        $vi->id_user = NULL;
        $vi->state = $source->is_credible($vi::SERVER_MOLMEDB) ? $vi::STATE_VALIDATED : $vi::STATE_NEW;
        
        if(!$source || !$source->id)
        {
            throw new Exception('Cannot find name source.');
        }

        if(Upload_validator::check_identifier_format($name, Upload_validator::DRUGBANK, True))
        {
            // Exists already?
            $exists = $vi->get_active_substance_value($this->id, $vi::ID_DRUGBANK);

            $vi->identifier = $vi::ID_DRUGBANK;
            $vi->active = $vi->state == $vi::STATE_VALIDATED && !$exists->id ? $vi::ACTIVE : $vi::INACTIVE;
        }
        elseif(Upload_validator::check_identifier_format($name, Upload_validator::CHEMBL_ID, True))
        {
            // Exists already?
            $exists = $vi->get_active_substance_value($this->id, $vi::ID_CHEMBL);

            $vi->identifier = $vi::ID_CHEMBL;
            $vi->active = $vi->state == $vi::STATE_VALIDATED && !$exists->id ? $vi::ACTIVE : $vi::INACTIVE;

        }
        else
        {
            return false;
        }

        $vi->save();

        // Update name
        $vi = new Validator_identifiers();
        $vi->id_substance = $this->id;
        $vi->id_source = NULL;
        $vi->value = $this->identifier;
        $vi->id_user = NULL;
        $vi->identifier = $vi::ID_NAME;
        $vi->state = $vi::STATE_VALIDATED;
        $vi->active = $vi::ACTIVE;

        $vi->save();

        return true;
    }

    /**
     * Checks, if substance identifiers are persistent
     * 
     * @throws Exception
     */
    public function check_identifiers()
    {
        if(!$this || !$this->id)
        {
            throw new Exception('Invalid subtance instance.');
        }

        $vi = new Validator_identifiers();

        $vi->init_substance_identifiers($this);

        $name = $vi->get_active_substance_value($this->id, $vi::ID_NAME);
        $smiles = $vi->get_active_substance_value($this->id, $vi::ID_SMILES);
        $inchikey = $vi->get_active_substance_value($this->id, $vi::ID_INCHIKEY);
        $pubchem = $vi->get_active_substance_value($this->id, $vi::ID_PUBCHEM);
        $drugbank = $vi->get_active_substance_value($this->id, $vi::ID_DRUGBANK);
        $chebi = $vi->get_active_substance_value($this->id, $vi::ID_CHEBI);
        $chembl = $vi->get_active_substance_value($this->id, $vi::ID_CHEMBL);
        $pdb = $vi->get_active_substance_value($this->id, $vi::ID_PDB);

        // Check identifier validity
        
        if($chembl->id)
        {
            try
            {
                $checked = Upload_validator::get_attr_val(Upload_validator::CHEMBL_ID, $chembl->value);

                if(!$checked)
                {
                    throw new SchedulerException('Chembl id doesn\'t match pattern.');
                }
                else
                {
                    // Check, if exists
                    $chembl_server = new Chembl();
                    
                    if($chembl_server->is_valid_identifier($chembl->value) === false)
                    {
                        throw new SchedulerException('Cannot find identifier on remote server.');
                    }
                }
            }
            catch(SchedulerException $e)
            {
                $chembl->active = $chembl::INACTIVE;
                $chembl->active_msg = $e->getMessage();
                $chembl->save();

                $chembl = null; 
            }
            catch(Exception $e)
            {
                
            }
        }
        if($drugbank->id)
        {
            try
            {
                $checked = Upload_validator::get_attr_val(Upload_validator::DRUGBANK, $drugbank->value);

                if(!$checked)
                {
                    throw new SchedulerException('Drugbank id doesn\'t match pattern.');
                }
                else
                {
                    // Check, if exists
                    $drugbank_server = new Drugbank();
                    
                    if($drugbank_server->is_valid_identifier($drugbank->value) === false)
                    {
                        throw new SchedulerException('Cannot find identifier on remote server.');
                    }
                }
            }
            catch(SchedulerException $e)
            {
                $drugbank->active = $drugbank::INACTIVE;
                $drugbank->active_msg = $e->getMessage();
                $drugbank->save();

                $drugbank = null; 
            }
            catch(Exception $e)
            {
                
            }
        }
        

        $this->name = $name && $name->id ? $name->value : NULL;
        $this->SMILES = $smiles && $smiles->id ? $smiles->value : NULL;
        $this->inchikey = $inchikey && $inchikey->id ? $inchikey->value : NULL;
        $this->pubchem = $pubchem && $pubchem->id ? $pubchem->value : NULL;
        $this->drugbank = $drugbank && $drugbank->id ? $drugbank->value : NULL;
        $this->chEBI = $chebi && $chebi->id ? $chebi->value : NULL;
        $this->chEMBL = $chembl && $chembl->id ? $chembl->value : NULL;
        $this->pdb = $pdb && $pdb->id ? $pdb->value : NULL;

        $this->save();
    }

    /**
     * Returns substance 3D structure
     * 
     * @param int $server - Finds 3D structure from given server if present
     * 
     * @return Files
     */
    public function get_3D_structure($server = NULL)
    {
        if(!$this || !$this->id)
        {
            return new Files();
        }

        $where = ['id_substance' => $this->id];

        if($server)
        {
            $where['server'] = $server;
        }

        $vs = new Validator_structure();

        $record = $vs->where($where)->order_by('is_default DESC, datetime', 'DESC')->get_one();

        if(!$record->id)
        {
            return new Files();
        }

        foreach($record->files as $f)
        {
            return $f;
        }

        return new Files();
    }

    /**
     * Checks, if substance has default 3D structure
     * 
     * @return boolean
     */
    public function has_default_3D_structure()
    {
        if(!$this || !$this->id)
        {
            return new Files();
        }

        $where = ['id_substance' => $this->id];

        $vs = new Validator_structure();
        $record = $vs->where($where)->order_by('is_default', 'DESC')->get_one();

        return $record->is_default;
    }

    // /**
    //  * Returns info about fragmentation state of molecule
    //  * 
    //  * @return boolean|null
    //  */
    // public function is_fragmented()
    // {
    //     if(!$this)
    //     {
    //         return null;
    //     }

    //     $data = Validator::get_fragmentation_records($this);

    //     if(!$data || !count($data))
    //     {
    //         return False;
    //     }

    //     // The newest record should be info about successful fragmentation
    //     return $data[0]->type === Validator_state::TYPE_FRAGMENTED;
    // }

    // /**
    //  * Returns true, if 3D Structure was already downloaded from given source
    //  * 
    //  * @param int $source - constants from Validator_state class
    //  * 
    //  * @return boolean
    //  * 
    //  */
    // public function had_structure_from_source($source)
    // {
    //     if(!$this)
    //     {
    //         return null;
    //     }

    //     if(!Validator_state::is_valid_source($source))
    //     {
    //         throw new Exception('Invalid source flag.');
    //     }

    //     $data = Validator::get_structure_records($this);

    //     foreach($data as $row)
    //     {
    //         if($row->get_source() == $source)
    //         {
    //             return TRUE;
    //         }
    //     }

    //     return FALSE;
    // }

    // /**
    //  * Returns all history values for given attribute
    //  * 
    //  * @param int $attribute_flag
    //  * 
    //  * @return array
    //  */
    // public function get_attribute_value_history($attribute_flag)
    // {
    //     return Validator::get_atribute_changes($this, $attribute_flag);
    // }

    // /**
    //  * Checks, if substance had given value for given attribute already
    //  * 
    //  * @param int $attribute_flag
    //  * @param string $value
    //  * 
    //  * @return Iterable_object[]
    //  * @throws Exception
    //  */
    // public function had_attribute_value($attribute_flag, $value)
    // {
    //     return Validator::had_attribute_value($this, $attribute_flag, $value);
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
     * Returns substances without interaction data
     * 
     * @return array
     */
    public function get_empty_substances()
    {
        $method_model = new Methods();
        $method = $method_model->get_by_type(Methods::PUBCHEM_LOGP_TYPE);

        if(!$method || !$method->id)
        {
            return [];
        }

        $result = [];

        $d = $this->queryAll('
            SELECT DISTINCT id 
            FROM
            (
                SELECT s.id, i.id as iid, t.id as tid
                FROM substances s
                LEFT JOIN interaction i ON i.id_substance = s.id AND i.id_method != ?
                LEFT JOIN transporters t ON t.id_substance = s.id
                GROUP BY id
            ) as t
            WHERE t.iid IS NULL AND t.tid IS NULL
        ', array($method->id));

        foreach($d as $r)
        {
            $result[] = $r->id;
        }

        return $result;
    }


    /**
     * Returns substance_ids which are labeled as nonduplicity
     * 
     * @return array
     */
    public function get_non_duplicities()
    {
        $result = [];

        $d1 = $this->queryAll('
            SELECT DISTINCT id_substance_2 as id
            FROM validations
            WHERE id_substance_1 = ? AND active = 0
        ', array($this->id));

        $d2 = $this->queryAll('
            SELECT DISTINCT id_substance_1 as id
            FROM validations
            WHERE id_substance_2 = ? AND active = 0
        ', array($this->id));

        foreach($d1 as $r)
        {
            $result[] = $r->id;
        }
        foreach($d2 as $r)
        {
            if(!in_array($r->id, $result))
            {
                $result[] = $r->id;
            }
        }

        return $result;
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
                SELECT DISTINCT s.id, s.name
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
     * Count search list for query
     * 
     * @param string $query
     * 
     * @return integer
     */
    public function search_by_transporter_count($query)
    {
        $query = '%' . $query . '%';

        return $this->queryOne('
            SELECT COUNT(*) as count 
            FROM
            (
                SELECT DISTINCT s.id
                FROM substances s
                JOIN transporters t on t.id_substance = s.id
                JOIN transporter_datasets d ON d.id = t.id_dataset
                JOIN transporter_targets tt ON tt.id = t.id_target
                WHERE tt.name LIKE ? AND d.visibility = ?
            ) tab ',
            array($query, Transporter_datasets::VISIBLE)
        )->count;
    }

    /**
     * Search substance by given query
     * 
     * @param string $query
     * 
     * @return Iterable
     */
    public function search_by_transporter($query, $pagination = 1)
	{
        $query = '%' . $query . '%';
        
        $result = $this->queryAll('
            SELECT DISTINCT s.*
            FROM substances s
            JOIN transporters t on t.id_substance = s.id
            JOIN transporter_datasets d ON d.id = t.id_dataset
            JOIN transporter_targets tt ON tt.id = t.id_target
            WHERE tt.name LIKE ? AND d.visibility = ?
            ORDER BY IF(s.name RLIKE "^[a-z]", 1, 2), s.name'
            , array($query, Transporter_datasets::VISIBLE));

        return $this->parse_list($result, $pagination);
    }
    
    /**
     * Parsing search list detail
     * 
     * @param array $data
     * @param int $pagination
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
    
    // /**
    //  * Checks if exists substances for given names
    //  * DEPRECATED - USE exists method instead
    //  * 
    //  * @param string $ligand
    //  * @param string $uploadName
    //  * 
    //  * @return integer|null ID of found substance
    //  */
    // public function test_duplicates($ligand, $uploadName)
    // {
    //     $result = $this->queryOne("
    //         SELECT id_substance 
    //         FROM alternames 
    //         WHERE name = ? OR name = ? 
    //         LIMIT 1", 
    //         array($ligand, $uploadName));
        
    //     if($result->id)
    //     {
    //         return $result->id;
    //     }

    //     $result = $this->queryOne("
    //         SELECT id FROM substances WHERE uploadName = ? OR name = ? OR uploadName = ? OR name = ? LIMIT 1", 
    //         array
    //         (
    //             $ligand, 
    //             $uploadName, 
    //             $uploadName, 
    //             $ligand
    //         ));

    //     return $result->id;
    // }
    
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
    
    // /**
    //  * Label given substance as possible duplicity for current object
    //  * 
    //  * @param $id_substance - Found duplicity
    //  * @param $duplicity - Duplicity detail
    //  */
    // public function add_duplicity($id_substance, $duplicity)
    // {
    //     if(!$this->id || !$id_substance)
    //     {
    //         throw new Exception('Cannot add duplicity. Invalid instance or substance_id.');
    //     }

    //     $validation = new Validator();

    //     $exists = $validation->where(array
    //         (
    //             'id_substance_1' => $this->id,
    //             'id_substance_2' => $id_substance,
    //             'duplicity LIKE' => $duplicity
    //         ))
    //         ->get_one();

    //     if(!$exists->id)
    //     {
    //         $validation->id_substance_1 = $this->id;
    //         $validation->id_substance_2 = $id_substance;
    //         $validation->duplicity = $duplicity;

    //         $validation->save();
    //     }

    //     // Label molecule as possible duplicity
    //     $this->prev_validation_state = $this->validated;
    //     $this->validated = Validator::POSSIBLE_DUPLICITY;
    //     $this->waiting = 1;
    //     $this->save();
    // }

    // /**
    //  * Overloading of save() function
    //  * 
    //  * @param int $source - For logging source of changes
    //  */
    // public function save($source = NULL)
    // {
    //     if($source)
    //     {
    //         // Get all changes
    //         $changes = $this->get_attribute_changes();

    //         print_r($changes);
    //         die;

    //         foreach($changes as $attr => $vals)
    //         {
    //             switch($attr)
    //             {
    //                 case 'pubchem':
    //                     $flag = Validator_state::FLAG_PUBCHEM;
    //                     break;
    //                 case 'drugbank':
    //                     $flag = Validator_state::FLAG_DRUGBANK;
    //                     break;
    //                 case 'chEBI':
    //                     $flag = Validator_state::FLAG_CHEBI;
    //                     break;
    //                 case 'pdb':
    //                     $flag = Validator_state::FLAG_PDB;
    //                     break;
    //                 case 'chEMBL':
    //                     $flag = Validator_state::FLAG_CHEMBL;
    //                     break;
    //                 case 'inchikey':
    //                     $flag = Validator_state::FLAG_INCHIKEY;
    //                     break;
    //                 case 'SMILES':
    //                     $flag = Validator_state::FLAG_SMILES;
    //                     break;
    //                 case 'name':
    //                     $flag = Validator_state::FLAG_NAME;
    //                     break;
    //                 case 'LogP':
    //                     $flag = Validator_state::FLAG_LOGP;
    //                     break;
    //                 default:
    //                     $flag = NULL;
    //                     break;
    //             }

    //             if(!$flag)
    //             {
    //                 continue;
    //             }

    //             // Validator_attr_change::add($this, $flag, $vals['new_value'])
    //         }
    //     }

    //     parent::save();
    // }
}

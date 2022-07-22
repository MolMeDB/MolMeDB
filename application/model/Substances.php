<?php

/**
 * Substances model
 * 
 * @property integer $id
 * @property string $identifier
 * @property string $name
 * @property string $uploadName
 * @property string $SMILES
 * @property string $fingerprint
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
 * @property string $old_identifier - Indicates, if record was loaded as redirection from old record
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

        if($id && !is_array($id) && preg_match(Identifiers::PATTERN, $id))
        {
            // Find by identifier
            $id_t = preg_replace('/^[M]{2}[0]*/i', '', $id);
            parent::__construct($id_t);

            if(!$this->id)
            {
                $s = Substance_links::instance()->where('identifier LIKE', $id)->get_one();

                if($s->id_substance)
                {
                    parent::__construct($s->id_substance);
                    $this->old_identifier = $id;
                }
            }
            return;
        }

        parent::__construct($id);
    }    

    /**
     * Creates instance by identifier
     * 
     * @param string $identifier
     * 
     * @return Substances
     */
    public static function by_identifier($identifier)
    {
        $identifier = strtoupper($identifier);
        return Substances::instance()->where('identifier', $identifier)->get_one();
    }

    /**
     * Returns all molecule fragments
     * 
     * @return array
     */
    public function get_all_fragments()
    {
        return Substances_fragments::instance()->get_all_substance_fragments($this->id);
    }

    /**
     * Returns molecules differing in the presence of a functional group
     * 
     * @param int $id_substance
     * 
     * @return array
     */
    public function save_functional_relatives($id_substance = null)
    {
        if(!$id_substance && !$this && !$this->id)
        {
            return null;
        }

        if(!$id_substance)
        {
            $id_substance = $this->id;
        }

        $substance = new Substances($id_substance);

        if(!$substance->id)
        {
            return null;
        }

        $active_smiles = Validator_identifiers::instance()->get_active_substance_value($substance->id, Validator_identifiers::ID_SMILES)->value;

        if(!$active_smiles)
        {
            return null;
        }

        // Get all options of current molecule
        $fragment = Fragments::instance()->where('smiles', $active_smiles)->get_one();

        if(!$fragment->id)
        {
            return null;
        }

        $pairs = [];

        // Save init flag
        $r = new Substance_pairs();
            
        $r->id_substance_1 = $substance->id;
        $r->id_substance_2 = $substance->id;
        $r->id_core = $fragment->id;
        $r->substance_1_fragmentation_order = 1;
        $r->substance_2_fragmentation_order = 1;

        $pairs[$substance->id] = [$r];

        // Get all variants of the same fragment
        $options = Fragments_options::instance()->get_all_options_ids($fragment->id);

        // At first, find bigger substances containing requested substance
        $bigger = [];

        foreach($options as $id)
        {
            $bigger = array_merge($bigger, Fragmentation::get_all_with_fragment_id($id, $substance->id));
        }

        // Save pairs
        foreach($bigger as $fragmentation)
        {
            if(!$fragmentation->order_number || !$fragmentation->id_substance)
            {
                continue;
            }

            foreach($fragmentation->raw_fragments as $sf)
            {
                if($sf->fragment->get_functional_group() === null && $sf->fragment->id !== $fragmentation->core->fragment->id)
                {
                    continue 2;
                }
            }

            $r = new Substance_pairs();
            
            $r->id_substance_1 = $substance->id;
            $r->id_substance_2 = $fragmentation->id_substance;
            $r->id_core = $fragmentation->core->fragment->id;
            $r->substance_1_fragmentation_order = 1;
            $r->substance_2_fragmentation_order = $fragmentation->order_number;

            if(!isset($pairs[$fragmentation->id_substance]))
            {
                $pairs[$fragmentation->id_substance] = [];
            }

            $pairs[$fragmentation->id_substance][] = $r;
        }

        // Find molecules composed of core + some func. group deletion
        // Get all molecule fragmentations
        $all_fragmentations = Fragmentation::get_all_substance_fragmentations($substance->id);

        $func_g_fragmentations = $all_fragmentations;
        $non_f_g_fragments = [];

        // Get all fragmentations, which contain MAX one non-funcional-group fragment
        foreach($all_fragmentations as $key => $fragmentation)
        {
            if(count($fragmentation->raw_fragments) == 1)
            {
                unset($func_g_fragmentations[$key]);
                continue;
            }

            $non_f_g = 0;
            foreach($fragmentation->raw_fragments as $record)
            {
                if($record->fragment->get_functional_group(null, false) === null)
                {
                    $non_f_g_fragments[$key] = $record;
                    $non_f_g++;
                }
            }

            if($non_f_g > 1)
            {
                if(isset($non_f_g_fragments[$key])) 
                {
                    unset($non_f_g_fragments[$key]);
                }
                unset($func_g_fragmentations[$key]);
            }
        }

        // Find pairs differing by substitution
        foreach($func_g_fragmentations as $key => $frag)
        {
            if(isset($non_f_g_fragments[$key]))
            {
                $cores = [$non_f_g_fragments[$key]];
            }
            else
            {
                continue;
            }

            foreach($cores as $c)
            {
                $fragment = $c->fragment;
                $atom_count = $fragment->get_atom_count();

                if($atom_count < 5 
                    // && ($fragment->get_functional_group() !== null)
                    )
                {
                    continue;
                }

                $variants = Fragments_options::instance()->get_all_options_ids($fragment->id);

                // Get compounds of the same group + func. groups
                $candidates = Db::instance()->queryAll('
                    SELECT DISTINCT id, id_substance, order_number
                    FROM (SELECT *, LENGTH(REPLACE(REPLACE(GROUP_CONCAT(fg), ",", ""), "0", "")) as total_fg
                    FROM
                    (
                        SELECT DISTINCT sf.*, IF(fet.id IS NOT NULL, "1", "0") as fg
                        FROM `substances_fragments` sf 
                        LEFT JOIN fragments_enum_types fet ON fet.id_fragment = sf.id_fragment
                        JOIN(
                            SELECT id_substance, order_number
                            FROM substances_fragments sf
                            WHERE sf.id_fragment IN ("' . implode('","', $variants) . '")
                            ) as t1 ON t1.id_substance = sf.id_substance AND t1.order_number = sf.order_number
                        WHERE sf.id_substance != ?
                    ) as t  
                    GROUP BY id_substance, order_number) as t
                    WHERE total_fragments-total_fg < 2 AND total_fragments-total_fg >= 0
                ', array($c->id_substance));

                foreach($candidates as $cand)
                {
                    if(!isset($pairs[$cand->id_substance]))
                    {
                        $pairs[$cand->id_substance] = [];
                    }
                    
                    $r = new Substance_pairs();

                    $r->id_substance_1 = $substance->id;
                    $r->id_substance_2 = $cand->id_substance;
                    $r->id_core = $c->fragment->id;
                    $r->substance_1_fragmentation_order = $frag->order_number;
                    $r->substance_2_fragmentation_order = $cand->order_number;

                    $pairs[$cand->id_substance][] = $r;
                }
            }
        }

        // Remove old and save new data
        Db::instance()->query('DELETE FROM substance_fragmentation_pairs WHERE id_substance_1 = ? OR id_substance_2 = ?', array($substance->id, $substance->id));

        foreach($pairs as $id => $data)
        {
            $best = null;
            $max = 0;

            foreach($data as $row)
            {
                $core = new Fragments($row->id_core);
                $total_atoms = $core->get_atom_count();
                if($total_atoms > $max)
                {
                    $max = $total_atoms;
                    $best = $row;
                }
            }

            // Save record
            $best->save();
        }
    }

    /**
     * Returns similar molecules
     * 
     * @param int|null $id
     * 
     * @return Iterable_object[]|false - False if error
     */
    public function get_similar_entries($id = null, $similarity_limit = 0.2)
    {
        if(!$id && !$this->id)
        {
            return false;
        }

        if(!$id)
        {
            $id = $this->id;
        }

        $db_limit = 0.6;

        $substance = new Substances($id);

        // Get substance fragments
        $temp = $substance->queryOne('
            SELECT GROUP_CONCAT(DISTINCT id_fragment) as ids
            FROM 
                (SELECT DISTINCT 1 as id, id_fragment
                    FROM substances_fragments
                    WHERE id_substance = ? AND similarity > ?
                
                UNION 
                
                SELECT DISTINCT 1 as id, fo.id_parent
                                FROM `substances_fragments` sf
                                JOIN fragments_options fo ON fo.id_child = sf.id_fragment
                                WHERE sf.id_substance = ? AND sf.similarity > ?
                
                UNION
                
                SELECT DISTINCT 1 as id, fo.id_child
                                FROM `substances_fragments` sf
                                JOIN fragments_options fo ON fo.id_parent = sf.id_fragment
                                WHERE sf.id_substance = ? AND sf.similarity > ?) as t
            GROUP BY id
        ', array($substance->id, $db_limit, $substance->id, $db_limit, $substance->id, $db_limit));

        $temp->ids = trim($temp->ids, ',');

        if(!$temp->ids)
        {
            return [];
        }

        $t = $temp->ids;
        $temp = $substance->queryOne("
            SELECT GROUP_CONCAT(DISTINCT id_fragment) as ids
            FROM
                (SELECT DISTINCT 1 as id, id_parent as id_fragment
                FROM fragments_options
                WHERE id_child IN (" . $temp->ids . ")
                UNION 
                SELECT DISTINCT 1 as id, id_child as id_fragment
                FROM fragments_options
                WHERE id_parent IN (" . $temp->ids . ")
                ) as t
            GROUP BY id
        ");

        $temp->ids = trim($temp->ids, ',');

        $t = $t . ',' . $temp->ids;
        $t = preg_replace('/\,+$/', '', $t);

        if(!$t)
        {
            return [];
        }

        $rows = $substance->queryAll("
            SELECT DISTINCT id, id_fragment, sf.id_substance, sf.order_number, similarity, links, sf.total_fragments as total
            FROM substances_fragments sf
            WHERE id_fragment IN 
                (" . $t . ")
              AND similarity > ? AND sf.id_substance != ?
              ORDER BY id_substance ASC, similarity DESC, total ASC
        ", array($db_limit, $substance->id));

        $options = $similarities = [];

        foreach($rows as $row)
        {
            if(isset($options[$row->id_substance]))
            {
                continue;
            }

            $t = new Fragments($row->id_fragment);
            $sub = new Substances($row->id_substance);

            
            if(!$t->id || !$sub->id)
            {
                continue;
            }
            
            $sim = Mol_similarity::compute($substance, $sub);
            
            if($sim < $similarity_limit)
            {
                continue;
            }
            
            $all_parts = Substances_fragments::instance()->get_fragmentation_detail_by_order($row->id_substance, $row->order_number);
            
            $similarities[$row->id_substance] = $sim;

            $options[$row->id_substance] = (object)array
            (
                'fragment' => $t,
                'subfragments' => $all_parts,
                'substance' => $sub,
                'similarity' => $sim,
                'all_similarities'  => array
                (
                    Mol_similarity::TANIMOTO => Mol_similarity::compute($substance, $sub, Mol_similarity::TANIMOTO),
                    Mol_similarity::COSINE => Mol_similarity::compute($substance, $sub, Mol_similarity::COSINE),
                    Mol_similarity::DICE => Mol_similarity::compute($substance, $sub, Mol_similarity::DICE),
                ),
            );
        }

        // Order by similarities
        array_multisort($similarities, SORT_DESC, $options);

        return $options;
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
     * Gets all ids of membranes and methods, with available energy data
     * 
     * @param int $id_substance
     * 
     * @return array
     * @throws Exception
     */
    public function get_available_energy($id_substance = null)
    {
        if(!$id_substance && !$this && !$this->id)
        {
            throw new Exception('Invalid id_substance parameter');
        }

        if(!$id_substance)
        {
            $id_substance = $this->id;
        }

        $all = $this->queryAll("SELECT id, CONCAT(id_membrane, ',', id_method) as ids, COUNT(*) as total
            FROM energy
            WHERE id_substance = ?
            GROUP BY (ids)
            ORDER BY ids ASC
        ", array($id_substance));

        $result = [];

        foreach($all as $row)
        {
            $ids = explode(',', $row->ids);

            $mem = new Membranes($ids[0]);
            $met = new Methods($ids[1]);
            $id = $row->id;
            $total = $row->total;

            if(!$mem->id || !$met->id || $total < 20)
            {
                continue;
            }

            if(!isset($result[$met->id]))
            {
                $result[$met->id] = [];
            }

            $result[$met->id][] = new Iterable_object([
                'id_energy' => $id,
                'id_membrane' => $mem->id,
                'membrane_name' => $mem->name,
                'id_method'   => $met->id,
                'method_name' => $met->name
            ]);
        }

        return $result;
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
                $chembl->state = $vi::STATE_INVALID;
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
                $drugbank->state = $vi::STATE_INVALID;
                $drugbank->save();

                $drugbank = null; 
            }
            catch(Exception $e)
            {
                
            }
        }

        if($name && $name->id)
        {
            $this->name = $name->value;
        }
        else
        {
            $this->name = $this->identifier;
            $exists = $vi->where(array
            (
                'id_substance'  => $this->id,
                'identifier'    => $vi::ID_NAME,
                'value'         => $this->identifier 
            ))->get_one();

            if(!$exists->id)
            {
                $exists = new Validator_identifiers();
                $exists->id_substance = $this->id;
                $exists->id_source = NULL;
                $exists->server = NULL;
                $exists->identifier = $vi::ID_NAME;
                $exists->id_user = NULL;
                $exists->value = $this->identifier;
            }

            $exists->state = $vi::STATE_VALIDATED;
            $exists->active = $vi::ACTIVE;

            $exists->save();
        }
        
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
     * Returns active identifiers
     * 
     * @return object
     */
    public function get_active_identifiers()
    {
        if(!$this || !$this->id)
        {
            return [];
        }

        $vi = new Validator_identifiers();

        $ids = $vi->get_all_active_substance_values($this->id);

        $identifiers = [];

        foreach($ids as $id => $obj)
        {
            if(!$obj || !$obj->id)
            {
                $identifiers[$id] = null;
            }

            $validation = Validator_identifier_validations::instance()->where(
                array
                (
                    'id_validator_identifier' => $obj->id,
                ))
                ->order_by('datetime', 'DESC')
                ->get_one();

            if(!$validation->id || $validation->state !== $obj->state)
            {
                $validation = null;
            }

            $identifiers[$id] = (object) array
            (
                'id' => $obj->id,
                'value' => $obj->value,
                'enum_identifier' => Validator_identifiers::get_enum_identifier($obj->identifier),
                'source' => !$obj->id_source ? null : array
                (
                    'id' => $obj->source->id,
                    'identifier' => Validator_identifiers::get_enum_identifier($obj->source->identifier),
                    'value' => $obj->source->value,
                    'state' => $obj->source->state,
                    'active' => $obj->source->active
                ),
                'text_source' => !$obj->source ? 'Presented in the original dataset.' : (
                    $obj->id_user ? 
                    "Manually obtained from " . Validator_identifiers::get_enum_server($obj->server) . "." :
                    "Automatically obtained from " . Validator_identifiers::get_enum_server($obj->server) . "."
                ),
                'state' => $obj->state,
                'active' => $obj->active,
                'server' => Validator_identifiers::get_enum_server($obj->server),
                'validation' => !$validation ? null : array
                (
                    'message' => $validation->message,
                    'state'   => $validation->state,
                    'id_user' => $validation->id_user
                )
            );
        }

        return $identifiers;
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

        $where = [
            'validator_structure.id_substance' => $this->id,
            'vi.state'     => Validator_identifiers::STATE_VALIDATED
        ];

        if($server)
        {
            $where['validator_structure.server'] = $server;
        }

        $vs = new Validator_structure();

        $record = $vs->where($where)
            ->select_list('validator_structure.*')
            ->join('JOIN validator_identifiers vi ON vi.id = validator_structure.id_source')
            ->join('JOIN files f ON f.id_validator_structure = validator_structure.id')
            ->order_by('is_default DESC, datetime', 'DESC')
            ->get_one();

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
     * Returns substance 3D structures
     * 
     * @return Files
     */
    public function get_all_3D_structures()
    {
        if(!$this || !$this->id)
        {
            return [new Files()];
        }

        $vs = new Validator_structure();

        $records = $vs->where('validator_structure.id_substance', $this->id)
            ->select_list('validator_structure.*')
            ->distinct()
            ->join('JOIN validator_identifiers vi ON vi.id = validator_structure.id_source')
            ->join('JOIN files f ON f.id_validator_structure = validator_structure.id')
            ->order_by('is_default DESC, datetime', 'DESC')
            ->get_all();

        $result = [];

        foreach($records as $record)
        {
            if(isset($result[$record->server]))
            {
                continue;
            }

            foreach($record->files as $f)
            {
                if($f->file_exists())
                {
                    $result[$record->server] = $f->as_array();
                    break;
                }
            }
        }

        return $result;
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
        if(strlen($query) < 5)
        {
            return $this->queryAll('
                SELECT DISTINCT s.name, s.identifier, s.id, s.SMILES, s.MW, s.pubchem, s.drugbank, s.chEBI, s.pdb, s.chEMBL
                FROM substances s
                JOIN (SELECT DISTINCT id_substance
                    FROM substances_fragments sf 
                    JOIN fragments f ON f.id = sf.id_fragment AND f.smiles LIKE ?) as t ON s.id = t.id_substance 
                ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
                LIMIT ?,?', array($query,($pagination-1)*10,10));
        }

        $fragment = Fragments::instance()->where('smiles', $query)->get_one();

        if($fragment->id)
        {
            $ids = Fragments_options::instance()->get_all_options_ids($fragment->id);

            return $this->queryAll('
                SELECT DISTINCT s.name, s.identifier, s.id, s.SMILES, s.MW, s.pubchem, s.drugbank, s.chEBI, s.pdb, s.chEMBL
                FROM substances s
                JOIN substances_fragments sf ON sf.id_substance = s.id AND sf.id_fragment IN ("' . implode('","', $ids) . '")
                ORDER BY sf.similarity DESC, IF(name RLIKE "^[a-z]", 1, 2) ASC, name ASC
                LIMIT ?,?', array(($pagination-1)*10,10));
        }

        $query_all = '%' . $query . '%';

        return $this->queryAll('
            SELECT DISTINCT s.name, s.identifier, s.id, s.SMILES, s.MW, s.pubchem, s.drugbank, s.chEBI, s.pdb, s.chEMBL, t.exact
            FROM substances s
            JOIN (SELECT DISTINCT id_substance, IF(f.smiles = ?, 100/sf.total_fragments, 0) as exact
                FROM substances_fragments sf 
                JOIN fragments f ON f.id = sf.id_fragment AND f.smiles LIKE ?) as t ON s.id = t.id_substance 
            ORDER BY exact DESC, IF(name RLIKE "^[a-z]", 1, 2) ASC, name ASC
            LIMIT ?,?', array($query, $query_all,($pagination-1)*10,10));
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
        if(strlen($query) < 5)
        {
            return $this->queryOne('
                SELECT COUNT(s.id) as count
                FROM substances s
                JOIN (SELECT DISTINCT id_substance
                    FROM substances_fragments sf 
                    JOIN fragments f ON f.id = sf.id_fragment AND f.smiles LIKE ?) as t ON s.id = t.id_substance 
                ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
                ', array($query))->count;
        }

        $fragment = Fragments::instance()->where('smiles', $query)->get_one();

        if($fragment->id)
        {
            $ids = Fragments_options::instance()->get_all_options_ids($fragment->id);

            return $this->queryOne('
                SELECT COUNT(s.id) as count
                FROM substances s
                JOIN substances_fragments sf ON sf.id_substance = s.id AND sf.id_fragment IN ("' . implode('","', $ids) . '")
                ORDER BY sf.similarity DESC, IF(name RLIKE "^[a-z]", 1, 2) ASC, name ASC')->count;
        }

        $query_all = '%' . $query . '%';

        return $this->queryOne('
            SELECT COUNT(s.id) as count
            FROM substances s
            JOIN (SELECT DISTINCT id_substance, IF(f.smiles = ?, 100/sf.total_fragments, 0) as exact
                FROM substances_fragments sf 
                JOIN fragments f ON f.id = sf.id_fragment AND f.smiles LIKE ?) as t ON s.id = t.id_substance 
            ORDER BY exact DESC, IF(name RLIKE "^[a-z]", 1, 2) ASC, name ASC
            ', array($query, $query_all))->count;
        
        
    }
    
    /**
     * Search substances for given method name
     * 
     * @param string $query
     * @param integer $pagination
     * 
     * @return array
     */
    public function search_by_method($id, $pagination)
    {
        return $this->queryAll('
            SELECT DISTINCT name, identifier, s.id, SMILES, MW, pubchem, drugbank, chEBI, pdb, chEMBL
            FROM substances as s
            JOIN interaction as i ON s.id = i.id_substance
            WHERE i.id_method = ?
                AND i.visibility = ?
            ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
            LIMIT ?,?', array
            (
                $id,
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
    public function search_by_method_count($id)
    {
        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM(
                SELECT DISTINCT s.id
                FROM substances as s
                JOIN interaction as i ON s.id = i.id_substance
                WHERE i.id_method = ?
                    AND i.visibility = ?) as tab', 
            array
            (
                $id,
                Interactions::VISIBLE
            ))->count;
    }

    /**
     * Search substances for given membrane name
     * 
     * @param int $id
     * @param integer $pagination
     * 
     * @return array
     */
    public function search_by_membrane($id, $pagination)
    {
        return $this->queryAll('
            SELECT DISTINCT name, identifier, s.id, SMILES, MW, pubchem, drugbank, chEBI, pdb, chEMBL
            FROM substances as s
            JOIN interaction as i ON s.id = i.id_substance
            WHERE i.id_membrane = ?
                AND i.visibility = ?
            ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
            LIMIT ?,?', array
            (
                $id,
                Interactions::VISIBLE,
                ($pagination-1)*10,
                10
            ));
    }

    /**
     * Count substances according to the membrane name
     * 
     * @param int $id
     * 
     * @return integer
     */
    public function search_by_membrane_count($id)
    {
        return $this->queryOne('
            SELECT COUNT(*) as count
            FROM 
                (SELECT DISTINCT s.id 
                FROM substances as s
                JOIN interaction as i ON s.id = i.id_substance
                WHERE i.id_membrane = ?
                    AND i.visibility = ?) as tab',
            array
            (
                $id,
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

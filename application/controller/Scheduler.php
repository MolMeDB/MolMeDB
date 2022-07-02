<?php

/**
 * Scheduler controller for cron service
 * 
 * Should be accessed only from local machine
 * 
 * @author Jakub Juračka
 */
class SchedulerController extends Controller
{
    /**
     * Constructor
     */
    function __construct()
    {
        parent::__construct();
    }

    private $debug_DES = FALSE && DEBUG;
    private $debug_CID = FALSE && DEBUG;
    private $debug_CTD = FALSE && DEBUG;
    private $debug_FMD = FALSE && DEBUG;

    /**
     * Specifies publicly accessible functions
     */
    static $accessible = array
    (
        'run',
        'log_sub_changes',
        'link_functional_groups'
    );


    /**
     * Main scheduler funtion
     * 
     */
    public function run()
    {

        // Holds info about run time
        $time = $this->time = new Time();

        echo '--- RUN ' . $time->get_string() . " ---\n";
        try
        {
            if(!$this->config->get(Configs::S_ACTIVE))
            {
                echo 'Scheduler is not active.';
                return;
            }

            // TODO restrictions
            $this->match_functional_pairs();
            // $this->link_functional_groups();
            die;

            // Send all unsent emails
            if($this->config->get(Configs::EMAIL_ENABLED))
            {
                $this->send_emails();
                echo "Emails sent. \n";
            }

            // Delete substances without interactions
            if($this->debug_DES || 
                ($this->check_time($this->config->get(Configs::S_DELETE_EMPTY_SUBSTANCES_TIME)) && 
                $this->config->get(Configs::S_DELETE_EMPTY_SUBSTANCES)))
            {
                echo "Deleting empty substances. \n";
                $this->delete_empty_substances();
            }

            // Checks interactions datasets
            if($this->debug_CID || 
                ($this->check_time($this->config->get(Configs::S_VALIDATE_PASSIVE_INT_TIME)) && 
                $this->config->get(Configs::S_VALIDATE_PASSIVE_INT)))
            {
                echo "Checking interaction datasets.\n";
                $this->check_interaction_datasets();
            }

            // Fragment molecules
            if($this->check_time("06:00"))
            {
                echo "Checking interaction datasets.\n";
                $this->fragment_molecules();
                $this->link_functional_groups();
            }

            // Checks transporter datasets - NOW INACTIVATED
            if($this->debug_CTD ||
                ($this->check_time($this->config->get(Configs::S_VALIDATE_ACTIVE_INT_TIME)) && 
                $this->config->get(Configs::S_VALIDATE_ACTIVE_INT)))
            {
                // TODO
                // $this->check_transporter_datasets();
                // echo "Transporter datasets checked.\n";
            }

            // ---- Molecules data autofill ---- //
            if($this->debug_FMD || 
                ($this->check_time($this->config->get(Configs::S_VALIDATE_SUBSTANCES_TIME)) && 
                $this->config->get(Configs::S_VALIDATE_SUBSTANCES))) // Run only once per hour and only at night
            {
                echo "Subtance validations is running. \n";
                $this->validate_substance_identifiers(); // PARAM TODO
            }

            // Once per day, update stats data
            if(($this->check_time($this->config->get(Configs::S_UPDATE_STATS_TIME)) && 
                $this->config->get(Configs::S_UPDATE_STATS)))
            {
                echo "Updating stats data. \n";
                $this->update_stats();
            }

            // Once per day, update stats data
            if(($this->check_time($this->config->get(Configs::S_CHECK_PUBLICATIONS_TIME)) && 
                $this->config->get(Configs::S_CHECK_PUBLICATIONS)))
            {
                echo "Updating publications data. \n";
                $this->update_publications();
                echo "Deleting empty publications. \n";
                $this->delete_empty_publications();
            }

            // Delete empty membranes and methods
            if(($this->check_time($this->config->get(Configs::S_CHECK_MEMBRANES_METHODS_TIME)) && 
                $this->config->get(Configs::S_CHECK_MEMBRANES_METHODS)))
            {
                echo "Deleting empty membranes/methods. \n";
                $this->delete_empty_membranes_methods();
            }

            echo "DONE \n";
        }
        catch(Exception $e)
        {
            $text = "General error occured while scheduler was running.<br/><br/>" . $e->getMessage();
            echo $text . ' in ' . $time->get_string();

            try
            {
                $report = new File();
                $filePath = MEDIA_ROOT . 'files/schedulerReports/general_' . $report->generateName() . '.log';
                $report->create($filePath);
                $report->writeLine($e->getMessage());
            }
            catch(Exception $e)
            {
                echo 'Cannot make report file - ' . $e->getMessage();
                $this->send_email_to_admins($text, 'Scheduler error');
                return;
            }
            
            $this->send_email_to_admins($text, 'Scheduler error', $filePath);
        }

        echo '-------------------------';
    }

    /**
     * Match all molecular functional group pairs
     * 
     * @author Jakub Juracka
     */
    public function match_functional_pairs()
    {
        // Check, if func groups are linked
        $total = Fragments_enum_types::instance()->count_all();

        if(!$total)
        {
            $this->link_functional_groups();
        }

        // check 10 molecules per run
        $limit = 100;

        $last_id = Config::get('scheduler_func_pairs_last_id');

        if(!$last_id)
        {
            $last_id = 1;
        }

        $all = Substances::instance()->where('id >=', $last_id)->limit($limit)->select_list('id')->get_all();

        try
        {
            foreach($all as $r)
            {
                $r->save_functional_relatives();
                Config::set('scheduler_func_pairs_last_id', $r->id);
            }
        }
        catch(Exception $e) // TODO
        {
            echo $e->getMessage();
        }
    }

    /**
     * Links all functional groups to their corresponding molecular fragments
     * 
     * @author Jakub Juracka
     */
    public function link_functional_groups()
    {
        // Do it iteratively
        $func_groups = Enum_types::get_by_type(Enum_types::TYPE_FRAGMENT_CATS);

        if(!$func_groups)
        {
            return;
        }

        foreach($func_groups as $fg)
        {
            if($fg->content === NULL || $fg->content == "")
            {
                continue;
            }

            $fg->content = str_replace('\\', '\\\\', $fg->content);

            // Find all fragments matching pattern
            $fragments = Fragments::instance()->where('smiles REGEXP', $fg->content)->get_all();

            // Save links
            foreach($fragments as $f)
            {
                // Skip if exists
                $exists = Fragments_enum_types::instance()->where(array
                    (
                        'id_fragment' => $f->id,
                        'id_enum_type' => $fg->id
                    ))
                    ->get_one();

                if($exists->id)
                {
                    continue;
                }

                $new = new Fragments_enum_types();

                $new->id_fragment = $f->id;
                $new->id_enum_type = $fg->id;

                $new->save();
            }
        }
    }


    public function log_sub_changes()
    {
        // Deletes
        $deletes = [
            'found different',
            'filled pubchem_logp value',
            'waiting for validation',
            'found SMILES',
            'found possible duplicity',
            'labeled as non duplicity',
            'SQLSTATE',
            'was validated',
            'cannot find',
            'is unreachable',
            'found 3d structure on'
        ];

        foreach($deletes as $wh)
        {
            Scheduler_errors::instance()->query("DELETE FROM `scheduler_errors` WHERE error_text LIKE '%$wh%'");
        }

        $changes = Scheduler_errors::instance()->where('error_text LIKE', "%on remote server%")->order_by('id_substance ASC, last_update', 'DESC')->get_all();

        $links = [
            'chebi' => 'chEBI',
            'drugbank_id' => 'drugbank',
            'chembl_id' => 'chEMBL',
            'pdb_id' => 'pdb',
            'pubchem_id' => 'pubchem',
        ];

        foreach($changes as $c)
        {
            $s = new Substances($c->id_substance);

            preg_match('/Found (.*) = (.*) on remote server./', $c->error_text, $all);

            if(count($all) < 3)
            {
                continue;
            }

            $src = $all[1];
            $val = $all[2];

            $attr = $links[$src];

            if($s->$attr == $val)
            {
                $s->$attr = NULL;
                $s->save();
                $c->delete();
            }
            else
            {
                $c->delete();
            }
        }

        // Name changes
        $rows = Scheduler_errors::instance()->where('error_text LIKE', "%found on%")->order_by('id_substance ASC, last_update', 'DESC')->get_all();

        foreach($rows as $r)
        {
            $s = new Substances($r->id_substance);

            $s->name = $s->identifier;
            $s->save();

            $r->delete();
        }

        // CHEMBL ID in name
        $rows = Scheduler_errors::instance()->where('error_text LIKE', "%found chembl_id in molecule name%")->order_by('id_substance ASC, last_update', 'DESC')->get_all();

        foreach($rows as $r)
        {
            $s = new Substances($r->id_substance);

            preg_match("/in molecule name = (.+)\./", $r->error_text, $all);

            if(count($all) < 2)
            {
                continue;
            }

            $d = $all[1];

            if(!$d)
            {
                continue;
            }

            $s->name = $d;
            $s->chEMBL = NULL;
            $s->save();

            $r->delete();
        }

        // Changes
        $rows = Scheduler_errors::instance()->where('error_text LIKE', "%changes - [%")->order_by('id_substance ASC, last_update', 'DESC')->get_all();

        foreach($rows as $r)
        {
            $s = new Substances($r->id_substance);

            preg_match('/Changes - \[([a-z]+): (.*) => (.*)\]/i', $r->error_text, $all);

            if(count($all) < 3)
            {
                continue;
            }

            $attr = $all[1];
            $from = $all[2] !== "NULL" ? $all[2] : NULL;
            $to   = $all[3] !== "NULL" ? $all[3] : NULL;

            if(strtolower($s->$attr) == strtolower($to))
            {
                $s->$attr = $from;
                $s->save();
            }

            $r->delete();
        }

        ##### NOW, SAVE ALL IDENTIFIER OF ALL MOLECULES AS UPLOADED #######
        $substnaces = Substances::instance()->queryAll('
            SELECT s.*
            FROM substances s
            WHERE s.id NOT IN (
                SELECT DISTINCT id_substance 
                FROM validator_identifiers
                )
        ');
        $vi = new Validator_identifiers();

        foreach($substnaces as $s)
        {
            $s = new Substances($s->id);

            // Remove inchikey
            $s->inchikey = NULL;
            $s->save();

            if(!$vi->init_substance_identifiers($s))
            {
                echo "ERROR OCCURED: " . $s->id . ' / ' . $s->name . '.';
                die;
            }
        }

        // Delete old 3D structures
        $t = scandir(File::FOLDER_3DSTRUCTURES);
        
        foreach($t as $f)
        {
            if(is_dir(File::FOLDER_3DSTRUCTURES . $f) || !preg_match('/\.mol$/', $f))
            {
                continue;
            }

            unlink(File::FOLDER_3DSTRUCTURES . $f);
        }

        die;
    }


    /**
     * Checks time
     * 
     * @param string $time
     * 
     * @return boolean
     */
    public function check_time($time)
    {
        if(!$this->time)
        {
            $this->time = new Time();
        }

        $times = explode('|', $time);

        foreach($times as $time)
        {
            if(!$time || strpos($time, ":") === FALSE)
            {
                continue;
            }

            $time = trim($time);

            $time = explode(':', $time);
            $h = $time[0];
            $m = $time[1];

            if(!is_numeric($h) && !is_numeric($m))
            {
                if(strtolower($h) == 'xx' && strtolower($m) == 'xx')
                {
                    return true;
                };

                continue;
            }
            
            if(!is_numeric($h))
            {
                $m = intval($m);
                if($this->time->is_minute($m))
                {
                    return true;
                }

                continue;
            }

            if(!is_numeric($m))
            {
                $h = intval($h);
                if($this->time->is_hour($h))
                {
                    return true;
                }

                continue;
            }

            $m = intval($m);
            $h = intval($h);

            if($this->time->is_minute($m) && $this->time->is_hour($h))
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Fragments molecules
     * 
     * @author Jakub Juracka
     */
    public function fragment_molecules()
    {
        // Get non-fragmented substances
        $sf_model = new Substances_fragments();

        $substances = $sf_model->get_non_fragmented_substances(10000);

        $rdkit = new Rdkit();

        foreach($substances as $s)
        {
            try
            {
                Db::beginTransaction();

                $fragments = $rdkit->fragment_molecule($s->SMILES);

                if(!$fragments)
                {
                    Db::commitTransaction();
                    continue;
                }

                // add molecule smiles as new fragment
                $new = new Fragments();
                $new->smiles = $s->SMILES;
                $new->save();
                
                $exists = Substances_fragments::instance()
                    ->where(array
                    (
                        "id_substance" => $s->id,
                        'id_fragment'   => $new->id
                    ))->get_one();

                
                if(!$exists->id)
                {
                    $f = new Substances_fragments();
                    $f->id_substance = $s->id;
                    $f->id_fragment = $new->id;
                    $f->total_fragments = 1;
                    $f->links = null;
                    $f->order_number = $sf_model->get_free_order_number($s->id);
                    $f->similarity = 1.0;
                    $f->save();
                }

                $metric = Rdkit::METRIC_DEFAULT;

                foreach($fragments as $record)
                {
                    $order = $sf_model->get_free_order_number($s->id);
                    $all = $record->fragments;

                    // Save fragments
                    $new_fragments = [];
                    $new_fragment_ids = [];
                    $new_links = [];
                    $similarities = [];

                    foreach($all as $f)
                    {
                        $new = new Fragments();

                        $data = $new->prepare_fragment($f->smiles);

                        if(!$f->smiles)
                        {
                            continue;
                        }

                        if(!$data)
                        {
                            throw new Exception('Invalid fragment - ' . $f->smiles);
                        }

                        $new->smiles = $data->smiles;
                        $new->save();

                        $new_fragments[] = $new;
                        $new_fragment_ids[] = $new->id;
                        $new_links[] = $data->links;
                        $similarities[] = $f->similarity->$metric;
                    }

                    // Check, if already exists
                    if(!count($new_fragment_ids) || $sf_model->fragmentation_exists($s->id, $new_fragment_ids))
                    {
                        continue;
                    }

                    foreach($new_fragments as $key => $new)
                    {
                        // save molecule fragment
                        $fragment = new Substances_fragments();

                        $fragment->id_substance = $s->id;
                        $fragment->id_fragment = $new->id;
                        $fragment->total_fragments = count($new_fragments);
                        $fragment->links = $new_links[$key];
                        $fragment->order_number = $order;
                        $fragment->similarity = $similarities[$key];
                        $fragment->save();
                    }
                }

                Db::commitTransaction();
            }
            catch(Exception $e)
            {
                Db::rollbackTransaction();
                echo $e;
                die;
            }
        }
    }


    /**
     * Tries to find duplicities in DB
     * 
     * @author Jakub Juracka
     */
    public function find_substance_duplicities()
    {
        return; // TODO
        $vi_model = new Validator_identifiers();

        $duplicities = $vi_model->get_duplicate_identifiers();

        foreach($duplicities as $dupl)
        {
            try
            {
                Db::beginTransaction();

                $records = [];

                $ids_substance = $dupl->id_substances;

                asort($ids_substance);

                foreach($ids_substance as $id_substance)
                {
                    $r = $vi_model->where(array
                        (
                            'id_substance'  => $id_substance,
                            'value LIKE'    => $dupl->value
                        ))
                        ->order_by('active DESC, create_date', 'DESC')
                        ->get_one();

                    if(!$r->id)
                    {
                        throw new Exception("Invalid validator identifier record - " . $dupl->value . " . / " . $id_substance . "<br/>");
                    }

                    if(isset($records[$r->identifier]))
                    {
                        $records[$r->identifier][] = $r;
                    }
                    else
                    {
                        $records[$r->identifier] = [$r];
                    }
                }

                foreach($records as $recs)
                {
                    $total = count($recs);

                    if($recs < 2)
                    {
                        continue;
                    }

                    // $total x $total-1 records add
                    for($i = 0; $i < $total; $i++)
                    {
                        for($k = $i+1; $k < $total; $k++)
                        {
                            $v1 = $recs[$i];
                            $v2 = $recs[$k];

                            if($v1->id_substance == $v2->id_substance)
                            {
                                continue;
                            }

                            // Exists?
                            $exists = Validator_identifier_duplicities::instance()->where(
                                array
                                (
                                    'id_validator_identifier_1' => $v1->id,
                                    'id_validator_identifier_2' => $v2->id,
                                ))
                                ->get_one();

                            if($exists->id)
                            {
                                continue;
                            }

                            
                            // Add new record
                            $new = new Validator_identifier_duplicities();
                            $non_dupl = $new->is_non_duplicity($v1->is_substance, $v2->id_substance);

                            $new->id_validator_identifier_1 = $v1->id;
                            $new->id_validator_identifier_2 = $v2->id;
                            $new->state = $non_dupl ? $new::STATE_NON_DUPLICITY : $new::STATE_NEW;
                            $new->id_user = NULL;

                            $new->save();
                        }
                    }
                }

                Db::commitTransaction();
            }
            catch(Exception $e)
            {
                Db::rollbackTransaction();
            }
        }
    }


    /**
     * Main substance identifier validator
     * Validates 10,000 molecules per each run including:
     *  1)
     *  2)
     *  3)
     * 
     * 
     * @author Jakub Juracka
     */
    public function validate_substance_identifiers($include_ignored = FALSE)
    {
        $logs = new Validator_identifier_logs();
        
        $max_cores = Config::get('scheduler_substance_validation_max_cores');
        $max_cores = $max_cores && is_numeric($max_cores) ? (int) $max_cores : 1;
    
        $running_cores = (int)Config::get('scheduler_substance_validation_running_cores');

        if(!$running_cores)
        {
            $new_order = 1;
        }
        else if($running_cores >= $max_cores && !DEBUG)
        {
            echo 'Max number of runs reached.';
            return;
        }
        else if(!DEBUG)
        {
            $new_order = $running_cores+1;
        }

        Config::set('scheduler_substance_validation_running_cores', $new_order);

        $limit = 1000;
        $offset = $new_order ? ($new_order-1)*$limit : 0;

        try
        {
            $substances = $logs->get_substances_for_validation($limit, $offset, TRUE, $include_ignored);

            if(!count($substances))
            {
                Config::set('scheduler_substance_validation_running_cores', $new_order-1);
                return;
            }
        }
        catch(Exception $e)
        {
            Config::set('scheduler_substance_validation_running_cores', $new_order-1);
            return;
        }

        $val_identifiers = new Validator_identifiers();

        // Remote servers
        $remote_servers = array
        (
            Validator_identifiers::SERVER_PUBCHEM => new Pubchem(),
            Validator_identifiers::SERVER_UNICHEM => new UniChem(),
            Validator_identifiers::SERVER_CHEMBL => new Chembl(),
            Validator_identifiers::SERVER_DRUGBANK => new Drugbank(),
            Validator_identifiers::SERVER_RDKIT => new Rdkit(),
            Validator_identifiers::SERVER_PDB   => new PDB(),
            Validator_identifiers::SERVER_CHEBI => null
        );

        $rdkit = $remote_servers[Validator_identifiers::SERVER_RDKIT];

        $logs_arr = array
        (
            $logs::TYPE_LOCAL_IDENTIFIERS => null,
            $logs::TYPE_SMILES => null,
            $logs::TYPE_INCHIKEY => null,
            $logs::TYPE_LOGP_MW => null,
            $logs::TYPE_REMOTE_IDENTIFIERS => null,
            $logs::TYPE_3D_STRUCTURE => null,
            $logs::TYPE_NAME_BY_IDENTIFIERS => null,
            $logs::TYPE_TITLE => null
        );

        foreach($substances as $s)
        {
            try
            {
                $s = new Substances($s->id);

                $s->check_identifiers();

                // Fill logs
                foreach($logs_arr as $type => $ob)
                {
                    $logs_arr[$type] = $logs->get_current_by_type($s->id, $type);
                    $logs_arr[$type]->type = $type;
                }

                $log = $logs_arr[$logs::TYPE_LOCAL_IDENTIFIERS];

                if(!$log->is_done())
                {
                    try
                    {
                        // At first, check if substance has identifier
                        if(!$s->identifier)
                        {
                            $s->identifier = Identifiers::generate_substance_identifier($s->id);
                            $s->save();
                        }

                        // Check, if name contains some identifier
                        $s->check_identifiers_in_name();

                        // Check uppercase of first letter in name
                        $name = $val_identifiers->get_active_substance_value($s->id, Validator_identifiers::ID_NAME);
                        $name->value = ucwords($name->value);
                        $name->save();

                        $log->update_state($s->id, $log->type, $log::STATE_DONE);
                    }
                    catch(Exception $e)
                    {
                        if($log->state == $log::STATE_ERROR || $log->state == $log::STATE_MULTIPLE_ERROR)
                        {
                            $count = $log->count ? $log->count : 0;
                            $new_state = $log::STATE_ERROR;
                            $count++;

                            if($count > 2)
                            {
                                $new_state = $log::STATE_MULTIPLE_ERROR;
                            }

                            $log->update_state($s->id, $log->type, $new_state, $e->getMessage(), $count);
                        }
                        else 
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage(), 1);
                        }
                    }   
                }

                // Check SMILES validity
                $log = $logs_arr[$logs::TYPE_SMILES];

                if(!$log->is_done())
                {
                    try
                    {
                        $smiles_o = $val_identifiers->get_active_substance_value($s->id, $val_identifiers::ID_SMILES);

                        if($smiles_o->id) // Has SMILES?
                        {
                            // Try canonize 
                            if($smiles_o->flag !== Validator_identifiers::SMILES_FLAG_CANONIZED && $rdkit->is_reachable())
                            {
                                $canonized = $rdkit->canonize_smiles($smiles_o->value);

                                if($canonized === false) // If cannot be cannonized, set as non-active
                                {
                                    $smiles_o->active = $smiles_o::INACTIVE;
                                    $smiles_o->flag = Validator_identifiers::SMILES_FLAG_CANONIZATION_ERROR;
                                    $smiles_o->active_msg = 'Cannot canonize SMILES by RDKIT.';
                                    $smiles_o->save();

                                    throw new Exception('Cannot canonize SMILES by RDKIT.');
                                }
                                else if($canonized && $canonized !== $smiles_o->value)
                                {
                                    $new = new Validator_identifiers();

                                    $new->id_substance = $s->id;
                                    $new->id_source = $smiles_o->id;
                                    $new->identifier = Validator_identifiers::ID_SMILES;
                                    $new->server = Validator_identifiers::SERVER_RDKIT;
                                    $new->value = $canonized;
                                    $new->flag = Validator_identifiers::SMILES_FLAG_CANONIZED;
                                    $new->id_user = NULL;
                                    $new->state = Validator_identifiers::STATE_VALIDATED;
                                    $new->active = Validator_identifiers::ACTIVE;
                                    $new->active_msg = 'Canonized by RDkit.';
                                    $new->save();

                                    $log->update_state($s->id, $log->type, $log::STATE_DONE);
                                }
                                else if($canonized && $canonized === $smiles_o->value)
                                {
                                    $smiles_o->flag = Validator_identifiers::SMILES_FLAG_CANONIZED;
                                    $smiles_o->state = $smiles_o::STATE_VALIDATED;
                                    $smiles_o->state_msg = 'Validated and canonized by RDkit.';
                                    $smiles_o->save();

                                    $log->update_state($s->id, $log->type, $log::STATE_DONE);
                                }
                            }
                            
                            // Save structure fingerprint
                            if(!$s->fingerprint)
                            {
                                $fp = $rdkit->get_fingerprint($s);

                                if($fp !== false)
                                {
                                    // Encode FP
                                    $encoded = Mol_fingerprint::encode($fp);
                                    // Save
                                    $s->fingerprint = $encoded;
                                    $s->save();
                                }
                            }
                            
                            if($smiles_o->flag == Validator_identifiers::SMILES_FLAG_CANONIZED && $s->fingerprint)
                            {
                                $log->update_state($s->id, $log->type, $log::STATE_DONE);
                            }
                        }
                        else // No SMILES? 
                        {
                            $source = NULL;
                            $canonized = false;
                            $server_id = null;

                            foreach(Validator_identifiers::$identifier_servers[Validator_identifiers::ID_SMILES] as $id)
                            {
                                $server_id = $id;
                                $server = $remote_servers[$id];

                                if(!$server || !$server->is_reachable())
                                {
                                    continue;
                                }

                                $smiles = $server->get_smiles($s);

                                $source = $val_identifiers->find_source($s->id, $server->last_identifier);

                                if(!$source || $source->state == Validator_identifiers::STATE_NEW)
                                {
                                    continue;
                                }

                                if($smiles)
                                {
                                    // Canonize SMILES if RDKIT is accessible
                                    if($rdkit->is_reachable())
                                    {
                                        $r = $rdkit->canonize_smiles($smiles);
                                        if($r !== false)
                                        {
                                            $smiles = $r;
                                            $canonized = true;
                                        }
                                    }
                                }

                                if($smiles)
                                {
                                    break;
                                }

                                $canonized = false;
                            }

                            if($source && $source->id && $smiles)
                            {
                                $new = new Validator_identifiers();

                                $new->id_substance = $s->id;
                                $new->id_source = $source->id;
                                $new->identifier = Validator_identifiers::ID_SMILES;
                                $new->server = $server_id;
                                $new->value = $smiles;
                                $new->flag = $canonized ? Validator_identifiers::SMILES_FLAG_CANONIZED : Validator_identifiers::SMILES_FLAG_CANONIZATION_ERROR;
                                $new->id_user = NULL;
                                $new->state = $source->is_credible($server_id) ? Validator_identifiers::STATE_VALIDATED : Validator_identifiers::STATE_NEW;
                                $new->active = $new->state == $source::STATE_VALIDATED ? $source::ACTIVE : $source::INACTIVE;

                                $new->save();

                                $log->update_state($s->id, $log->type, $new->flag == Validator_identifiers::SMILES_FLAG_CANONIZED ? $log::STATE_DONE : $log::STATE_WAITING);
                            }
                        }
                    }
                    catch(Exception $e)
                    {
                        echo $e->getMessage();
                        if($log->state == $log::STATE_ERROR || $log->state == $log::STATE_MULTIPLE_ERROR)
                        {
                            $count = $log->count ? $log->count : 0;
                            $new_state = $log::STATE_ERROR;
                            $count++;

                            if($count > 2)
                            {
                                $new_state = $log::STATE_MULTIPLE_ERROR;
                            }

                            $log->update_state($s->id, $log->type, $new_state, $e->getMessage(), $count);
                        }
                        else 
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage(), 1);
                        }
                    } 
                }

                ##################################
                #### END OF SMILES VALIDATION ####
                ##################################

                #######################
                #### Find inchikey ####
                #######################
                $log = $logs_arr[$log::TYPE_INCHIKEY];

                if(!$log->is_done())
                {
                    try
                    {
                        $inchikey_o = $val_identifiers->get_active_substance_value($s->id, $val_identifiers::ID_INCHIKEY);

                        if(!$inchikey_o->id || $inchikey_o->state == $inchikey_o::STATE_NEW)
                        {
                            foreach(Validator_identifiers::$identifier_servers[Validator_identifiers::ID_INCHIKEY] as $server_id)
                            {
                                $server = $remote_servers[$server_id];

                                if(!$server || !$server->is_reachable())
                                {
                                    continue;
                                }

                                $new_inchikey = $server->get_inchikey($s);

                                $source = $val_identifiers->find_source($s->id, $server->last_identifier);

                                if(!$source || !$source->id || !$new_inchikey)
                                {
                                    continue;
                                }

                                $inchikey_o->id_substance = $s->id;
                                $inchikey_o->id_source = $source->id;
                                $inchikey_o->identifier = Validator_identifiers::ID_INCHIKEY;
                                $inchikey_o->server = $server_id;
                                $inchikey_o->value = $new_inchikey;
                                $inchikey_o->id_user = null;
                                $inchikey_o->state = $source->is_credible($server_id) ? $inchikey_o::STATE_VALIDATED : $inchikey_o::STATE_NEW;
                                $inchikey_o->active = $inchikey_o->state == $inchikey_o::STATE_VALIDATED ? $inchikey_o::ACTIVE : $inchikey_o::INACTIVE;
                                $inchikey_o->active_msg = "Verified by RDkit.";
                                $inchikey_o->state_msg = $inchikey_o->active_msg;
                                
                                $inchikey_o->save();

                                $log->update_state($s->id, $log->type, $log::STATE_DONE);
                                
                                break;
                            }
                        }
                        else
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_DONE);
                        }
                    }
                    catch(Exception $e)
                    {
                        if($log->state == $log::STATE_ERROR || $log->state == $log::STATE_MULTIPLE_ERROR)
                        {
                            $count = $log->count ? $log->count : 0;
                            $new_state = $log::STATE_ERROR;
                            $count++;

                            if($count > 2)
                            {
                                $new_state = $log::STATE_MULTIPLE_ERROR;
                            }

                            $log->update_state($s->id, $log->type, $new_state, $e->getMessage(), $count);
                        }
                        else 
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage(), 1);
                        }
                    }
                }

                ###################################
                #### END OF INCHIKEY VALIDATION ###
                ###################################
                
                ############################
                ### Get LogP,MW by RDKIT ###
                ############################
                $log = $logs_arr[$log::TYPE_LOGP_MW];

                if(!$log->is_done())
                {
                    try
                    {
                        $smiles_o = $val_identifiers->get_active_substance_value($s->id, $val_identifiers::ID_SMILES);

                        if($smiles_o->id && $rdkit->is_reachable())
                        {
                            $data = $rdkit->get_general_info($smiles_o->value);

                            if($data)
                            {
                                $s->MW = Upload_validator::get_attr_val(Upload_validator::MW, $data->MW);
                                $s->LogP = Upload_validator::get_attr_val(Upload_validator::LOG_P, $data->LogP);

                                $s->save();

                                $log->update_state($s->id, $log->type, $log::STATE_DONE);
                            }
                            else
                            {
                                throw new Exception('Cannot load data from RDkit server.');
                            }
                        }
                    }
                    catch(Exception $e)
                    {
                        if($log->state == $log::STATE_ERROR || $log->state == $log::STATE_MULTIPLE_ERROR)
                        {
                            $count = $log->count ? $log->count : 0;
                            $new_state = $log::STATE_ERROR;
                            $count++;

                            if($count > 2)
                            {
                                $new_state = $log::STATE_MULTIPLE_ERROR;
                            }

                            $log->update_state($s->id, $log->type, $new_state, $e->getMessage(), $count);
                        }
                        else 
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage(), 1);
                        }
                    }
                }

                ##################################
                #### End of LogP, MW validation ##
                ##################################

                /////////////////////////////////////////

                #########################################
                #### Find missing links to other DBs ####
                #########################################
                $log = $logs_arr[$log::TYPE_REMOTE_IDENTIFIERS];
                
                if(!$log->is_done())
                {
                    try
                    {
                        $exists = [];
                        // Iterate over attribute values
                        foreach($val_identifiers::$identifier_servers as $identifier => $servers)
                        {
                            if(!in_array($identifier, $val_identifiers::$independent_servers))
                            {
                                $exists[$identifier] = 1;
                                continue;
                            }

                            // Has already attr value?
                            $value = $val_identifiers->get_active_substance_value($s->id, $identifier);

                            if($value->id)
                            {
                                $exists[$identifier] = 1;
                                continue;
                            }

                            foreach($servers as $server_id)
                            {
                                $server = $remote_servers[$server_id];

                                if(!$server->is_reachable())
                                {
                                    continue;
                                }

                                $found_value = $server->get_by_type($s, $identifier);

                                if(!$found_value)
                                {
                                    continue;
                                }

                                $source = $val_identifiers->find_source($s->id, $server->last_identifier);

                                if(!$source || $source->state == $source::STATE_NEW)
                                {
                                    continue;
                                }

                                $new = new Validator_identifiers();

                                $new->id_substance = $s->id;
                                $new->id_source = $source->id;
                                $new->identifier = $identifier;
                                $new->server = $server_id;
                                $new->value = $found_value;
                                $new->state = $source->is_credible($server_id) ? $new::STATE_VALIDATED : $new::STATE_NEW;
                                $new->active = $new->state == $new::STATE_VALIDATED ? $new::ACTIVE : $new::INACTIVE;
                                
                                $new->save();

                                $exists[$identifier] = 1;

                                break;
                            }
                        }

                        if(count($exists) == count($val_identifiers::$identifier_servers))
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_DONE);
                        }
                    }
                    catch(Exception $e)
                    {
                        if($log->state == $log::STATE_ERROR || $log->state == $log::STATE_MULTIPLE_ERROR)
                        {
                            $count = $log->count ? $log->count : 0;
                            $new_state = $log::STATE_ERROR;
                            $count++;

                            if($count > 2)
                            {
                                $new_state = $log::STATE_MULTIPLE_ERROR;
                            }

                            $log->update_state($s->id, $log->type, $new_state, $e->getMessage(), $count);
                        }
                        else 
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage(), 1);
                        }
                    }
                }

                ######################################
                ##### END OF IDENTIFIER FINDING ######
                ######################################

                //////////////////////////////////////

                ######################################
                #### Find 3D structure if missing ####
                ######################################
                $log = $logs_arr[$log::TYPE_3D_STRUCTURE];
                $vs = new Validator_structure();

                if(!$vs->has_substance_structure($s->id) || !$log->is_done())
                {
                    $default = $s->has_default_3D_structure() ? 0 : 1;

                    $exists = [];
                    $accessible = [];

                    foreach(Validator_structure::$priority_sources as $server_id)
                    {
                        try
                        {
                            $exists_structure = $s->get_3D_structure($server_id);

                            $server = $remote_servers[$server_id];

                            if($exists_structure->id)
                            {
                                $exists[$server_id] = 1;
                                $accessible[$server_id] = 1;
                                continue;
                            }

                            if(!$server || !$server->is_reachable())
                            {
                                continue;
                            }

                            $accessible[$server_id] = 1;

                            $structure = $server->get_3d_structure($s);

                            if(!$structure)
                            {
                                continue;
                            }
                            
                            $source = $val_identifiers->find_source($s->id, $server->last_identifier);

                            if(!$source || $source->state != Validator_identifiers::STATE_VALIDATED)
                            {
                                continue;
                            }

                            Db::beginTransaction();

                            $path = File::FOLDER_3DSTRUCTURES . $s->identifier . '/';
                            $prefix = Validator_identifiers::get_enum_server($server_id) . '_';
                            $i = 1;

                            while(file_exists($path . $prefix . $i . '.mol'))
                            {
                                $i++;
                            }

                            $file_name = $prefix . $i . '.mol';

                            $file = new File();
                            $file->create($path . $file_name);
                            $file->write($structure);
                            $file->close();

                            $f = new Files();
                            $f = $f->add($file);

                            $new = new Validator_structure();

                            $new->id_substance = $s->id;
                            $new->id_source = $source->id;
                            $new->server = $server_id;
                            $new->id_user = null;
                            $new->is_default = $default; $default = 0;
                            
                            $new->save();

                            $f->id_validator_structure = $new->id;
                            $f->save();

                            Db::commitTransaction();

                            $exists[$server_id] = 1;
                            $file_name = null;
                        }
                        catch(Exception $e)
                        {
                            Db::rollbackTransaction();

                            if(isset($file_name) && file_exists($file_name))
                            {
                                unlink($file_name);
                            }
                            // LOG ERROR
                            echo $e->getMessage();
                        }
                    }

                    if(count($exists) == count($accessible))
                    {
                        $log->update_state($s->id, $log->type, $log::STATE_DONE);
                    }
                    else
                    {
                        $log->update_state($s->id, $log->type, $log::STATE_PROCESSING);
                    }
                }

                ###################################
                ### End of finding 3D structure ###
                ###################################

                ####################################
                ## Try to find TITLE as best name ##
                ####################################

                $log = $logs_arr[$log::TYPE_TITLE];

                if(!$log->is_done())
                {
                    try
                    {
                        $found = false;

                        $priority_methods = ['get_title', 'get_name'];

                        foreach($val_identifiers::$identifier_servers[$val_identifiers::ID_NAME] as $servers)
                        {
                            if(is_numeric($servers))
                            {
                                $servers = [$servers];
                            }

                            foreach($servers as $server_id)
                            {
                                $server = $remote_servers[$server_id];

                                if(!$server || !$server->is_reachable())
                                {
                                    continue;
                                }

                                foreach($priority_methods as $method)
                                {
                                    $found_value = $server->$method($s);

                                    // Check name validity
                                    $found_value = Upload_validator::get_attr_val(Upload_validator::NAME, $found_value);

                                    if($found_value)
                                    {
                                        break;
                                    }
                                }

                                if(!$found_value)
                                {
                                    continue;
                                }

                                $source = $val_identifiers->find_source($s->id, $server->last_identifier);

                                if(!$source || $source->state != $source::STATE_VALIDATED)
                                {
                                    continue;
                                }

                                $found = true;

                                $new = new Validator_identifiers();

                                $found_value = ucfirst(strtolower($found_value));

                                $new->id_substance = $s->id;
                                $new->id_source = $source->id;
                                $new->server = $server_id;
                                $new->identifier = $new::ID_NAME;
                                $new->value = $found_value;
                                $new->state = $source->is_credible($server_id) ? $new::STATE_VALIDATED : $new::STATE_NEW;
                                $new->active = $new->state == $new::STATE_VALIDATED ? $new::ACTIVE : $new::INACTIVE;
                                
                                $new->save();
                                break;
                            }

                            if($found)
                            {
                                $log->update_state($s->id, $log->type, $log::STATE_DONE);
                                break;
                            }
                        }
                    }
                    catch(Exception $e)
                    {
                        if($log->state == $log::STATE_ERROR || $log->state == $log::STATE_MULTIPLE_ERROR)
                        {
                            $count = $log->count ? $log->count : 0;
                            $new_state = $log::STATE_ERROR;
                            $count++;

                            if($count > 2)
                            {
                                $new_state = $log::STATE_MULTIPLE_ERROR;
                            }

                            $log->update_state($s->id, $log->type, $new_state, $e->getMessage(), $count);
                        }
                        else 
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage(), 1);
                        }
                    }
                }

                ///////////////////////

                #########################################
                ## Try to find new name by identifier ###
                #########################################
                $log = $logs_arr[$log::TYPE_NAME_BY_IDENTIFIERS];

                if(!$log->is_done())
                {
                    try
                    {
                        $name_o = $val_identifiers->get_active_substance_value($s->id, $val_identifiers::ID_NAME);
                        if(!$name_o->id || Identifiers::is_identifier($name_o->value))
                        {
                            $found = false;

                            foreach($val_identifiers::$identifier_servers[$val_identifiers::ID_NAME] as $servers)
                            {
                                if(is_numeric($servers))
                                {
                                    $servers = [$servers];
                                }

                                foreach($servers as $server_id)
                                {
                                    $server = $remote_servers[$server_id];

                                    if(!$server || !$server->is_reachable())
                                    {
                                        continue;
                                    }

                                    $found_value = $server->get_name($s);

                                    if(!$found_value)
                                    {
                                        continue;
                                    }

                                    $source = $val_identifiers->find_source($s->id, $server->last_identifier);

                                    if(!$source || $source->state != $source::STATE_VALIDATED)
                                    {
                                        continue;
                                    }

                                    $found = true;

                                    $new = new Validator_identifiers();

                                    $new->id_substance = $s->id;
                                    $new->id_source = $source->id;
                                    $new->server = $server_id;
                                    $new->identifier = $new::ID_NAME;
                                    $new->value = $found_value;
                                    $new->state = $source->is_credible($server_id) ? $new::STATE_VALIDATED : $new::STATE_NEW;
                                    $new->active = $new->state == $new::STATE_VALIDATED ? $new::ACTIVE : $new::INACTIVE;
                                    
                                    $new->save();
                                    break;
                                }

                                if($found)
                                {
                                    $log->update_state($s->id, $log->type, $log::STATE_DONE);
                                    break;
                                }
                            }
                        }
                        else
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_DONE);
                        }
                    }
                    catch(Exception $e)
                    {
                        if($log->state == $log::STATE_ERROR || $log->state == $log::STATE_MULTIPLE_ERROR)
                        {
                            $count = $log->count ? $log->count : 0;
                            $new_state = $log::STATE_ERROR;
                            $count++;

                            if($count > 2)
                            {
                                $new_state = $log::STATE_MULTIPLE_ERROR;
                            }

                            $log->update_state($s->id, $log->type, $new_state, $e->getMessage(), $count);
                        }
                        else 
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage(), 1);
                        }
                    }
                }

                ##########################
                ### End of name finder ###
                ##########################

                $s->check_identifiers();

            }
            catch(Exception $e)
            {
                // Save error
                echo $e->getMessage();
            }
        }

        $no = (int)Config::get('scheduler_substance_validation_running_cores');
        Config::set('scheduler_substance_validation_running_cores', $no > 0 ? $no-1 : 0);
    }


    /**
     * Updates stats data
     */
    public function update_stats()
    {
        $stats_model = new Statistics();
        $stats_model->update_all();
    }

    /**
     * Updates publications
     */
    public function update_publications()
    {
        $publication_model = new Publications();
        $all_publications = $publication_model->get_all();

        foreach($all_publications as $p)
        {
            $p->update_ref_stats();
        }
    }

    /**
     * Deletes empty publications
     */
    public function delete_empty_publications()
    {
        $publication_model = new Publications();
        $empty_publications = $publication_model->get_empty_publications();

        foreach($empty_publications as $p)
        {
            $p->delete();
        }
    }

    /**
     * Deletes old membranes and methods
     * 
     * @author Jakub Juracka
     */
    public function delete_empty_membranes_methods()
    {
        $membrane_model = new Membranes();
        $method_model = new Methods();

        $empty_membranes = $membrane_model->get_empty_membranes();
        $mepty_methods = $method_model->get_empty_methods();

        $del_membranes = $del_methods = 0;

        foreach($empty_membranes as $membrane)
        {
            try
            {
                $membrane->delete();
                $del_membranes++;
            }
            catch(Exception $e)
            {
                echo "Error occured while deleting membrane [" . $membrane->name . "].\n";
                echo $e->getMessage() . "\n";
            }
        }

        foreach($mepty_methods as $method)
        {
            try
            {
                $method->delete();
                $del_methods++;
            }
            catch(Exception $e)
            {
                echo "Error occured while deleting method [" . $method->name . "].\n";
                echo $e->getMessage() . "\n";
            }
        }

        if($del_membranes)
        {
            echo "Total $del_membranes membranes deleted.\n";
        }

        if($del_methods)
        {
            echo "Total $del_methods methods deleted.\n";
        }
    }


    /**
     * Delets substances without interactions
     */
    public function delete_empty_substances()
    {
        $subst_model = new Substances();
        $empty = $subst_model->get_empty_substances();
        $total = 0;
        $molecules = array(['Deleted molecules']);

        foreach($empty as $s_id)
        {
            $total++;
            $substance = new Substances($s_id);

            if(!$substance->id)
            {
                continue;
            }

            $molecules[] = [$substance->name];
            $substance->delete();
        }

        // Save report and send to the admins
        if($total)
        {
            try
            {
                $report = new File();
                $file_path = MEDIA_ROOT . 'files/schedulerReports/subs_delete_' . $report->generateName() . '.csv';
                $report->create($file_path);
                $report->writeCSV($molecules);

                // Send emails
                $this->send_email_to_admins('A total of ' . $total . ' molecules were deleted.', 'Scheduler autoremove report', $file_path);

                // Add info about log file
                $scheduler_log = new Log_scheduler();

                $scheduler_log->error_count = 0;
                $scheduler_log->success_count = $total;
                $scheduler_log->report_path = $file_path;
                $scheduler_log->type = Log_scheduler::T_SUBSTANCE_AUTOREMOVE;

                $scheduler_log->save();
            }
            catch(Exception $e)
            {
                echo $e->getMessage();
                echo 'Cannot make `substance_delete` log file.';
            }

            echo('Total ' . $total . ' substances were deleted.' . "\n");
        }
    }

    /**
     * Checks interactions datasets
     * 
     * @author Jakub Juracka      
     */
    public function check_interaction_datasets()
    {
        $dataset_model = new Datasets();
        $interaction_model = new Interactions();
        $removed = 0;
        $joioned = 0;

        // Join the same datasets
        // Same -> membrane/method/secondary reference/author + the same upload day
        $duplcs = $dataset_model->get_duplicites();
        $joioned = count($duplcs);

        foreach($duplcs as $ids)
        {
            $final_id = array_shift($ids);

            // For each dataset, change interaction dataset id
            foreach($ids as $dataset_id)
            {
                $interaction_model->change_dataset_id($dataset_id, $final_id);
            }
        }

        if(count($duplcs))
        {
            echo 'Total ' . count($duplcs) . " datasets were joined. \n";
        }

        // Remove empty datasets
        $empty_datasets = $dataset_model->get_empty_datasets();

        foreach($empty_datasets as $dataset)
        {
            $total_int = $dataset->get_count_interactions();
            
            if($total_int > 0)
            {
                continue;
            }

            $dataset->delete();
            $removed++;
        }

        if($removed > 0)
        {
            echo 'Total ' . $removed . " empty datasets were removed. \n";
        }

        if($removed > 0 || $joioned > 0)
        {
            // Save report and send to the admins
            try
            {
                $csv = array
                (
                    ['Empty interaction datasets deleted:', $removed],
                    ['Interaction datasets joined:', $joioned],
                );

                $report = new File();
                $file_path = MEDIA_ROOT . 'files/schedulerReports/inter_ds_check_' . $report->generateName() . '.csv';
                $report->create($file_path);
                $report->writeCSV($csv);

                $text = "Some interaction datasets were deleted/joined. <br/>"
                    .   " - Total deleted: " . $removed . '<br/>'
                    .   " - Total joined: " . $joioned;

                // Send emails
                $this->send_email_to_admins($text, 'Scheduler dataset checker report');

                // Add info about log file
                $scheduler_log = new Log_scheduler();

                $scheduler_log->error_count = 0;
                $scheduler_log->success_count = $joioned + $removed;
                $scheduler_log->report_path = $file_path;
                $scheduler_log->type = Log_scheduler::T_INT_CHECK_DATASET;

                $scheduler_log->save();
            }
            catch(Exception $e)
            {
                echo $e->getMessage();
                echo 'Cannot make `interaction_delete/join` log file.';
            }
        }
    }

    /**
     * Checks transporter datasets 
     * 
     * @NOT USED NOW
     * 
     * @author Jakub Juracka      
     */
    public function check_transporter_datasets()
    {
        return;
        $dataset_model = new Transporter_datasets();
        $interaction_model = new Transporters();
        $removed = 0;

        // Join the same datasets
        // Same -> membrane/method/secondary reference/author + the same upload day
        // $duplcs = $dataset_model->get_duplicites();
        
        // foreach($duplcs as $ids)
        // {
        //     $final_id = array_shift($ids)['id'];

        //     // For each dataset, change interaction dataset id
        //     foreach($ids as $dataset_id)
        //     {
        //         $interaction_model->change_dataset_id($dataset_id['id'], $final_id);
        //     }
        // }

        // if(count($duplcs))
        // {
        //     echo 'Total ' . count($duplcs) . " datasets were joined. \n";
        // }

        // // Remove empty datasets
        // $empty_datasets = $dataset_model->get_empty_datasets();

        // foreach($empty_datasets as $dataset)
        // {
        //     $total_int = $dataset->get_count_interactions();
            
        //     if($total_int > 0)
        //     {
        //         continue;
        //     }

        //     $dataset->delete();
        //     $removed++;
        // }

        // if($removed > 0)
        // {
        //     echo 'Total ' . $removed . " empty datasets were removed. \n";
        // }
    }


     /**
     * Sends unsent emails
     */
    public function send_emails()
    {
        $email_model = new Email_queue();
        $sender = new Email();

        $emails = $email_model->where(array
            (
                'status !=' => Email_queue::SENT
            ))
            ->get_all();

        foreach($emails as $e)
        {
            $record = new Email_queue($e->id);

            if(!$record->id)
            {
                continue;
            }

            try
            {
                $email_model->beginTransaction();
                // Send email
                $sender->send([$record->recipient_email], $record->subject, $record->text, $record->file_path);

                // Change status
                $record->status = Email_queue::SENT;
                $record->last_attemp_date = date('Y-m-d H:i:s');
                $record->save();

                $email_model->commitTransaction();
            }
            catch(Exception $e)
            {
                $email_model->rollbackTransaction();
                $record->status = Email_queue::ERROR;
                $record->error_text = $e->getMessage();
                $record->last_attemp_date = date('Y-m-d H:i:s');

                $record->save();
            }
        }
    }

    /**
     * Sends emails to the admins
     * 
     * @param string $message
     */
    public function send_email_to_admins($message, $subject = 'Scheduler run report', $file_path = NULL)
    {
        $email_addresses = explode(';', $this->config->get(Configs::EMAIL_ADMIN_EMAILS));

        if(!count($email_addresses))
        {
            return;
        }

        foreach($email_addresses as $e)
        {
            if(!Email::check_email_validity($e))
            {
                continue;
            }

            $email_queue = new Email_queue();

            $email_queue->recipient_email = $e;
            $email_queue->subject = $subject;
            $email_queue->text = $message;
            $email_queue->file_path = $file_path;
            $email_queue->status = Email_queue::SENDING;

            $email_queue->save();
        }
    }


    /**
     * Fills logPs for given substance
     * 
     * @param Substances $s
     */
    private function fill_logP($s)
    {
        $interaction_model = new Interactions();
        $log = new Scheduler_errors();

        // Try to find LogPs in another DBs
        if($s->pubchem)
        {
            $pubchem = new Pubchem();

            if($pubchem->is_connected())
            {
                $data = $pubchem->get_data($s->pubchem);

                if($data && $data->logP && $data->logP != '')
                {
                    $membrane = $pubchem->get_logP_membrane();
                    $method = $pubchem->get_logP_method();
                    $dataset = $pubchem->get_dataset();
                    $publication = $pubchem->get_publication();

                    if(!$membrane || !$membrane->id || !$method || !$method->id ||
                        !$dataset || !$dataset->id || !$publication || !$publication->id)
                    {
                        $s->save();
                        throw new Exception('Cannot fill Pubchem LogP. Pubchem default membrane/method/dataset/publication was not found!');
                    }

                    // Exists yet in DB?
                    $exists = $interaction_model->where(
                        array
                        (
                            'id_substance'  => $s->id,
                            'id_membrane'   => $membrane->id,
                            'id_method'     => $method->id,
                            'id_reference'  => $publication->id,
                            'id_dataset'    => $dataset->id
                        ))
                        ->get_one();

                    if($exists->id)
                    {
                        $exists->LogK = Upload_validator::get_attr_val(Upload_validator::LOG_P, $data->logP);
                        $exists->save();
                    }
                    else
                    {
                        $interaction = new Interactions();

                        $interaction->id_substance = $s->id;
                        $interaction->id_membrane = $membrane->id;
                        $interaction->id_method = $method->id;
                        $interaction->id_dataset = $dataset->id;
                        $interaction->id_reference = $publication->id;
                        $interaction->LogK = Upload_validator::get_attr_val(Upload_validator::LOG_P, $data->logP);
                        $interaction->temperature = NULL;
                        $interaction->charge = NULL;
                        $interaction->user_id = NULL;
                        $interaction->visibility = Interactions::VISIBLE;

                        $interaction->save();

                        $log->add($s->id, 'Filled Pubchem_LogP value.');
                    }
                }
            }
        }

        $s->save();
    }
}
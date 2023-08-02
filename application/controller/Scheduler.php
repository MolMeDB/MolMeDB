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
        $this->pid = getmypid();
        parent::__construct();
        // Holds info about run time
        $this->time = new Time();
    }

    /**Holds PID */
    private $pid;
    
    /**
     * @var Scheduler_runs
     */
    private $run;

    /**
     * @var Scheduler_runs[]
     */
    private $runs = [];

    /**
     * @var Time
     */
    private $time;

    /**
     * @var int
     */
    private $timeout_limit = null;

    /**
     * Specifies publicly accessible functions
     */
    static $accessible = array
    (
        'run', 
//        'run_cosmo'
    );

    /**
     * Prints message to the console
     * 
     * @param string $msg
     */
    private function print($msg, $print_time = TRUE)
    {
        $time = strtotime('now') - $this->time->current();
        $time = date('i:s', $time);

        if($print_time)
        {
            echo " -> $time - $msg \n";
        }
        else
        {
            echo " -> $msg \n";
        }
    }

    /**
     * Main scheduler funtion
     * 
     */
    public function run()
    {
        echo '--- RUN ' . $this->time->get_string() . " ---\n";
        try
        {
            if(!$this->config->get(Configs::S_ACTIVE))
            {
                $this->print('Scheduler is not active.');
                return;
            }

            // Send all unsent emails
            if($this->config->get(Configs::EMAIL_ENABLED))
            {
                $this->protected_call('send_emails', []);
            }

            // Delete substances without interactions
            // if(($this->check_time($this->config->get(Configs::S_DELETE_EMPTY_SUBSTANCES_TIME)) && 
            //     $this->config->get(Configs::S_DELETE_EMPTY_SUBSTANCES)))
            // {
            //     $this->protected_call('delete_empty_substances', []);
            // }

            // Checks interactions datasets
            if(($this->check_time($this->config->get(Configs::S_VALIDATE_PASSIVE_INT_TIME)) && 
                $this->config->get(Configs::S_VALIDATE_PASSIVE_INT)))
            {
                $this->protected_call('check_interaction_datasets', []);
            }

            // Checks transporter datasets - NOW INACTIVATED
            if(($this->check_time($this->config->get(Configs::S_VALIDATE_ACTIVE_INT_TIME)) && 
                $this->config->get(Configs::S_VALIDATE_ACTIVE_INT)))
            {
                // TODO
                // $this->check_transporter_datasets();
                // echo "Transporter datasets checked.\n";
            }

            // Once per day, update stats data
            if(($this->check_time($this->config->get(Configs::S_UPDATE_STATS_TIME)) && 
                $this->config->get(Configs::S_UPDATE_STATS)))
            {
                $this->protected_call('update_stats', []);
            }

            // Once per day, update stats data
            if(($this->check_time($this->config->get(Configs::S_CHECK_PUBLICATIONS_TIME)) && 
                $this->config->get(Configs::S_CHECK_PUBLICATIONS)))
            {
                $this->protected_call('update_publications', []);
                // $this->protected_call('delete_empty_publications', []);
            }

            // Delete empty membranes and methods
            // if(($this->check_time($this->config->get(Configs::S_CHECK_MEMBRANES_METHODS_TIME)) && 
            //     $this->config->get(Configs::S_CHECK_MEMBRANES_METHODS)))
            // {
            //     $this->protected_call('delete_empty_membranes_methods', []);
            // }

            // ---- Molecules data autofill ---- //
            if($this->config->get(Configs::S_VALIDATE_SUBSTANCES))
            {
                $this->protected_call('validate_substance_identifiers', []);
            }

            // Fragmentation
            if($this->config->get(Configs::S_FRAGMENT_MOLECULES))
            {
                $this->protected_call('fragment_molecules', []);
            }

            // Link molecules by functional groups
            if($this->config->get(Configs::S_MATCH_FUNCT_PAIRS))
            {
                $this->protected_call('match_functional_pairs', []);
            }

            // Pair group update
            if($this->config->get(Configs::S_UPDATE_PAIR_GROUPS))
            {
                if(($this->check_time($this->config->get(Configs::S_UPDATE_PAIR_GROUPS_TIME))))
                {
                    $this->protected_call('update_pair_groups', []);
                }

                if(($this->check_time($this->config->get(Configs::S_UPDATE_PAIR_GROUPS_STATS_TIME))))
                {
                    $this->protected_call('update_pair_group_interactions', [], 1800);
                }
            }

            // COSMO computations
            if($this->config->get(Configs::COSMO_ENABLED) && $this->check_time("xx:x0|xx:x2|xx:x4|xx:x6|xx:x8"))
            {
                $this->protected_call('run_cosmo', []);
            }

            // Upload new datasets
            if($this->config->get(Configs::S_AUTOUPLOAD_INTERACTIONS) &&
                $this->check_time($this->config->get(Configs::S_AUTOUPLOAD_INTERACTIONS_TIME)))
            {
                $this->protected_call('autoupload_interactions', [], 1800);
            }

            $this->print("Scheduler END.");
        }
        catch(Exception $e)
        {
            $text = "General error occured while scheduler was running.\n\n" . $e->getMessage();
            $this->print($text);

            $this->run->report_crash($this->run->pid, $e);

            try
            {
                $report = new File();
                $name = $report->generateName();
                $filePath = MEDIA_ROOT . 'files/schedulerReports/general_' . $name . '.log';
                $report->create($filePath);
                $report->writeLine($e->getMessage());

                $file = new Files();
                $file->name = $name;
                $file->path = $filePath;
                $file->type = Files::T_SCHEDULER_REPORT;
                $file->save();
            }
            catch(Exception $e)
            {
                $this->print('Cannot make report file - ' . $e->getMessage());
                $this->send_email_to_admins($text, 'Scheduler error');
                return;
            }
            
            $this->send_email_to_admins($text, 'Scheduler error', $filePath);
        }

        $this->print('-------------------------');
    }

    /**
     * Updates table with pair groups
     * 
     * @author Jakub Juracka
     */
    public function update_pair_groups()
    {
        $model = new Substance_pair_groups();
        $model->update_all();
    }

    /**
     * Updates tables with pair group interactions
     * 
     * @author Jakub Juracka
     */
    public function update_pair_group_interactions()
    {
        try
        {
            $groups = Substance_pair_groups::instance()->order_by('datetime ASC, id ', 'ASC')->get_all();

            foreach($groups as $g)
            {
                if($this->can_continue())
                {
                    Db::beginTransaction();
                    $g->update_group_stats();
                    Db::commitTransaction();
                }
                else
                {
                    return;
                }
            }
        }
        catch(Exception $e)
        {
            Db::rollbackTransaction();
            throw $e;
        }
    }

    /**
     * Autouploads interactions which are pending
     * 
     * @author Jakub Juracka
     */
    public function autoupload_interactions()
    {
        $pending = Upload_queue::instance()
            ->where('state', Upload_queue::STATE_PENDING)
            ->get_all();

        foreach($pending as $record)
        {
            if(!$this->can_continue())
            {
                $this->print('Autoupload_interactions max runtime exceeded. Will continue in next run.');
                return;
            }

            try
            {
                $this->print('Trying to run upload record id:' . $record->id);
                $record->start();
            }
            catch(Exception $e)
            {
                $this->print($e->getMessage());
                throw $e;
            }
        }
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

        // Is already linked?
        $sj = Scheduler_runs::instance()->where(array
        (
            'method'    => 'link_functional_groups',
        ))->order_by('id', 'DESC')->get_one();

        if($sj->status == Scheduler_runs::STATE_RUNNING)
        {
            $text = 'Waiting for finish of `link_funcional_groups` process.';
            $this->run->note = $text;
            $this->print($text);
            return;
        }

        if(!$sj->id || !$total)
        {
            if(!$this->protected_call('link_functional_groups', []))
            {
                $this->run->note = 'Link functional group failed. Leaving...';
                $this->print('Link functional group failed. Leaving...');
                return;
            }
        }

        // check $limit molecules per run
        $limit = 100;

        $all = Substance_pairs::instance()->get_unmatched_molecules($limit);

        foreach($all as $r)
        {
            try
            {
                Db::beginTransaction();
                $r->save_functional_relatives();
                Db::commitTransaction();
            }
            catch(Exception $e)
            {
                Db::rollbackTransaction();
                echo $e->getMessage();
                $ex = new MmdbException($e->getMessage(), '', 0, $e);
                $this->run->id_exception = $ex->getExceptionId();
                // Inform admin and exit
                $this->send_email_to_admins(
                    'Exception thrown during `match_functional_pairs` method run.</br>' . $e->getMessage()
                );
                throw $ex;
            }
        }
    }

    /**
     * Runs COSMO computations
     * 
     * @author Jakub Juracka
     */
    public function run_cosmo()
    {
        if(!$this->config->get(Configs::COSMO_ENABLED))
        {
            return;
        }

        // How many jobs hold ready (prepared) for metacentrum send?
        $max_metacentrum_prepared = $this->config->get(Configs::COSMO_METACENTRUM_MAX_SDF);
        $max_ionization_states = $this->config->get(Configs::COSMO_METACENTRUM_MAX_ION);
        $max_metacentrum_queue_items =$this->config->get(Configs::COSMO_METACENTRUM_MAX_RUNNING);

        $cosmo = new Run_cosmo();
        $rdkit = new Rdkit();
        
        // At first, delete what should be deleted
        $to_delete = $cosmo->where(array
            (
                'status' => $cosmo::STATUS_REMOVE,
                'state !=' => $cosmo::STATE_RESULT_DB_STORED,
                'last_update <' => date('Y-m-d H:i:s', strtotime('-12 hours')) 
            ))
            ->get_all();

        foreach($to_delete as $record)
        {
            try
            {
                Db::beginTransaction();
                // Remove all stored files
                $f = new File();
                $f->remove_conformer_folder($record->id_fragment);

                // Remove all ionized states
                $fo = Fragment_ionized::instance()->where('id_fragment', $record->id_fragment)->get_all();
                
                foreach($fo as $f)
                {
                    $f->delete();
                }

                // Remove ionization info
                $r = Run_ionization::instance()->where('id_fragment', $record->id_fragment)->get_one();

                if($r->id)
                {
                    $r->delete();
                }

                // Finaly remove cosmo job record
                $record->delete();
                
                Db::commitTransaction();
            }
            catch(MmdbException $e)
            {
                Db::rollbackTransaction();
            }
        }

        // Force run events
        $force_all = $cosmo->where('forceRun', '1')->get_all();

        foreach($force_all as $record)
        {
            try
            {
                Db::beginTransaction();
                // Check, if conformer folder can be replaced
                $all = $cosmo->where('id_fragment', $record->id_fragment)->get_all();
                $all_force = $cosmo->where(array
                (
                    'id_fragment' => $record->id_fragment,
                    'forceRun IS NOT'    => 'NULL'
                ))->get_all();

                if(count($all) == count($all_force))
                {
                    // Clear data and start again
                    $f = new File();
                    $f->remove_conformer_folder($record->id_fragment);
                    // Remove all ionized states
                    $fo = Fragment_ionized::instance()->where('id_fragment', $record->id_fragment)->get_all();
                    
                    foreach($fo as $f)
                    {
                        $f->delete();
                    }

                    // Remove ionization info
                    $r = Run_ionization::instance()->where('id_fragment', $record->id_fragment)->get_one();

                    if($r->id)
                    {
                        $r->delete();
                    }
                }

                $record->log = NULL;
                $record->state = Run_cosmo::STATE_PENDING;
                $record->forceRun = 2;
                $record->save();

                Db::commitTransaction();
            }
            catch(MmdbException $e)
            {
                Db::rollbackTransaction();
            }
        }


        // Check, how many jobs is waiting to send to metacentrum
        $total_ready_to_send = $cosmo->where(array
            (
                'state' => $cosmo::STATE_SDF_READY,
                'status' => $cosmo::STATUS_OK 
            ))
            ->count_all();

        $t = $max_metacentrum_prepared - $total_ready_to_send;

        // Prepare missing n structures to send
        $prepare_sdf = $cosmo->where(array
            (
                'state <' => $cosmo::STATE_SDF_READY,
                'status'  => $cosmo::STATUS_OK
            ))
            ->order_by('priority DESC, status DESC, id', 'ASC')
            ->limit($t > 0 ? $t : 1)
            ->get_all();

        foreach($prepare_sdf as $record)
        {
            $exists = Fragment_ionized::instance()->where('id_fragment', $record->id_fragment)->get_one();

            if($exists->id)
            {
                $record->state = $cosmo::STATE_IONIZED;
                $record->save();
            }

            // Get ionization states
            if($record->state == $cosmo::STATE_PENDING)
            {   
                $ion_states = $rdkit->get_ionization_states($record->fragment->smiles, $max_ionization_states);
                
                if($ion_states === false)
                {
                    $text = 'Cannot get fragment [id:' . $record->id_fragment . '] ionization states.';
                    $record->log = $text;
                    $record->status = $cosmo::STATUS_ERROR;
                    $record->save();
                    continue;
                }

                try
                {
                    Db::beginTransaction();
                    $run_i = new Run_ionization();

                    $run_i->id_fragment = $record->fragment->id;
                    $run_i->ph_end = $ion_states->pH_end;
                    $run_i->ph_start = $ion_states->pH_start;

                    $run_i->save();

                    if(!in_array($record->fragment->smiles, $ion_states->molecules))
                    {
                        $ion_states->molecules[] = $record->fragment->smiles;
                    }

                    foreach($ion_states->molecules as $smiles)
                    {
                        $fi = new Fragment_ionized();

                        $fi->id_fragment = $record->fragment->id;
                        $fi->smiles = $smiles;
                        $fi->save();
                    } 

                    // + add molecule as is
                    $exists = Fragment_ionized::instance()->where('smiles LIKE', $run_i->fragment->smiles)->get_one();

                    if(!$exists->id)
                    {
                        $fi = new Fragment_ionized();

                        $fi->id_fragment = $run_i->id_fragment;
                        $fi->smiles = $smiles;
                        $fi->save();
                    }

                    $record->state = $cosmo::STATE_IONIZED;
                    $record->save();

                    Db::commitTransaction();
                }
                catch(MmdbException $e)
                {
                    Db::rollbackTransaction();
                    throw $e;
                }
            }
            
            if($record->state == $cosmo::STATE_IONIZED)
            {
                // Generate conformers and save SDF files
                $ion_states = Fragment_ionized::instance()->where('id_fragment', $record->id_fragment)->get_all();

                foreach($ion_states as $ion)
                {
                    $name = $record->id_fragment . '_' . $ion->id;

                    $fileHelper = new File();
                    $folder = $fileHelper->prepare_conformer_folder($record->id_fragment,$ion->id);
                    $folder_files = scandir($folder);

                    foreach($folder_files as $a)
                    {
                        // Exists some conformers with this name?
                        if(strpos($a, $name) !== false)
                        {
                            // Conformer exists
                            $record->state = $cosmo::STATE_SDF_READY;
                            $record->save();
                            continue 2;
                        }
                    }

                    // Get conformer SDF contents for an $ion
                    $confs = $rdkit->get_cosmo_conformers($ion->smiles, $name);

                    if($confs === false)
                    {
                        $text = 'Cannot generate conformers.';
                        $record->log = $text;
                        $record->status = $cosmo::STATUS_ERROR;
                        $record->save();
                        continue;
                    }

                    foreach($confs as $key => $cf)
                    {
                        $f = fopen($folder . $name ."_".$key.".sdf", 'w');
                        if(!fwrite($f, $cf->sdf))
                        {
                            throw new MmdbException("Cannot store content of SDF file. [ION: " . $ion->id . "]");
                        }
                        fclose($f);
                    }
                }

                $record->state = $cosmo::STATE_SDF_READY;
                $record->save();
            }
        }

        try
        {
            $f = new File();

            $u = Users_metacentrum::instance()->where('enabled', '1')->get_one();
            $metacentrum = new Metacentrum();
            $username = $u->login;
            $password = Users_metacentrum::unhash($u->password);
            $queue = Metacentrum::QUEUE_ELIXIR;
            $host = $this->config->get(Configs::COSMO_URL);

            // Get jobs on metacentrum
            $jobs = $metacentrum->get_job_list($username, $password, $queue, true);

            if($jobs === NULL)
            {
                throw new MmdbException('Cannot get list of running jobs.');
            }

	        if(!isset($jobs['total']) || !isset($jobs['jobs']))
            {
                throw new MmdbException('Invalid structure of jobs.');
            }

            $running_jobs_total = $jobs['total'];
            $jobs = $jobs['jobs'];

            // Update info
            Config::set(Configs::COSMO_TOTAL_RUNNING, $running_jobs_total);
            Config::set(Configs::COSMO_LAST_UPDATE, date("Y-m-d H:i"));

            // Exclude done jobs
            $killed = [];
            $t = $jobs;
            foreach($t as $key => $job)
            {
                if($job->is_killed())
                {
                    $killed[] = $job;
                }
                if($job->is_finished() && !$job->is_killed())
                {
                    unset($jobs[$key]);
                }
            }

            // Run all killed again if can
            $to_run_again = array
            (
                40 => [],
                80 => [],
                120 => []
            );
            $to_run_again_cosmo = array();
            foreach($killed as $job)
            {
                $ion = $job->get_db_ion();
                $db_run = $job->get_db_cosmo_run();
                
                if($ion->id == NULL || !$db_run->id) // Not in DB
                {
                    continue;
                }
                
                if($job->job_type == Metacentrum_job::TYPE_COSMO)
                {
                    $db_run->status = Run_cosmo::STATUS_ERROR;
                    $db_run->save();
                    continue;
                }

                $to_run_again_cosmo[] = $db_run;

                if($job->max_runtime == '20:00:00')
                {
                    $to_run_again[40][] = $ion->id;
                }
                elseif($job->max_runtime == '40:00:00')
                {
                    $to_run_again[80][] = $ion->id;
                }
                elseif($job->max_runtime == '120:00:00')
                {
                    $ion->cosmo_flag = Fragment_ionized::COSMO_F_OPTIMIZE_ERR_PARTIAL;
                    $ion->save();
                }
                else
                {
                    $to_run_again[120][] = $ion->id;
                }
            }

            if(!count($to_run_again[40]))
            {
                unset($to_run_again[40]);
            }
            if(!count($to_run_again[80]))
            {
                unset($to_run_again[80]);
            }
            if(!count($to_run_again[120]))
            {
                unset($to_run_again[120]);
            }

            foreach($to_run_again as $hours => $ion_ids)
            {
                $metacentrum->run_failed_cosmo($to_run_again_cosmo, $ion_ids, $host, $username, $password, $queue, $hours);
            }

            // stop if queue is full
            if($running_jobs_total >= $max_metacentrum_queue_items)
            {
                throw new MmdbException('Maximum jobs in queue.');
            }

            $remaining_jobs = $max_metacentrum_queue_items - $running_jobs_total;

            // Get all possible combinations of membrane/method/temperature
            $settings = Run_cosmo::instance()
                ->distinct()
                ->select_list('method, temperature,  id_membrane')
                ->where(array
                (
                    'status' => Run_cosmo::STATUS_OK,
                    'state <'  => Run_cosmo::STATE_RESULT_DOWNLOADED,
                    'state >=' => Run_cosmo::STATE_SDF_READY 
                ))
                ->in('state', array(Run_cosmo::STATE_OPTIMIZATION_RUNNING, Run_cosmo::STATE_SDF_READY, Run_cosmo::STATE_COSMO_RUNNING))
                ->get_all()
                ->as_array();

            shuffle($settings);

            foreach($settings as $setting)
            {
                if($remaining_jobs <= 0)
                {
                    break;
                }

                // Prefere COSMO RUNs
                $candidates = Db::instance()->queryAll(
                    "SELECT t.*, COUNT(t.id) as total_ions
                    FROM (
                        SELECT rn.*
                        FROM run_cosmo rn
                        JOIN fragments_ionized fi ON fi.id_fragment = rn.id_fragment AND (fi.cosmo_flag != ? OR fi.cosmo_flag IS NULL)
                        WHERE rn.state = ? AND rn.status = ? AND method LIKE ? AND temperature LIKE ? AND id_membrane = ? AND next_remote_check <= ?
                        LIMIT ?) as t
                    GROUP BY t.id
                    ORDER BY priority DESC, method DESC, last_update ASC"
                , array(
                    Fragment_ionized::COSMO_F_OPTIMIZE_ERR_COMLETE,
                    Run_cosmo::STATE_OPTIMIZATION_RUNNING,
                    Run_cosmo::STATUS_OK,
                    $setting['method'],
                    $setting['temperature'],
                    $setting['id_membrane'],
                    date("Y-m-d H:i:s"),
                    $remaining_jobs
                ), FALSE);

                foreach($candidates as $c)
                {
                    $remaining_jobs -= $c['total_ions'];
                }

                // If remaining space, fill new optimization jobs
                $c2 = Db::instance()->queryAll(
                    "SELECT t.*, COUNT(t.id) as total_ions
                    FROM (
                        SELECT rn.*
                        FROM run_cosmo rn
                        JOIN fragments_ionized fi ON fi.id_fragment = rn.id_fragment AND (fi.cosmo_flag != ? OR fi.cosmo_flag IS NULL)
                        WHERE rn.state = ? AND rn.status = ? AND method LIKE ? AND temperature LIKE ? AND id_membrane = ? AND next_remote_check <= ?
                        LIMIT ?) as t
                    GROUP BY t.id
                    ORDER BY priority DESC, method DESC, last_update ASC"
                , array(
                    Fragment_ionized::COSMO_F_OPTIMIZE_ERR_COMLETE,
                    Run_cosmo::STATE_SDF_READY,
                    Run_cosmo::STATUS_OK,
                    $setting['method'],
                    $setting['temperature'],
                    $setting['id_membrane'],
                    date("Y-m-d H:i:s"),
                    $remaining_jobs
                ),FALSE);

                $t = $c2;

                foreach($c2 as $k => $c)
                {
                    if($remaining_jobs < 0)
                    {
                        unset($t[$k]);
                    }
                    $ion_states = Fragment_ionized::instance()->where('id_fragment', $c['id_fragment'])->get_all();
                    $fileHelper = new File();
                    foreach($ion_states as $ion)
                    {
                        $folder = $fileHelper->prepare_conformer_folder($c['id_fragment'],$ion->id);
                        $folder_files = scandir($folder);
                        $folder_files = array_filter($folder_files, function($a){return preg_match('/\.sdf$/', $a);});
                        $remaining_jobs -= count($folder_files);
                    }
                }

                $candidates = array_merge($candidates, $t);

                $candidates = array_merge($candidates, Db::instance()->queryAll(
                    "SELECT t.*, COUNT(t.id) as total_ions
                    FROM (
                        SELECT rn.*
                        FROM run_cosmo rn
                        JOIN fragments_ionized fi ON fi.id_fragment = rn.id_fragment AND (fi.cosmo_flag != ? OR fi.cosmo_flag IS NULL)
                        WHERE rn.state = ? AND rn.status = ? AND method LIKE ? AND temperature LIKE ? AND id_membrane = ? AND next_remote_check <= ?
                        LIMIT ?) as t
                    GROUP BY t.id
                    ORDER BY priority DESC, method DESC, last_update ASC"
                , array(
                    Fragment_ionized::COSMO_F_OPTIMIZE_ERR_COMLETE,
                    Run_cosmo::STATE_COSMO_RUNNING,
                    Run_cosmo::STATUS_OK,
                    $setting['method'],
                    $setting['temperature'],
                    $setting['id_membrane'],
                    date("Y-m-d H:i:s"),
                    max($remaining_jobs, 50)
                ), FALSE));

                // Add all without ions to recompute
                $candidates = array_merge($candidates, Db::instance()->queryAll(
                    "   SELECT rn.*
                        FROM run_cosmo rn
                        LEFT JOIN fragments_ionized fi ON fi.id_fragment = rn.id_fragment
                        WHERE fi.id IS NULL
                    "
                ,array(), FALSE));

                $t = $candidates;
                $candidates = [];

                foreach ($t as $c)
                {
                    $cddt = new Run_cosmo($c['id']);
                    $cddt->last_update = date('Y-m-d H:i:s');
                    $cddt->save();
                    $candidates[] = $cddt;
                }

                $metacentrum->run_cosmo($candidates, $host, $username, $password, $queue);
            }
        }
        catch(MmdbException $e)
        {
	    print_r($e);
            $this->print($e->getMessage());
        }

        // Process final data
        $toFinal = $cosmo->where(array
            (
                'state' => $cosmo::STATE_RESULT_DOWNLOADED,
                'status'  => $cosmo::STATUS_OK
            ))
            ->order_by('priority DESC, status DESC, id', 'ASC')
            ->limit(50)
            ->get_all();

        foreach($toFinal as $job)
        {
            $job->process_results();
            $job->state = Run_cosmo::STATE_RESULT_PARSED;
            $job->save();
        }

        // Save final data to the DB // TODO
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

    /** 
     * Checks, if given method is not calling too often
     * 
     * @param string $name
     * @param array $arguments
     * @param int $timeout_end - Number of seconds, after which should be function safely ended
     * 
     */
    private function protected_call($name, $arguments, $timeout_end = NULL)
    {
        // Check, if exists
        if(!method_exists($this, $name))
        {
            $this->print('Invalid method called in scheduler.');
            die;
        }

        $run = new Scheduler_runs();

        // Check, if exists pid running this method
        $exists = $run->get_by_method_name($name, $run::STATE_RUNNING);

        if($exists->id)
        {
            // Check, if process is still running
            if($exists->is_pid_running())
            {
                $this->print('Method ' . $name . ' looks like still running from previous session.' . $exists->__toString());
                return false;
            }
            else
            {
                $text = $exists->__toString() . ' including calling method Scheduler->' . $name . ' probably unexpectedly failed. Current time: ' . date('Y-m-d H:i:s');  
                $this->send_email_to_admins($text, 'Scheduler error');
                $this->print('Error occured during processing ' . $exists->__toString());
                $exists->report_crash($exists->pid);
            }
        }

        $start = strtotime('now');

        if($timeout_end)
        {
            $this->timeout_limit = $start + $timeout_end;
        }

        $run->pid = $this->pid;
        $run->method = $name;
        $run->params = json_encode($arguments);
        $run->status = $run::STATE_RUNNING;
        $run->datetime = date('Y-m-d H:i:s', $start);
        $run->save();

        // $this->runs[$name] = $run;

        $this->print("Method `$name` is running.");

        $prev_run = $this->run;

        try
        {
            // Call method
            $this->run = $run;
            $this->$name(...$arguments);
            $this->run = $prev_run;
        }
        catch(MmdbException $e)
        {
            $this->run = $prev_run;
            $run->status = $run::STATE_ERROR;
            $run->id_exception = $e->getExceptionId();
            $run->save();
            $this->print("Method `$name` failed.");

            $this->send_email_to_admins(
                "Method `$name` run failed. Exception id: " . $e->getExceptionId()
            );
            return false;
        }
        catch(Exception $e)
        {
            $this->run = $prev_run;
            $ex = new MmdbException('General error occured.', '', 0, $e);
            $run->status = $run::STATE_ERROR;
            $run->id_exception = $ex->getExceptionId();
            $run->save();
            $this->print("Method `$name` failed.");
            
            $this->send_email_to_admins(
                "Method `$name` run failed. Exception id: " . $ex->getExceptionId()
            );
            return false;
        }

        // After end
        $run->status = $run::STATE_DONE;
        $run->save();
        $this->print("Method `$name` executed.");
        return true;
    }

    /**
     * Checks, if function can continue
     * 
     * @return bool
     */
    private function can_continue()
    {
        if($this->timeout_limit)
        {
            return strtotime('now') < $this->timeout_limit;
        }

        return true;
    }

    // /**
    //  * Should be run only once. Matches all substance changes
    //  * 
    //  */
    // public function log_sub_changes()
    // {
    //     // Deletes
    //     $deletes = [
    //         'found different',
    //         'filled pubchem_logp value',
    //         'waiting for validation',
    //         'found SMILES',
    //         'found possible duplicity',
    //         'labeled as non duplicity',
    //         'SQLSTATE',
    //         'was validated',
    //         'cannot find',
    //         'is unreachable',
    //         'found 3d structure on'
    //     ];

    //     foreach($deletes as $wh)
    //     {
    //         Scheduler_errors::instance()->query("DELETE FROM `scheduler_errors` WHERE error_text LIKE '%$wh%'");
    //     }

    //     $changes = Scheduler_errors::instance()->where('error_text LIKE', "%on remote server%")->order_by('id_substance ASC, last_update', 'DESC')->get_all();

    //     $links = [
    //         'chebi' => 'chEBI',
    //         'drugbank_id' => 'drugbank',
    //         'chembl_id' => 'chEMBL',
    //         'pdb_id' => 'pdb',
    //         'pubchem_id' => 'pubchem',
    //     ];

    //     foreach($changes as $c)
    //     {
    //         $s = new Substances($c->id_substance);

    //         preg_match('/Found (.*) = (.*) on remote server./', $c->error_text, $all);

    //         if(count($all) < 3)
    //         {
    //             continue;
    //         }

    //         $src = $all[1];
    //         $val = $all[2];

    //         $attr = $links[$src];

    //         if($s->$attr == $val)
    //         {
    //             $s->$attr = NULL;
    //             $s->save();
    //             $c->delete();
    //         }
    //         else
    //         {
    //             $c->delete();
    //         }
    //     }

    //     // Name changes
    //     $rows = Scheduler_errors::instance()->where('error_text LIKE', "%found on%")->order_by('id_substance ASC, last_update', 'DESC')->get_all();

    //     foreach($rows as $r)
    //     {
    //         $s = new Substances($r->id_substance);

    //         $s->name = $s->identifier;
    //         $s->save();

    //         $r->delete();
    //     }

    //     // CHEMBL ID in name
    //     $rows = Scheduler_errors::instance()->where('error_text LIKE', "%found chembl_id in molecule name%")->order_by('id_substance ASC, last_update', 'DESC')->get_all();

    //     foreach($rows as $r)
    //     {
    //         $s = new Substances($r->id_substance);

    //         preg_match("/in molecule name = (.+)\./", $r->error_text, $all);

    //         if(count($all) < 2)
    //         {
    //             continue;
    //         }

    //         $d = $all[1];

    //         if(!$d)
    //         {
    //             continue;
    //         }

    //         $s->name = $d;
    //         $s->chEMBL = NULL;
    //         $s->save();

    //         $r->delete();
    //     }

    //     // Changes
    //     $rows = Scheduler_errors::instance()->where('error_text LIKE', "%changes - [%")->order_by('id_substance ASC, last_update', 'DESC')->get_all();

    //     foreach($rows as $r)
    //     {
    //         $s = new Substances($r->id_substance);

    //         preg_match('/Changes - \[([a-z]+): (.*) => (.*)\]/i', $r->error_text, $all);

    //         if(count($all) < 3)
    //         {
    //             continue;
    //         }

    //         $attr = $all[1];
    //         $from = $all[2] !== "NULL" ? $all[2] : NULL;
    //         $to   = $all[3] !== "NULL" ? $all[3] : NULL;

    //         if(strtolower($s->$attr) == strtolower($to))
    //         {
    //             $s->$attr = $from;
    //             $s->save();
    //         }

    //         $r->delete();
    //     }

    //     ##### NOW, SAVE ALL IDENTIFIER OF ALL MOLECULES AS UPLOADED #######
    //     $substnaces = Substances::instance()->queryAll('
    //         SELECT s.*
    //         FROM substances s
    //         WHERE s.id NOT IN (
    //             SELECT DISTINCT id_substance 
    //             FROM validator_identifiers
    //             )
    //     ');
    //     $vi = new Validator_identifiers();

    //     foreach($substnaces as $s)
    //     {
    //         $s = new Substances($s->id);

    //         // Remove inchikey
    //         $s->inchikey = NULL;
    //         $s->save();

    //         if(!$vi->init_substance_identifiers($s))
    //         {
    //             $this->print("ERROR OCCURED: " . $s->id . ' / ' . $s->name . '.');
    //             die;
    //         }
    //     }

    //     // Delete old 3D structures
    //     $t = scandir(File::FOLDER_3DSTRUCTURES);
        
    //     foreach($t as $f)
    //     {
    //         if(is_dir(File::FOLDER_3DSTRUCTURES . $f) || !preg_match('/\.mol$/', $f))
    //         {
    //             continue;
    //         }

    //         unlink(File::FOLDER_3DSTRUCTURES . $f);
    //     }

    //     die;
    // }


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
                }

		if(strtolower($h) == 'xx' || (strtolower(substr($h, 0, 1)) == 'x' && is_numeric(substr($h, 1, 1))))
		{
		    if(strtolower($h) != 'xx' && (($this->time->get_hour() % 10) !== intval(substr($h, 1, 1))))
		    {
		        continue;
		    }

		    if(strtolower($m) == 'xx' || (strtolower(substr($m, 0, 1)) == 'x' && is_numeric(substr($m, 1, 1)) &&
		      ($this->time->get_minute() % 10) == intval(substr($m, 1, 1))))
		    {
			return true;
		    }
		}

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

        $this->print("Fragmentation started.");

        $substances = $sf_model->get_non_fragmented_substances(10000);

        $this->print('Try to fragment total: ' . count($substances) . ' molecules.');

        $rdkit = new Rdkit();

        foreach($substances as $s)
        {
            // Check, if molecule has fragmentation error in history
            $err = Error_fragments::instance()->where(array
                (
                    'id_substance'  => $s->id,
                    'type'          => Error_fragments::TYPE_FRAGMENTATION_ERROR 
                ))
                ->get_one();

            // Not fragment mixtures etc.
            if(preg_match('/\./', $s->SMILES))
            {
                continue;
            }

            try
            {
                Db::beginTransaction();
                $s->check_identifiers();

                // Timeout in secs
                $timeout = 20;

                // If error occured, increase timeout
                if($err->id)
                {
                    $timeout = 90;
                }

                $fragments = $rdkit->fragment_molecule($s->SMILES, true, $timeout);

                if(!$fragments)
                {
                    // Cannot fragment molecule -> save error record
                    $err->id_substance = $s->id;
                    $err->id_fragment = NULL;
                    $err->type = Error_fragments::TYPE_FRAGMENTATION_ERROR;
                    $err->text = 'Cannot fragment molecule.';
                    $err->datetime = date('Y-m-d H:i:s');

                    $err->save();

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

                // If ok, remove old errors
                $errors = Error_fragments::instance()->where('id_substance', $s->id)->get_all();

                foreach($errors as $e)
                {
                    $e->delete();
                }

                Db::commitTransaction();
            }
            catch(MmdbException $e)
            {
                Db::rollbackTransaction();

                $err->id_substance = $s->id;
                $err->id_fragment = NULL;
                $err->type = Error_fragments::TYPE_FRAGMENTATION_ERROR;
                $err->text = $e->getMessage();
                $err->datetime = date('Y-m-d H:i:s');

                $err->save();

                $this->run->id_exception = $e->getExceptionId();
            }
            catch(Exception $e)
            {
                Db::rollbackTransaction();
                throw $e;
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
        
        $limit = 1000;

        try
        {
            $substances = $logs->get_substances_for_validation($limit, 0, TRUE, $include_ignored);

            if(!count($substances))
            {
                $this->print('No substance found for validation.');
                return;
            }
        }
        catch(Exception $e)
        {
            $this->print($e->getMessage());
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
                        $this->print($e->getMessage());
                        $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage());
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
                                try
                                {
                                    $canonized = $rdkit->canonize_smiles($smiles_o->value);
                                }
                                catch(MmdbException $e)
                                {
                                    $canonized = false;
                                }

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
                                        try
                                        {
                                            $r = $rdkit->canonize_smiles($smiles);
                                        }
                                        catch(MmdbException $e)
                                        {
                                            $r = false;
                                        }

                                        if($r !== false)
                                        {
                                            $smiles = $r;
                                            $canonized = true;
                                        }
                                        else
                                        {
                                            $smiles = null;
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
                            else
                            {
                                $log->update_state($s->id, $log->type, $log::STATE_ERROR, 'SMILES not found.');
                            }
                        }
                    }
                    catch(Exception $e)
                    {
                        $this->print($e->getMessage());
                        $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage());
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
                        $this->print($e->getMessage());
                        $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage());
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
                        $this->print($e->getMessage());
                        $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage());
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
                        $this->print($e->getMessage());
                        $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage());
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
                            $this->print($e->getMessage());
                        }
                    }

                    if(count($exists) == count($accessible) || count($exists) > 1)
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

                #########################################
                ## Try to find new name by identifier ###
                #########################################
                $log = $logs_arr[$log::TYPE_NAME_BY_IDENTIFIERS];

                if(!$log->is_done())
                {
                    try
                    {
                        $name_o = $val_identifiers->get_active_substance_value($s->id, $val_identifiers::ID_NAME);
                        $unreachable = false;
                        $found = [];
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
                                    $unreachable = true;
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

                                if(!$source->id)
                                {
                                    continue;
                                }

                                $new = Validator_identifiers::instance()->where(array
                                    (
                                        'id_substance'  => $s->id,
                                        'id_source'     => $source->id,
                                        'server'        => $server_id,
                                        'identifier'    => Validator_identifiers::ID_NAME,
                                        'value'         => $found_value
                                    ))
                                    ->get_one();

                                $new->id_substance = $s->id;
                                $new->id_source = $source->id;
                                $new->server = $server_id;
                                $new->identifier = Validator_identifiers::ID_NAME;
                                $new->value = $found_value;
                                $new->state = $source->is_credible($server_id) ? Validator_identifiers::STATE_VALIDATED : Validator_identifiers::STATE_NEW;
                                $new->active = Validator_identifiers::INACTIVE;
                                
                                $new->save();

                                $found[] = $new;
                            }
                        }

                        // Iterate over found values and activate the best one
                        $best = [];
                        foreach($found as $vi)
                        {
                            $best[$vi->id] = 100-strlen($vi->value);
                        }

                        arsort($best);

                        if(count($best) && 
                            (Identifiers::is_identifier($name_o->value) || $name_o->id_source != null))
                        {
                            $best = array_keys($best)[0];
                            $best = new Validator_identifiers($best);
                            $best->active = Validator_identifiers::ACTIVE;
                            $best->save();
                        }

                        if(!$unreachable) // If all servers were reachable, can close the log
                        {
                            $log->update_state($s->id, $log->type, $log::STATE_DONE);
                        }
                    }
                    catch(MmdbException $e)
                    {
                        $this->print($e->getMessage());
                        $log->update_state($s->id, $log->type, $log::STATE_ERROR, $e->getMessage());
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
                throw $e;
            }
        }
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
     * 
     * @deprecated
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
     * @deprecated
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
                $this->print("Error occured while deleting membrane [" . $membrane->name . "].");
                $this->print($e->getMessage());
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
                $this->print("Error occured while deleting method [" . $method->name . "].");
                $this->print($e->getMessage());
            }
        }

        if($del_membranes)
        {
            $this->print("Total $del_membranes membranes deleted.");
        }

        if($del_methods)
        {
            $this->print("Total $del_methods methods deleted.");
        }
    }


    // /**
    //  * Finds molecules without passive or active interactions
    //  * and removes them from the DB 
    //  *  - Pubchem LogP doesnt count as interaction
    //  *  - Molecule must have missing identifiers
    //  * 
    //  */
    // public function delete_empty_substances()
    // {
    //     $subst_model = new Substances();
    //     $empty = $subst_model->get_empty_substances();
    //     $total = 0;
    //     $molecules = array(['Deleted molecules']);

    //     foreach($empty as $s_id)
    //     {
    //         $substance = new Substances($s_id);

    //         if(!$substance->id)
    //         {
    //             continue;
    //         }

    //         $total++;

    //         $molecules[] = [$substance->name];
    //         $substance->delete();
    //     }

    //     // Save report and send to the admins
    //     if($total)
    //     {
    //         try
    //         {
    //             $report = new File();
    //             $name = $report->generateName();
    //             $file_path = MEDIA_ROOT . 'files/schedulerReports/subs_delete_' . $name . '.csv';
    //             $report->create($file_path);
    //             $report->writeCSV($molecules);

    //             $f = new Files();
    //             $f->path = $file_path;
    //             $f->name = $name;
    //             $f->type = $f::T_SCHEDULER_DEL_EMPTY_SUBSTANCES;

    //             $f->save();

    //             // Send emails
    //             $this->send_email_to_admins('A total of ' . $total . ' molecules were deleted.', 'Scheduler autoremove report', $file_path);
    //         }
    //         catch(MmdbException $e)
    //         {
    //             $this->print($e->getMessage());
    //             $this->run->id_exception = $e->getExceptionId();
    //         }

    //         $this->print('Total ' . $total . ' substances were deleted.');
    //     }
    // }

    /**
     * Checks interactions datasets
     *  1) Joins same datasets - If two datasets has the same membrane/method/secondary_reference/author
     *      and where uploaded in the same day => JOIN THEM
     *  2) Removes datasets without data
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

                // Change file links
                $interaction_model->query('
                    UPDATE `files`
                    SET `id_dataset_passive` = ?
                    WHERE `id_dataset_passive` = ?
                ', array($final_id, $dataset_id));
            }
        }

        if(count($duplcs))
        {
            $this->print('Total ' . count($duplcs) . " datasets were joined.");
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
            $this->print('Total ' . $removed . " empty datasets were removed.");
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
                $name = $report->generateName();
                $file_path = MEDIA_ROOT . 'files/schedulerReports/inter_ds_check_' . $name . '.csv';
                $report->create($file_path);
                $report->writeCSV($csv);

                $text = "Some interaction datasets were deleted/joined. <br/>"
                    .   " - Total deleted: " . $removed . '<br/>'
                    .   " - Total joined: " . $joioned;

                // Send emails
                $this->send_email_to_admins($text, 'Scheduler dataset checker report');

                $file = new Files();
                $file->name = $report->generateName();
                $file->path = $file_path;
                $file->type = Files::T_SCHEDULER_CHECK_PASSIVE_DATASETS;
                $file->save();
            }
            catch(MmdbException $e)
            {
                $this->print($e->getMessage());
                $this->run->id_exception = $e->getExceptionId();
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
        //     $this->print('Total ' . count($duplcs) . " datasets were joined. \n";
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
        //     $this->print('Total ' . $removed . " empty datasets were removed. \n";
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
}

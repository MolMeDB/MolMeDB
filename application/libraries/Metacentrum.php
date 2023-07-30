<?php


/**
 * Service for handling with metacentrum jobs
 * 
 * @author Jakub Juračka
 */
class Metacentrum 
{
    const BASE_PATH = APP_ROOT . "scripts/";

    /** 
     * COSMO LOG explanations
     */
    const CODE_INIT = 0;
    const CODE_OK = 1;
    const CODE_WAITING = 2;
    const CODE_ERROR = 3;
    const CODE_EXISTS = 10;
    const CODE_IS_RUNNING = 11;
    const CODE_HAS_RESULT = 12;

    /**
     * COSMO steps
     */
    const CSM_STEP_INPUT_CHECK = 0;
    const CSM_STEP_INIT = 1;
    const CSM_STEP_CHECK_RESULT = 2;
    const CSM_STEP_REMOTE_FOLDER = 3;
    const CSM_STEP_UPLOAD = 4;
    const CSM_STEP_CUBY_CHECK_STATUS = 5;
    const CSM_STEP_CUBY_GENERATE = 6;
    const CSM_STEP_CUBY_UPLOAD = 7;
    const CSM_STEP_CUBY_RUN = 8;
    const CSM_STEP_CUBY_RUN_JOB = 81;
    const CSM_STEP_COSMO_PREPARE = 9;
    const CSM_STEP_COSMO_RUN = 10;
    const CSM_STEP_COSMO_DOWNLOAD = 11;

    /**
     * Queues
     */
    const QUEUE_ELIXIR = 'elixir';
    const QUEUE_SKIRIT = 'skirit';
    const QUEUE_DEFAULT = 'metacentrum';

    /**
     * Default host
     */
    const HOST = 'zuphux.metacentrum.cz';

    /**
     * Queue servers
     */
    private static $queue_servers = array
    (
        self::QUEUE_ELIXIR => 'elmo.metacentrum.cz',
        self::QUEUE_DEFAULT => 'zuphux.metacentrum.cz',
        self::QUEUE_SKIRIT => 'skirit.metacentrum.cz'
    );

    /**
     * Returns list of jobs in queue
     * 
     * @param string $username
     * @param string $password
     * @param string $queue
     * 
     * @return Metacentrum_job[]
     */
    public static function get_job_list($username, $password, $queue, $include_finished = False)
    {
        if(!array_key_exists($queue, self::$queue_servers))
        {
            throw new MmdbException('Invalid queue.');
        }

        $server = self::$queue_servers[$queue];

        $script = "python3 " . APP_ROOT . 'scripts/metacentrum_jobs.py';
        $params = array
        (
            '--username' => $username,
            '--password' => $password,
            '--host'     => $server 
        );

        if($include_finished)
        {
            $params['--include_finished'] = 'true';
        }

        foreach($params as $key => $val)
        {
            $script .= ' ' . $key . ' \'' . $val . '\'';
        }

        $output = [];

        exec($script, $output, $code);

        if(count($output) == 0)
        {
           $output = [];
        }
        else if(count($output) == 1)
        {
            $output = json_decode($output[0]);
        }
        else
        {
            return null; // Error
        }

        $result = [];
        $started = false;
        $curr_row = [];

        if(!is_iterable($output)) // Empty result
        {
            return [];
        }

        foreach($output as $row)
        {
            if(!is_array($row) || empty($row))
            {
                $started = false;
                $job = new Metacentrum_job($curr_row);
                $result[$job->job_name] = $job;
                $curr_row = [];
                continue;
            }

            if($started)
            {
                $row = implode(' ', $row);
                $row = explode('=', $row);
                if(count($row) < 2)
                {
                    continue;
                }

                $curr_row[trim($row[0])] = trim($row[1]);
                continue;
            }

            $row = implode(' ', $row);

            if(preg_match('/^\s*Job\s+Id/', $row))
            {
                $started = true;
                $curr_row['job_id'] = trim(explode(':', $row)[1]);
            }
        }

        return $result;
    }

    /**
     * Runs cosmo on metacentrum and download results
     * 
     * @param Run_cosmo[] $cosmo_run
     */
    public static function run_cosmo($cosmo_runs, $host, $username, $password, $queue, $limit = 20, $force = false)
    {
        $valid_runs = [];
        $script_inputs = [];
        $force_runs = [];

        if(!count($cosmo_runs))
        {
            return;
        }

        foreach($cosmo_runs as $cosmo_run)
        {
            if(!$cosmo_run || !$cosmo_run->id)
            {
                continue;
            }

            // Check status
            if($cosmo_run->status !== Run_cosmo::STATUS_OK || $cosmo_run->state < Run_cosmo::STATE_SDF_READY)
            {
                continue;
            }

            $force_runs[] = $cosmo_run->forceRun != NULL ? $cosmo_run->id : null;

            $f = new File();

            // Load all ions
            $ions = Fragment_ionized::instance()->where('id_fragment', $cosmo_run->id_fragment)->get_all();

            if(!count($ions))
            {
                // Missig ions, run from the start
                $f->remove_conformer_folder($cosmo_run->id_fragment);
                $cosmo_run->state = Run_cosmo::STATE_PENDING;
                $cosmo_run->log = NULL;
                $cosmo_run->save();
                continue;
            }

            // Get logs
            $logs = new MetacentrumLog(MetacentrumLog::TYPE_COSMO_RUN, $cosmo_run->log);

            $membrane = new File($cosmo_run->membrane->cosmo_file->path);

            if(!$membrane->exists())
            {
                throw new Exception('Membrane file does not exist.');
            }

            $valid_runs[] = $cosmo_run;
            
            $err_ions = 0;

            foreach($ions as $ion)
            {
                if($ion->cosmo_flag == Fragment_ionized::COSMO_F_OPTIMIZE_ERR_COMLETE)
                {
                    continue;
                }

                $logs->add($ion->id);

                $path = $f->prepare_conformer_folder($cosmo_run->id_fragment, $ion->id);

                // Change files permissions
                foreach([$path, $membrane->origin_path] as $fl)
                {
                    exec("find $fl -exec chmod 0777 {} +");
                }

                // Add to run list
                $script_inputs[] = $cosmo_run->id_fragment . '/' . $ion->id . '/' . $cosmo_run->fragment->get_charge($ion->smiles);
            }
        }

        if(!count($valid_runs))
        {
            return;
        }

        $cosmo_run = $valid_runs[0];
        $membrane = new File($cosmo_run->membrane->cosmo_file->path);

        ///////////////////////////////////
        // Run COSMO
        $script = "python3 " . APP_ROOT . "scripts/cosmo_pipeline.py";
        $params = array
        (
            '--ions' => implode(' ', $script_inputs),
            '--host' => $host,
            '--username' => $username, 
            '--password' => $password,
            '--queue' => $queue,
            '--cpu'  => 8,
            '--ram'  => 32,
            '--limit' => $limit,
            '--cosmo' => $cosmo_run->get_script_method(),
            '--temp'  => $cosmo_run->temperature,
            '--membrane' => $membrane->origin_path,
            '--membName' => $cosmo_run->membrane->name,
        );

        if($force)
        {
            $params["force"] = "true";
        }

        foreach($params as $key => $val)
        {
            $script .= ' ' . $key . ' \'' . $val . '\'';
        }

        # Include errors? DEBUG ONLY
        if(DEBUG)
        {
            $script .= ' 2>&1';
        }

        $output = [];

        exec($script, $output, $code);

        $outputs = [];
        $init = TRUE; $index = 0;
        foreach($output as $o)
        {
            if(!strlen($o))
            {
                continue;
            }

            if(($init && !preg_match('/JOB:/', $o)))
            {
                continue;
            }
            else if(preg_match('/JOB:/', $o))
            {
                $init = false;
                $index = preg_replace('/\s+/', '', preg_replace('/^\s*JOB:/', '', $o));
                $outputs[$index] = [];
            }

            $outputs[$index][] = $o;
        }

        // Process results
        foreach($valid_runs as $cosmo_run)
        {
            // Load all ions
            $ions = Fragment_ionized::instance()->where('id_fragment', $cosmo_run->id_fragment)->get_all();

            $last_steps = [];
            $increased = false;

            foreach($ions as $ion)
            {
                if($ion->cosmo_flag == Fragment_ionized::COSMO_F_OPTIMIZE_ERR_COMLETE)
                {
                    continue;
                }

                $last_steps[$ion->id] = Metacentrum::CSM_STEP_INPUT_CHECK;
                $log_key = $cosmo_run->id_fragment . '/' . $ion->id . '/' . $cosmo_run->fragment->get_charge($ion->smiles);

                if(!isset($outputs[$log_key])) // Log not exists
                {
                    echo $cosmo_run->id . ' / ' . $log_key . ' / Log not found';
                    continue;
                }

                $output = $outputs[$log_key];
                $path = $f->prepare_conformer_folder($cosmo_run->id_fragment, $ion->id);

                // Save whole log to the file
                array_unshift($output, "\n\nRUN - " . date("Y-m-d H:i:s"));
                file_put_contents($path . 'cosmo.log', implode("\n", $output), FILE_APPEND);

                $err_confs = 0;
                $connected = FALSE;
                
                // Process output
                foreach($output as $row)
                {
                    // Process just logs
                    if(!preg_match('/^\s*_LOG_/', $row))
                    {
                        continue;
                    }

                    $l = preg_replace('/^\s*_LOG_:\s*/', '', $row);
                    $l = trim($l);

                    $tuple = explode(' ', $l);

                    if(count($tuple) < 2)
                    {
                        continue;
                    }
                    $ss = explode('/', $tuple[0]);

                    $logs->setState($ss[0], $ss[1], $tuple[1]);

                    if($ss[0] == self::CSM_STEP_INIT && $ss[1] == Metacentrum::CODE_OK)
                    {
                        $connected = TRUE;
                    }

                    if( in_array($ss[1], [Metacentrum::CODE_OK, Metacentrum::CODE_EXISTS, Metacentrum::CODE_IS_RUNNING]) && $ss[0] != self::CSM_STEP_CUBY_RUN_JOB)
                    {
                        $last_steps[$ion->id] = $ss[0];
                    }

                    if($ss[1] == Metacentrum::CODE_ERROR && $ss[0] == self::CSM_STEP_CUBY_RUN_JOB)
                    {
                        $err_confs++;
                    }
                    else if ($ss[1] == self::CODE_OK && $ss[0] == self::CSM_STEP_COSMO_DOWNLOAD)
                    {
                        $ion->cosmo_flag = max(intval($ion->cosmo_flag), Fragment_ionized::COSMO_F_COSMO_DOWNLOADED);
                    }
                    // If error occured, stop computing
                    else if($ss[1] == Metacentrum::CODE_ERROR)
                    {
                        $increased = True;
                        break;
                    }
                }

                if(!$connected)
                {
                    throw new MmdbException('Cannot connect to metacentrum server. Log: ' . $cosmo_run->id_fragment . '/' . $ion->id);
                }

                $total_confs = scandir($path);
                $total_confs = count(array_filter($total_confs, function($a){return preg_match('/\.sdf$/', $a);}));

                if($err_confs > $total_confs/2)
                {
                    $ion->cosmo_flag = max(intval($ion->cosmo_flag), Fragment_ionized::COSMO_F_OPTIMIZE_ERR_COMLETE);
                    $err_ions++;
                }
                else if ($err_confs)
                {
                    $ion->cosmo_flag = max(intval($ion->cosmo_flag), Fragment_ionized::COSMO_F_OPTIMIZE_ERR_PARTIAL);
                }

                $ion->save();
            }

            if(count($ions) == $err_ions)
            {
                $cosmo_run->status = Run_cosmo::STATUS_ERROR;
            }

            if($increased)
            {
                $cosmo_run->increase_error_count();
            }
            else if(count($ions) != $err_ions) // No fatal error?
            {
                $cosmo_run->error_count = NULL;
                $cosmo_run->status = Run_cosmo::STATUS_OK;
            }

            $last_steps = array_map(function($a){return $a == Metacentrum::CSM_STEP_CHECK_RESULT ? Metacentrum::CSM_STEP_COSMO_DOWNLOAD : $a;}, $last_steps);
            
            if(!empty($last_steps))
            {
                // Minimum of successful steps
                $min_step = min($last_steps);

                // Change state
                if($min_step == Metacentrum::CSM_STEP_COSMO_DOWNLOAD)
                {
                    $cosmo_run->state = Run_cosmo::STATE_RESULT_DOWNLOADED;
                }
                else if($min_step < Metacentrum::CSM_STEP_CUBY_CHECK_STATUS)
                { // Weird behaviour
                    $cosmo_run->state = Run_cosmo::STATE_SDF_READY;
                }
                else if($min_step < Metacentrum::CSM_STEP_COSMO_PREPARE)
                {
                    $cosmo_run->state = Run_cosmo::STATE_OPTIMIZATION_RUNNING;
                    $cosmo_run->next_remote_check = date('Y-m-d H:i:s', strtotime("+$limit hours"));
                }
                else if($min_step < Metacentrum::CSM_STEP_COSMO_DOWNLOAD)
                {
                    $cosmo_run->state = Run_cosmo::STATE_COSMO_RUNNING;
                    $cosmo_run->next_remote_check = date('Y-m-d H:i:s', strtotime("+5 hours"));
                }

                // And save logs
                $cosmo_run->log = $logs->stringify();
            }

            $cosmo_run->forceRun = NULL;
            $cosmo_run->save();
        }

    }


    /**
     * Runs cosmo on metacentrum and download results
     * 
     * @param Run_cosmo[] $cosmo_runs
     */
    public static function run_failed_cosmo($cosmo_runs, $ion_ids, $host, $username, $password, $queue, $limit = 20)
    {
        $valid_runs = [];
        $script_inputs = [];

        if(!count($cosmo_runs))
        {
            return;
        }

        foreach($cosmo_runs as $cosmo_run)
        {
            if(!$cosmo_run || !$cosmo_run->id)
            {
                continue;
            }

            $f = new File();

            $cosmo_ions = Fragment_ionized::instance()->where('id_fragment', $cosmo_run->id_fragment)->get_all();

            // Get logs
            $logs = new MetacentrumLog(MetacentrumLog::TYPE_COSMO_RUN, $cosmo_run->log);

            $membrane = new File($cosmo_run->membrane->cosmo_file->path);

            if(!$membrane->exists())
            {
                throw new Exception('Membrane file does not exist.');
            }

            $valid_runs[] = $cosmo_run;
            
            foreach($cosmo_ions as $ion)
            {
                if(!in_array($ion->id, $ion_ids))
                {
                    continue;
                }

                $logs->add($ion->id);

                // Add to run list
                $script_inputs[] = $cosmo_run->id_fragment . '/' . $ion->id . '/' . $cosmo_run->fragment->get_charge($ion->smiles);
            }
        }

        if(!count($valid_runs))
        {
            return;
        }

        $cosmo_run = $valid_runs[0];
        $membrane = new File($cosmo_run->membrane->cosmo_file->path);

        ///////////////////////////////////
        // Run COSMO
        $script = "python3 " . APP_ROOT . "scripts/cosmo_pipeline.py";
        $params = array
        (
            '--ions' => implode(' ', $script_inputs),
            '--host' => $host,
            '--username' => $username, 
            '--password' => $password,
            '--queue' => $queue,
            '--cpu'  => 8,
            '--ram'  => 32,
            '--limit' => $limit,
            '--cosmo' => $cosmo_run->get_script_method(),
            '--temp'  => $cosmo_run->temperature,
            '--membrane' => $membrane->origin_path,
            '--membName' => $cosmo_run->membrane->name,
            '--reRun' => "true"
        );

        foreach($params as $key => $val)
        {
            $script .= ' ' . $key . ' \'' . $val . '\'';
        }

        # Include errors? DEBUG ONLY
        if(DEBUG)
        {
            $script .= ' 2>&1';
        }

        $output = [];

        exec($script, $output, $code);

        $outputs = [];
        $init = TRUE; $index = 0;
        foreach($output as $o)
        {
            if(!strlen($o))
            {
                continue;
            }

            if(($init && !preg_match('/JOB:/', $o)))
            {
                continue;
            }
            else if(preg_match('/JOB:/', $o))
            {
                $init = false;
                $index = preg_replace('/\s+/', '', preg_replace('/^\s*JOB:/', '', $o));
                $outputs[$index] = [];
            }

            $outputs[$index][] = $o;
        }

        // Process results
        foreach($valid_runs as $cosmo_run)
        {
            // Load all ions
            $ions = Fragment_ionized::instance()->where('id_fragment', $cosmo_run->id_fragment)->get_all();

            $last_steps = [];

            foreach($ions as $ion)
            {
                
                $log_key = $cosmo_run->id_fragment . '/' . $ion->id . '/' . $cosmo_run->fragment->get_charge($ion->smiles);
                
                if(!isset($outputs[$log_key])) // Log not exists
                {
                    continue;
                }

                $last_steps[$ion->id] = Metacentrum::CSM_STEP_INPUT_CHECK;

                $output = $outputs[$log_key];
                $path = $f->prepare_conformer_folder($cosmo_run->id_fragment, $ion->id);

                // Save whole log to the file
                array_unshift($output, "\n\nReRUN - " . date("Y-m-d H:i:s"));
                file_put_contents($path . 'cosmo.log', implode("\n", $output), FILE_APPEND);

                $connected = FALSE;
                
                // Process output
                foreach($output as $row)
                {
                    // Process just logs
                    if(!preg_match('/^\s*_LOG_/', $row))
                    {
                        continue;
                    }

                    $l = preg_replace('/^\s*_LOG_:\s*/', '', $row);
                    $l = trim($l);

                    $tuple = explode(' ', $l);

                    if(count($tuple) < 2)
                    {
                        continue;
                    }
                    $ss = explode('/', $tuple[0]);

                    $logs->setState($ss[0], $ss[1], $tuple[1]);

                    if($ss[0] == self::CSM_STEP_INIT && $ss[1] == Metacentrum::CODE_OK)
                    {
                        $connected = TRUE;
                    }

                    if($ss[0] == self::CSM_STEP_CUBY_RUN_JOB && $ss[1] == Metacentrum::CODE_OK)
                    {
                        $cosmo_run->state = Run_cosmo::STATE_OPTIMIZATION_RUNNING;
                        $cosmo_run->save();
                        $ion->cosmo_flag = NULL;
                        $ion->save();
                    }
                }

                if(!$connected)
                {
                    throw new MmdbException('Cannot connect to metacentrum server. Log: ' . $cosmo_run->id_fragment . '/' . $ion->id);
                }

            }
        }
    }
}

class MetacentrumLog
{
    /**
     * Types
     */
    const TYPE_COSMO_RUN = 1;

    /**
     * Valid types
     */
    private static $valid_types = array
    (
        self::TYPE_COSMO_RUN
    );

    private $type;
    private $data = [];
    private $current;

    /**
     * Constructor
     */
    function __construct($type, $data = [])
    {
        if(!in_array($type, self::$valid_types))
        {
            throw new MmdbException('Invalid metacentrum log type.');
        }

        $this->type = $type;
        if(is_array($data))
        {
            $this->data = $data;
        }
    }

    /**
     * Adds new record
     * 
     * @param int $id
     */
    public function add($id)
    {
        if(!isset($this->data[$id]))
        {
            $this->data[$id] = array();
        }
        $this->current = &$this->data[$id];
    }

    /**
     * Set new state
     */
    public function setState($state, $value, $note = NULL)
    {
        if($this->current === NULL)
        {
            throw new MmdbException("Invalid `current` instance.");
        }

        if($state == Metacentrum::CSM_STEP_CUBY_RUN_JOB) // save as array
        {
            if(isset($this->current[$state]))
            {
                $this->current[$state]->note[] = $note;
                $this->current[$state]->value == Metacentrum::CODE_ERROR ? Metacentrum::CODE_ERROR : $value;
            }
            else
            {
                $this->current[$state] = (object) array 
                (
                    'note' => array($note),
                    'value' => $value
                );
            }
            return;
        }

        if(isset($this->current[$state]))
        {
            $v = $this->current[$state]->value;
            if($v != $value)
            {
                $this->current[$state]->value = $value;
                $this->current[$state]->note = $note;
            }
        }
        else
        {
            $this->current[$state] = (object)array
            (
                'value' => $value,
                'note'  => $note
            );
        }
    }

    /**
     * Get state info
     */
    public function getStateValue($state, $id = null)
    {
        if($id)
        {
            if(!isset($this->data[$id]))
            {
                return null;
            }
            $c = $this->data[$id];
        }
        else
        {
            $c = $this->current;
        }

        if($c === null)
        {
            return null;
        }

        if(isset($this->current[$state]))
        {
            return $this->current[$state]->value;
        }

        return null;
    }

    /**
     * Get state note
     */
    public function getStateNote($state, $id)
    {
        if($id)
        {
            if(!isset($this->data[$id]))
            {
                return null;
            }
            $c = $this->data[$id];
        }
        else
        {
            $c = $this->current;
        }

        if(!$c)
        {
            return null;
        }

        if(isset($this->current[$state]))
        {
            return $this->current[$state]->note;
        }

        return null;
    }

    /**
     * Get all logs
     * 
     * @return array
     */
    public function getLogs()
    {
        return $this->data;
    }

    /**
     * Stringify logs
     * 
     * @return string
     */
    public function stringify()
    {
        return json_encode($this->data, JSON_FORCE_OBJECT);
    }
}


/**
 * Holds info about metacentrum jobs
 * 
 */
class Metacentrum_job 
{
    /** @var string */
    public $job_name;
    /** @var string */
    public $username;
    /** @var string */
    public $queue;
    /** @var string */
    public $cpu;
    /** @var string */
    public $ram;
    /** @var string */
    public $max_runtime;
    /** @var string */
    public $runtime;
    /** @var string */
    public $state;
    /** @var bool */
    public $killed = 0;
    /** @var int */
    public $id_fragment;
    /** @var int */
    public $id_ion;
    /** @var int */
    public $id_conformer;
    /** @var int */
    public $job_type;

    const TYPE_OPTIMIZATION = 1;
    const TYPE_COSMO = 2;

    /**
     * Constructor
     * 
     * @param array $data
     */
    function __construct($data)
    {
        // Init
        $this->job_name   = isset($data["Job_Name"]) ? $data["Job_Name"] : null;
        $this->username   = isset($data["Job_Owner"]) ? explode("@", $data["Job_Owner"])[0] : null;
        $this->queue      = isset($data["queue"]) ? $data["queue"] : null;
        $this->cpu        = isset($data["Resource_List.ncpus"]) ? $data["Resource_List.ncpus"] : null;
        $this->ram        = isset($data["Resource_List.mem"]) ? $data["Resource_List.mem"] : null;
        $this->max_runtime= isset($data["Resource_List.walltime"]) ? $data["Resource_List.walltime"] : null;
        $this->state      = isset($data["job_state"]) ? $data["job_state"] : null;
        $this->runtime    = isset($data["resources_used.walltime"]) ? $data["resources_used.walltime"] : null;

        if(strpos($this->job_name, 'MMDB_C_OPT') !== FALSE)
        {
            $this->job_type = self::TYPE_OPTIMIZATION;
        }
        else if(strpos($this->job_name, 'MMDB_COSMO') !== FALSE)
        {
            $this->job_type = self::TYPE_COSMO;
        }

        // process job name
        $nums = explode('_', $this->job_name);
        $nums = array_reverse($nums);

        if(count($nums) > 2)
        {
            $this->id_conformer = $nums[0];
            $this->id_ion = $nums[1];
            $this->id_fragment = $nums[2];
        }

        // Check, if process was killed (runtime reason)
        $max_hours = explode(':', $this->max_runtime);
        $used_hours = explode(':', $this->runtime);

        if(count($max_hours) < 2 || count($used_hours) < 2)
            return;

        $max_minutes = intval($max_hours[1]); $max_hours = intval($max_hours[0]);
        $used_minutes = intval($used_hours[1]); $used_hours = intval($used_hours[0]);

        $this->killed = $used_hours > $max_hours || 
            ($used_hours == $max_hours && $used_minutes >= $max_minutes) ? 1 : 0;
    }

    /**
     * Checks if job is running
     * 
     * @return boolean
     */
    public function is_running()
    {
        return $this->state == 'R';
    }

    /**
     * Checks if job is in queue
     * 
     * @return boolean
     */
    public function is_queued()
    {
        return $this->state == 'Q';
    }

    /**
     * Checks if job is finished
     * 
     * @return boolean
     */
    public function is_finished()
    {
        return $this->state == 'F' || $this->is_killed();
    }

    /**
     * Checks if job is killed
     * 
     * @return boolean
     */
    public function is_killed()
    {
        return $this->killed;
    }

    /**
     * Finds corresponding job in DB
     * 
     * @return Fragment_ionized
     */
    public function get_db_ion()
    {
        if(!$this->id_fragment || !$this->id_ion)
        {
            return null;
        }

        return Fragment_ionized::instance()->where(array
        (
            'id' => $this->id_ion,
            'id_fragment' => $this->id_fragment
        ))->get_one();
    }

    /**
     * Finds corresponding job in DB
     * 
     * @return null|Run_cosmo
     */
    public function get_db_cosmo_run()
    {
        if(!$this->id_fragment || !$this->id_ion)
        {
            return null;
        }

        return Run_cosmo::instance()->where(array
        (
            'id_fragment' => $this->id_fragment
        ))
        ->order_by('id')
        ->get_one();
    }
}
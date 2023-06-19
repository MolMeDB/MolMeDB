<?php

/**
 * Holds info about COSMO runs
 * 
 * @property int $id
 * @property int $state
 * @property int $error_count
 * @property int $status
 * @property int $forceRun
 * @property int $id_dataset
 * @property Run_cosmo_datasets $dataset
 * @property int $id_fragment
 * @property Fragments $fragment
 * @property int $id_membrane
 * @property Membranes $membrane
 * @property double $temperature
 * @property int $method
 * @property int $priority
 * @property string $create_date
 * @property string $last_update
 * @property string $log
 * 
 * @author Jakub Juračka
 */
class Run_cosmo extends Db
{
    /**
     * Constructor
     */
    function __construct($id = null)
    {
        $this->table = 'run_cosmo';
        parent::__construct($id);
    }

    /** STATES */
    const STATE_PENDING = 0;
    const STATE_IONIZED = 1;
    const STATE_SDF_READY = 2;
    const STATE_OPTIMIZATION_RUNNING = 3;
    const STATE_COSMO_RUNNING = 4;
    const STATE_RESULT_DOWNLOADED = 5;
    const STATE_RESULT_PARSED = 6;
    const STATE_RESULT_DB_STORED = 7;

    /** STATUS */
    const STATUS_STOP = 0;
    const STATUS_OK = 1;
    const STATUS_REMOVE = 2;
    const STATUS_ERROR = 3;

    /** Priorities */
    const PRIORITY_LOW = 1;
    const PRIORITY_MEDIUM = 2;
    const PRIORITY_HIGH = 3;

    /** METHODS */
    const METHOD_COSMOPERM = 1;
    const METHOD_COSMOMIC = 2;

    /** Enum methods */
    private static $enum_methods = array
    (
        self::METHOD_COSMOMIC => 'CosmoMic',
        self::METHOD_COSMOPERM => "CosmoPerm"
    );

    /** Enum methods */
    private static $enum_script_methods = array
    (
        self::METHOD_COSMOMIC => 'mic',
        self::METHOD_COSMOPERM => "perm"
    );

    /** Enum priorities */
    private static $enum_priorities = array
    (
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_MEDIUM => "Medium",
        self::PRIORITY_HIGH => "High"
    );

    /** Enum states */
    private static $enum_states = array
    (
        self::STATE_PENDING => 'Pending',
        self::STATE_IONIZED => 'Ionized',
        self::STATE_SDF_READY => 'SDF ready',
        self::STATE_OPTIMIZATION_RUNNING => 'Optimization run',
        self::STATE_COSMO_RUNNING => 'COSMO run',
        self::STATE_RESULT_DOWNLOADED => 'Data stored locally',
        self::STATE_RESULT_PARSED => 'Data file processed',
        self::STATE_RESULT_DB_STORED => 'DB saved'
    );

    /**
     * Enum status
     */
    private static $enum_status = array
    (
        self::STATUS_STOP   => 'Stopped',
        self::STATUS_OK     => "OK",
        self::STATUS_REMOVE => 'Removing',
        self::STATUS_ERROR  => 'Error'
    );

    protected $has_one = array
    (
        'id_fragment',
        'id_membrane',
        'id_dataset' => array
        (
            'class' => 'Run_cosmo_datasets',
            'var' => 'dataset'
        )
    );

    public function increase_error_count()
    {
        $this->error_count =  ($this->error_count !== null) ? $this->error_count + 1 : 1;
        if($this->error_count > 2)
        {
            // $this->error_count = 0;
            $this->status = Run_cosmo::STATUS_ERROR;
        }
        $this->save();
    }

    public static function get_all_status()
    {
        return self::$enum_status;
    }

    public static function get_all_states()
    {
        return self::$enum_states;
    }

    public static function is_valid_method($method)
    {
        return array_key_exists($method, self::$enum_methods);
    }

    public static function is_valid_priority($priority)
    {
        return array_key_exists($priority, self::$enum_priorities);
    }

    public static function get_all_methods()
    {
        return self::$enum_methods;
    }

    public static function get_all_priorities()
    {
        return self::$enum_priorities;
    }

    public function get_enum_state($state = null, $include_numeric = False)
    {
        if(!$state && is_numeric($this->state))
        {
            $state = $this->state;
        }

        if(!array_key_exists($state, self::$enum_states))
        {
            return null;
        }

        $s = self::$enum_states[$state];

        return ($include_numeric ? "(" . ($state + 1) . "/" . count(self::$enum_states) . ") " : "") . $s;
    }

    public function get_state_detail()
    {
        if(!$this->id)
        {
            return '';
        }

        if($this->state > self::STATE_SDF_READY)
        {
            // Parse logs and add info
            $ions = Fragment_ionized::instance()->where('id_fragment', $this->id_fragment)->get_all();

            $err = 0;
            foreach($ions as $ion)
            {  
                if($ion->cosmo_flag == Fragment_ionized::COSMO_F_OPTIMIZE_ERR_COMLETE)
                {
                    $err++;
                }
            }

            return 'Running: ' . (count($ions) - $err) . ' // Errors: ' . $err;
        }

        return '';
    }

    public function get_enum_status($status = null)
    {
        if(!$status && is_numeric($this->status))
        {
            $status = $this->status;
        }

        if(!array_key_exists($status, self::$enum_status))
        {
            return null;
        }

        return self::$enum_status[$status];
    }

    public function get_enum_method($method = null)
    {
        if(!$method && is_numeric($this->method))
        {
            $method = $this->method;
        }

        if(!array_key_exists($method, self::$enum_methods))
        {
            return null;
        }

        return self::$enum_methods[$method];
    }

    public function get_script_method($method = null)
    {
        if(!$method && is_numeric($this->method))
        {
            $method = $this->method;
        }

        if(!array_key_exists($method, self::$enum_methods))
        {
            return null;
        }

        return self::$enum_script_methods[$method];
    }

    public function get_enum_priority($priority = null)
    {
        if(!$priority && is_numeric($this->priority))
        {
            $priority = $this->priority;
        }

        if(!array_key_exists($priority, self::$enum_priorities))
        {
            return null;
        }

        return self::$enum_priorities[$priority];
    }

    /**
     * Merge all results for given ion and returns
     * 
     * @param Fragment_ionized $ion
     * 
     * @return object[]
     */
    public static function get_ion_results($ion)
    {
        if(!($ion instanceof Fragment_ionized) || !$ion->id)
        {
            throw new Exception('Invalid $ion instance.');
        }

        $f = new File();

        $cosmo_runs = self::instance()->where('id_fragment', $ion->id_fragment)->get_all();

        $results = [];

        foreach($cosmo_runs as $cosmo)
        {
            $path = $f->prepare_conformer_folder($cosmo->fragment->id, $ion->id);
            $path = $path . 'COSMO/';
            if(!file_exists($path)) // Results not exists yet
            {
                continue;
            }

            $files = array_filter(scandir($path), function($a){return !preg_match('/^\./', $a);});
            $prefix = strtolower($cosmo->get_script_method() . '_' . str_replace('/','_',str_replace(' ', '-',$cosmo->membrane->name)) . '_' . str_replace('.', ',', $cosmo->temperature));
            $prefix = trim($prefix);

            foreach($files as $pt)
            {
                if($prefix == strtolower(substr($pt, 0, strlen($prefix))))
                {
                    $out_path = $path . $pt . '/' . 'cosmo_parsed.json';

                    if(!file_exists($out_path) && $cosmo->state > Run_cosmo::STATE_RESULT_DOWNLOADED) // Exists results but not parsed!
                    {
                        $cosmo->state = Run_cosmo::STATE_RESULT_DOWNLOADED;
                        $cosmo->save();
                        continue;
                    }
                    elseif(!file_exists($out_path))
                    {
                        continue;
                    }

                    $data = json_decode(file_get_contents($out_path));
                    
                    foreach($data as $job)
                    {
                        $energy_dist = $job->layer_positions;
                        $temp = $job->temperature;

                        foreach($job->solutes as $sol)
                        {
                            $e_values = [];
                            
                            foreach($sol->energy_values as $e)
                            {
                                $e_values[] = round($e, 2);
                            }

                            $results[$cosmo->id] = (object)array
                            (
                                'logK' => $sol->logK,
                                'logPerm' => $sol->logPerm,
                                'energyValues' => $e_values,
                                'energyDistance' => $energy_dist,
                                'temperature' => $temp,
                                'membraneName' => $cosmo->membrane->name,
                                'methodName'   => $cosmo->get_enum_method()
                            );
                        }
                    }
                    break;
                }
            }
        }

        // Add chart data
        foreach($results as $cosmo_id => $data)
        {
            if(!$data->membraneName)
            {
                continue;
            }

            $distances = $data->energyDistance;
            $chart_data = [];

            sort($distances);
        
            foreach($distances as $d)
            {
                $chart_data[] = (object)array
                (
                    'x' => floatval($d)/10
                );
            }

            $yk = 'y';
            foreach($distances as $key => $d)
            {
                $index = array_search($d, $data->energyDistance);

                if($index === false)
                {
                    $chart_data[$key]->$yk = null;
                }
                else
                {
                    $chart_data[$key]->$yk = floatval($data->energyValues[$index]);
                }
            }

            $results[$cosmo_id]->chart_data = $chart_data;
        }

        return $results;
    }

    /**
     * Returns all results
     * 
     * @param $pagination
     * @param $per_page
     */
    public function get_list($id_dataset, $pagination, $per_page, $compound_query = NULL, $states = [], $status = [])
    {
        $join = $where = '';

        if($compound_query)
        {
            $join = 'JOIN fragments f ON f.id = rc.id_fragment
                    JOIN substances_fragments sf ON sf.id_fragment = f.id
                    JOIN (
                        SELECT vi.*
                        FROM validator_identifiers vi 
                        JOIN substances s ON s.id = vi.id_substance
                        WHERE s.identifier LIKE "%' . $compound_query . '%" OR vi.value LIKE "%' . $compound_query . '%"
                        ) as vi ON sf.id_substance = vi.id_substance';
        }


        if($states && !empty($states))
        {
            $where = "rc.state IN('" . implode("','", $states) . "') ";
        }

        if($status && !empty($status))
        {
            $where .= $where == '' ? '' : ' AND ';
            $where .= "rc.status IN('" . implode("','", $status) . "') ";         
        }

        $where = $where != '' ? 'WHERE ' . $where . ' AND rc.id_dataset = ' . $id_dataset : ' WHERE rc.id_dataset = ' . $id_dataset;

        return Run_cosmo::instance()->queryAll(
            "SELECT rc.id_fragment, MIN(rc.state) as state, MAX(rc.status) as status, rc.create_date, rc.last_update, rc.id_dataset
            FROM run_cosmo rc
            $join
            $where
            GROUP BY id_fragment
            ORDER BY rc.id ASC
            LIMIT ?,?"
        , array(($pagination-1) * $per_page, $per_page));
    }

    /**
     * Returns count of all results
     */
    public function get_list_count($id_dataset, $compound_query = NULL, $states = [], $status = [])
    {
        $join = $where = '';

        if($compound_query)
        {
            $join = 'JOIN fragments f ON f.id = rc.id_fragment
                    JOIN substances_fragments sf ON sf.id_fragment = f.id
                    JOIN validator_identifiers vi ON vi.id_substance = sf.id_substance AND vi.value LIKE "%' . $compound_query . '%"';
        }

        if($states && !empty($states))
        {
            $where = "rc.state IN('" . implode("','", $states) . "') ";
        }

        if($status && !empty($status))
        {
            $where .= $where == '' ? '' : ' AND ';
            $where .= "rc.status IN('" . implode("','", $status) . "') ";         
        }

        $where = $where != '' ? 'WHERE ' . $where . ' AND rc.id_dataset = ' . $id_dataset : ' WHERE rc.id_dataset = ' . $id_dataset;

        return Run_cosmo::instance()->queryOne(
            "SELECT COUNT(*) as count
            FROM (
                SELECT rc.id_fragment
                FROM run_cosmo rc
                $join
                $where
                GROUP BY id_fragment
            ) as t"
            )->count;
    }

    /**
     * Process final data and save them in json to file
     * 
     * @author Jakub Juracka
     */
    public function process_results()
    {
        if(!$this || !$this->id)
        {
            throw new MmdbException('Invalid db instance.');
        }

        // if($this->state !== self::STATE_RESULT_DOWNLOADED)
        // {
        //     return false;
        // }

        $fl = new File();

        // Load all ions
        $ions = Fragment_ionized::instance()->where('id_fragment', $this->id_fragment)->get_all();

        foreach($ions as $ion)
        {
            $path = $fl->prepare_conformer_folder($this->id_fragment, $ion->id) . 'COSMO/';
            $done = FALSE;

            if(!file_exists($path))
            {
                continue; // Results not exists
            }

            $pos_results = array_filter(scandir($path), function($a){return !preg_match('/^\./', $a);});
            $temp = str_replace('.', ',', $this->temperature);

            if(strpos($temp, ',') === false)
            {
                $temp .= ',0';
            }

            $req_folder = $this->get_script_method() . '_' . str_replace('/','_',str_replace(' ', '-',$this->membrane->name)) . '_' . $temp;

            foreach($pos_results as $folder)
            {
                if($folder != $req_folder)
                {
                    continue;
                }

                $f_path = $path . $folder . '/';

                $out_path = $f_path . 'cosmo_parsed.json';

                if(file_exists($out_path) && filesize($out_path) < 100)
                {
                    unlink($out_path);
                }

                if(!is_dir($f_path))
                {
                    continue;
                }

                if(!file_exists($f_path . 'cosmo.xml'))
                {
                    continue;
                }

                // Check if data were already processed
                if(file_exists($f_path . 'cosmo_parsed.json'))
                {
                    continue;
                }

                $inp_path = $f_path . 'cosmo.xml';

                // Parse results and save as json
                $content = file_get_contents($inp_path);
                $xml = simplexml_load_string($content);

                if($xml === FALSE)
                {
                    throw new MmdbException('Cannot parse cosmo XML file.');
                }

                if(!property_exists($xml, 'joblist'))
                {
                    throw new MmdbException('Missing joblist parameter in XML file.');
                }

                $final = [];

                // Process each job
                foreach($xml->joblist as $job)
                {
                    // If only one record in joblist
                    if(property_exists($job, 'job'))
                    {
                        $job = $job->job;
                    }

                    $required_job_attributes = array
                    (
                        'jobnumber',
                        'symmetry',
                        'nrlayer',
                        'layerposition',
                        'temperature',
                        'solutelist'
                    );

                    foreach($required_job_attributes as $attr)
                    {
                        if(!property_exists($job, $attr))
                        {
                            throw new MmdbException("Missing `" . $attr . "` attribute in job.");
                        }
                    }
                    
                    $jn = trim($job->jobnumber);
                    $final[$jn] = array
                    (
                        'symmetry' => trim($job->symmetry),
                        'layer_count' => trim($job->nrlayer),
                        'layer_positions' => explode(" ", preg_replace("/\s+/", " ", trim($job->layerposition))),
                        // Convert to celsius
                        'temperature' => floatval($job->temperature) > 200 ? floatval($job->temperature) - 273.15 : floatval($job->temperature),
                        'solutes' => array()
                    );

                    // Round layer positions
                    foreach($final[$jn]['layer_positions'] as $key => $pos)
                    {
                        $final[$jn]['layer_positions'][$key] = round($pos, 1);
                    }

                    foreach($job->solutelist as $solute)
                    {
                        if(property_exists($solute,'solute'))
                        {
                            $solute = $solute->solute;
                        }

                        $required_sol_attributes = array
                        (
                            'name',
                            'meanposition',
                            'distribution',
                            'logP_micelle_water',
                            // 'logPerm_membrane_cm_s'
                        );

                        foreach($required_sol_attributes as $attr)
                        {
                            if(!property_exists($solute, $attr))
                            {
                                throw new MmdbException("Missing `" . $attr . "` attribute in solute [input:" . $inp_path . "].");
                            }
                        }

                        $sn = trim($solute->number);
                        $solutes = [];
                        $solutes[$sn] = array
                        (
                            'name' => trim($solute->name),
                            'mean_position' => floatval($solute->meanposition),
                            'logK' => floatval($solute->logP_micelle_water),
                            'logPerm' => property_exists($solute, 'logPerm_membrane_cm_s')? floatval($solute->logPerm_membrane_cm_s) : null,
                            'energy_values' => array(),
                        );

                        // Add energu values
                        $energies = trim($solute->distribution);
                        $energies = explode(' ', preg_replace('/\s+/', " ", $energies));
                        $invalid_energy = FALSE;
                        
                        foreach($energies as $e)
                        {
                            if(!is_numeric($e) || $e == 0)
                            {
                                $invalid_energy = TRUE;
                                break;
                            }

                            $e = floatval($e);
                            $last = -8.314 * floatval($job->temperature) * log($e) / 4185;
                            $solutes[$sn]['energy_values'][] = $last; 
                        }

                        if(!$invalid_energy)
                        {
                            foreach($solutes[$sn]['energy_values'] as $key => $v)
                            {
                                $solutes[$sn]['energy_values'][$key] = round($solutes[$sn]['energy_values'][$key] - $last, 2);
                            }
                        }
                        else
                        {
                            $solutes[$sn]['energy_values'] = [];
                        }
                        $final[$jn]['solutes'] = $solutes;
                    }

                    if(empty($final))
                    {
                        throw new MmdbException('Invalid file structure.');
                    }

                    // Save result
                    $out_path = $f_path . 'cosmo_parsed.json';
                    if(!file_put_contents($out_path, json_encode($final)))
                    {
                        throw new MmdbException('Cannot save final parsed data.');
                    }

                    if(!file_exists($out_path) || filesize($out_path) < 100)
                    {
                        throw new MmdbException('Output file is empty.');
                    }

                    // Set permissions
                    chmod($out_path, 0777);
                    $done = TRUE;
                }
            }

            if($done)
            {
                $ion->cosmo_flag = max(Fragment_ionized::COSMO_F_COSMO_PARSED, intval($ion->cosmo_flag));
                $ion->save();
            }
        }
    }
}
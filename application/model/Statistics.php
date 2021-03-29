<?php

/**
 * Stats model
 * Stores stats data for loading speed improvement
 * 
 * @param int $id
 * @param int $type
 * @param string $content
 * @param string $data_path
 * @param string $update_date
 * 
 * @author Jakub Juracka
 */
class Statistics extends Db
{
    /** STATS TYPE - INTERACTION ADDING STATS */
    const TYPE_INTER_ADD = 1;
    const TYPE_INTER_ADD_FILE = File::FOLDER_STATS_DOWNLOAD . 'interactions_all.zip';

    /** STATS TYPE - TOTAL INTERACTION */
    const TYPE_INTER_TOTAL = 2;

    /** STATS TYPE - TOTAL SUBSTANCES */
    const TYPE_SUBST_TOTAL = 3;

    /** STATS TYPE - TOTAL MEMBRANES */
    const TYPE_MEMBRANES_TOTAL = 4;

    /** STATS TYPE - TOTAL METHODS */
    const TYPE_METHODS_TOTAL = 5;

    /** STATS TYPE - MEMBRANES DATA STATS */
    const TYPE_MEMBRANES_DATA = 6;

    /** STATS TYPE - METHODS DATA STATS */
    const TYPE_METHODS_DATA = 7;

    /** STATS TYPE - IDENTIFIERS DATA */
    const TYPE_IDENTIFIERS = 8;
    const TYPE_IDENTIFIERS_FILE = File::FOLDER_STATS_DOWNLOAD . 'identifiers.zip';

    /** STATS TYPE - INTERACTIONS ACTIVE/PASSIVE */
    const TYPE_INTER_PASSIVE_ACITVE = 9;
    const TYPE_INTER_PASSIVE_ACITVE_FILE = File::FOLDER_STATS_DOWNLOAD . 'active_passive_interactions.zip';

    /** STATS TYPE - ACTIVE INTERACTIONS STATS */
    const TYPE_INTER_ACTIVE = 10;


    public static function exists_stat_file($type)
    {
        if(!in_array($type, self::$valid_file_types))
        {
            return False;
        }

        return file_exists($type);
    }

    /**
     * Holds info about valid file types
     */
    private static $valid_file_types = array
    (
        self::TYPE_INTER_ADD_FILE,
        self::TYPE_IDENTIFIERS_FILE,
        self::TYPE_INTER_PASSIVE_ACITVE_FILE
    );
    
    /**
     * Holds info about valid types
     */
    private static $valid_types = array
    (
        self::TYPE_INTER_ACTIVE,
        self::TYPE_INTER_ADD,
        self::TYPE_MEMBRANES_DATA,
        self::TYPE_METHODS_DATA,
        self::TYPE_IDENTIFIERS,
        self::TYPE_INTER_PASSIVE_ACITVE,
        self::TYPE_INTER_TOTAL,
        self::TYPE_SUBST_TOTAL,
        self::TYPE_METHODS_TOTAL,
        self::TYPE_MEMBRANES_TOTAL,
    );

    /** HOLDS INFO ABOUT TYPE */
    private static $is_numeric = array
    (
        self::TYPE_INTER_TOTAL,
        self::TYPE_SUBST_TOTAL,
        self::TYPE_METHODS_TOTAL,
        self::TYPE_MEMBRANES_TOTAL,
    );

    private static $is_json = array
    (
        self::TYPE_INTER_ADD,
        self::TYPE_MEMBRANES_DATA,
        self::TYPE_METHODS_DATA,
        self::TYPE_IDENTIFIERS,
        self::TYPE_INTER_PASSIVE_ACITVE,
        self::TYPE_INTER_ACTIVE
    );

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'stats';
        parent::__construct($id);
    }

    /**
     * Checks, if type is valid
     * 
     * @param int $type
     * 
     * @return boolean
     */
    public static function is_valid($type)
    {
        return in_array($type, self::$valid_types);
    }

    /**
     * Checks, if type is numeric
     * 
     * @param int $type
     * 
     * @return boolean
     */
    public static function is_numeric($type)
    {
        return in_array($type, self::$is_numeric);
    }

    /**
     * Checks, if type is json 
     * 
     * @param int $type
     * 
     * @return boolean
     */
    public static function is_json($type)
    {
        return in_array($type, self::$is_json);
    }

    /**
     * Returns stats for given type
     * 
     * @param int $type
     * 
     * @return string|array|null
     */
    public function get($type = NULL)
    {
        if(!self::is_valid($type) && !$this->id)
        {
            return null;
        }

        $obj = $this;

        if(!$obj->id || $type)
        {
            $obj = $this->where('type', $type)->get_one();
        }

        if(!$obj->id)
        {
            $obj = $this->update($type);
        }

        if(!$obj->id)
        {
            return NULL;
        }

        if(self::is_numeric($obj->type))
        {
            if(is_numeric($obj->content))
            {
                return intval($obj->content);
            }
            else
            {
                return 0;
            }
        }
        else if(self::is_json($obj->type))
        {
            return json_decode($obj->content);
        }
    }

    /**
     * Updates stats by type
     * 
     * @param int $type
     * 
     * @return Statistics
     */
    public function update($type)
    {
        if(!self::is_valid($type))
        {
            return NULL;
        }

        $obj = $this->where('type', $type)->get_one();

        if(!$obj->id)
        {
            $obj = new Statistics();
            $obj->type = $type;

            $obj->save();
        }

        $inter_model = new Interactions();
        $transporter_model = new Transporters();
        $mem_model = new Membranes();
        $meth_model = new Methods();
        $subs_model = new Substances();

        $path = NULL;

        switch($type)
        {
            case self::TYPE_INTER_TOTAL:
                $obj->content = $inter_model->count_all() + $transporter_model->count_all();
                break;

            case self::TYPE_MEMBRANES_TOTAL:
                $obj->content = $mem_model->count_all();
                break;

            case self::TYPE_METHODS_TOTAL:
                $obj->content = $meth_model->count_all();
                break;

            case self::TYPE_SUBST_TOTAL:
                $obj->content = $subs_model->count_all();
                break;

            case self::TYPE_IDENTIFIERS:
                $obj->content =self::get_identifiers_stats();
                $path = self::get_identifiers_data();
                break;

            case self::TYPE_INTER_ADD:
                $obj->content =self::get_interaction_substances_adding_stats();
                $path = self::get_interaction_substances_adding_data();
                break;

            case self::TYPE_INTER_PASSIVE_ACITVE:
                $obj->content =$this->get_passive_active_int_substance_stats();
                $path = $this->get_passive_active_int_substance_data();
                break;

            case self:: TYPE_MEMBRANES_DATA:
                $obj->content =self::get_membranes_stats(TRUE);
                break;

            case self:: TYPE_METHODS_DATA:
                $obj->content =self::get_methods_stats(TRUE);
                break;

            case self::TYPE_INTER_ACTIVE:
                $obj->content = self::get_active_interactions_stats();
                break;

            default:
                return null;
        }

        if(self::is_numeric($type))
        {
            $obj->content = intval($obj->content);
        }
        else if(self::is_json($type))
        {
            $obj->content = json_encode($obj->content);
        }

        $obj->update_date = date('Y-m-d H:i:s');
        $obj->data_path = $path;

        $obj->save();

        return $obj;
    }

    /**
     * Returns update day
     * 
     * @return string
     */
    public function get_last_update_date()
    {
        $total = $this->count_all();

        if(!$total)
        {
            $this->update_all();
        }

        $obj = $this->order_by('update_date', 'ASC')->get_one();

        return $obj->update_date; 
    }

    /**
     * Updates all records
     * 
     * @author Jakub Juracka
     */
    public function update_all()
    {
        $types = self::$valid_types;  
        // $types = [self::TYPE_INTER_PASSIVE_ACITVE];

        foreach($types as $type)
        {
            $this->update($type);
        }
    }
    

    /**
     * Returns data for methods stats
     * 
     * @return array
     */
    private static function get_methods_stats($log_scale = FALSE)
    {   
        $methodModel = new Methods();
        $interactionModel = new Interactions();

        $methods = $methodModel->select_list(array('id', 'name'))->get_all();

        $result = [];

        $maxValue = 0;

        foreach($methods as $m)
        {
            $count_interactions = $interactionModel
                ->where('id_method', $m->id)
                ->count_all();

            $count_substances = $interactionModel
                ->join('substances s ON s.id = interaction.id_substance AND interaction.id_method = ' . $m->id)
                ->select_list('DISTINCT s.id')
                ->count_all();

            $max = $count_interactions > $count_substances ? $count_interactions : $count_substances;
            $maxValue = $max > $maxValue ? $max : $maxValue;

            $result[] = array
            (
                'id' => $m->id,
                'method' => $m->name,
                'count_interactions' => $count_interactions,
                'count_substances' => $count_substances
            );
        }

        // Sort by interactions
        usort($result, function($a, $b) {
            return $b['count_interactions'] - $a['count_interactions'];
        });

        if($log_scale)
        {
            for($i = 0; $i < count($result); $i++)
            {
                $result[$i]['count_interactions'] = $result[$i]['count_interactions'] > 0 ? $result[$i]['count_interactions'] : 1;
                $result[$i]['count_substances'] = $result[$i]['count_substances'] > 0 ? $result[$i]['count_substances'] : 1;
            }
        }

        if($log_scale)
        {
            $axis_breaks = null;
        }
        else
        {
            $axis_breaks = self::get_axis_breaks($result, array('count_interactions')) + self::get_axis_breaks($result, array('count_substances'));
        }

        return array
        (
            "data" => $result,
            'axisBreaks_interactions' => $axis_breaks,
        );
    }

    /**
     * Returns data for membranes interval adding data
     * 
     * @return array
     */
    public static function get_membranes_stats($log_scale = FALSE)
    {
        $membraneModel = new Membranes();
        $interactionModel = new Interactions();

        $membranes = $membraneModel->select_list(array('id', 'name'))->get_all();

        $result = [];

        foreach($membranes as $mem)
        {
            $count_active = $interactionModel
                ->where('id_membrane', $mem->id)
                ->count_all();

            $count_substances = $interactionModel
                ->join('substances s ON s.id = interaction.id_substance AND interaction.id_membrane = ' . $mem->id)
                ->select_list('DISTINCT s.id')
                ->count_all();

            $result[] = array
            (
                'id' => $mem->id,
                'membrane' => $mem->name,
                'count_interactions' => $count_active,
                'count_substances' => $count_substances
            );
        }

        // Sort by interactions
        usort($result, function($a, $b) {
            return $b['count_interactions'] - $a['count_interactions'];
        });

        if($log_scale)
        {
            for($i = 0; $i < count($result); $i++)
            {
                $result[$i]['count_interactions'] = $result[$i]['count_interactions'] > 0 ? $result[$i]['count_interactions'] : 1;
                $result[$i]['count_substances'] = $result[$i]['count_substances'] > 0 ? $result[$i]['count_substances'] : 1;
            }
        }

        if($log_scale)
        {
            $axis_breaks = null;
        }
        else
        {
            $axis_breaks = self::get_axis_breaks($result, array('count_interactions')) + self::get_axis_breaks($result, array('count_substances'));
        }

        return array
        (
            "data" => $result,
            'axisBreaks_interactions' => $axis_breaks,
        );
    }

    /**
     * Returns stats for passive/active interactions
     */
    private function get_passive_active_int_substance_stats()
    {
        $pubchem = new Pubchem();

        $pubchem_method = $pubchem->get_logP_method();
        $pubchem_membrane = $pubchem->get_logP_membrane();

        $total_subs_pass_interactions = $this->queryOne('
            SELECT COUNT(*) as count
            FROM 
            (
                SELECT s.id, MAX(i.id) iid, MAX(t.id) tid
                FROM substances s 
                JOIN interaction i ON i.id_substance = s.id AND i.id_membrane != ? AND i.id_method != ?
                LEFT JOIN transporters t ON t.id_substance = s.id
                GROUP BY s.id
            ) as tab
            WHERE tab.iid IS NOT NULL AND tab.tid IS NULL
        ', array($pubchem_membrane->id, $pubchem_method->id))->count;

        $total_subs_active_interactions = $this->queryOne('
            SELECT COUNT(*) as count
            FROM 
            (
                SELECT s.id, MAX(i.id) iid, MAX(t.id) tid
                FROM substances s 
                LEFT JOIN interaction i ON i.id_substance = s.id AND i.id_membrane != ? AND i.id_method != ?
                JOIN transporters t ON t.id_substance = s.id
                GROUP BY s.id
            ) as tab
            WHERE tab.iid IS NULL AND tab.tid IS NOT NULL
        ', array($pubchem_membrane->id, $pubchem_method->id))->count;

        $total_subs_active_passive_interactions = $this->queryOne('
            SELECT COUNT(*) as count
            FROM 
            (
                SELECT s.id, MAX(i.id) iid, MAX(t.id) tid
                FROM substances s 
                LEFT JOIN interaction i ON i.id_substance = s.id AND i.id_membrane != ? AND i.id_method != ?
                LEFT JOIN transporters t ON t.id_substance = s.id
                GROUP BY s.id
            ) as tab
            WHERE tab.iid IS NOT NULL AND tab.tid IS NOT NULL
        ', array($pubchem_membrane->id, $pubchem_method->id))->count;

        $data =  array
        (
            array
            (
                'name' => 'Active',
                'count' => $total_subs_active_interactions
            ),
            array
            (
                'name' => 'Passive',
                'count' => $total_subs_pass_interactions
            ),
            array
            (
                'name' => 'Both',
                'count' => $total_subs_active_passive_interactions
            ),
        );

        return array
        (
            'data' => $data,
            'axisBreaks' => self::get_axis_breaks($data, ['count'])
        );
    }

    /**
     * Returns data for interaction and substance interval adding data
     * 
     * @return array
     */
    private static function get_interaction_substances_adding_stats()
    {
        $break_limit = 10000;
        $break_step = 100;

        $interactionModel = new Interactions();
        $substanceModel = new Substances();
        $transporterModel = new Transporters();

        $start_timestamp = strtotime($interactionModel->order_by('createDateTime', 'ASC')->get_one()->createDateTime);
        $start_timestamp = strtotime($substanceModel->order_by('createDateTime', 'ASC')->get_one()->createDateTime) < $start_timestamp ? 
            strtotime($substanceModel->order_by('createDateTime', 'ASC')->get_one()->createDateTime) : $start_timestamp;
        $end_timestamp = strtotime("now");

        // Get chart months
        $months_interval = Time::get_interval_months($start_timestamp, $end_timestamp);

        if(!$months_interval)
        {
            return array();
        }

        // Get values for months
        $result = [];

        unset($months_interval[0]);

        foreach($months_interval as $month_date)
        {
            $ts = strtotime($month_date);

            $count_i = ($interactionModel
                ->where(array
                (
                    'createDateTime <=' => date('Y-m-01', strtotime('+1 month', $ts))
                ))
                ->order_by('createDateTime', 'ASC')
                ->count_all()) + 
                ($transporterModel
                ->where(array
                (
                    'create_datetime <=' => date('Y-m-01', strtotime('+1 month', $ts))
                ))
                ->order_by('create_datetime', 'ASC')
                ->count_all());

            $count_s = ($substanceModel
                ->where(array
                (
                    'createDateTime <=' => date('Y-m-01', strtotime('+1 month', $ts))
                ))
                ->order_by('createDateTime', 'ASC')
                ->count_all());

            $result[] = array
            (
                'date' => $month_date,
                'count_interactions' => $count_i,
                'count_substances' => $count_s
            );
        }

        // Get axis breaks if needed
        $axis_breaks = array();
        $max_value = 0;

        $last_count = -1;

        foreach($result as $row)
        {
            if($row['count_interactions'] > $max_value)
            {
                $max_value = $row['count_interactions'];
            }

            if($row['count_substances'] > $max_value)
            {
                $max_value = $row['count_substances'];
            }

            $diff = $row['count_interactions'] - $last_count;
            if($last_count >= 0 && ($diff >= $break_limit))
            {
                $axis_breaks[] = array
                (
                    'startValue' => $last_count + $break_step,
                    'endValue' => $row['count_interactions'] - $break_step,
                    'breakSize' => 1 * (pow(10,-intval(log10($diff)) + 1))
                );  
            }

            $last_count = $row['count_interactions'];
        }

        return array
        (
            "data" => $result,
            'axisBreaks' => $axis_breaks,
            'max_value' => intval($max_value) + 100
        );
    }

    /**
     * Returns data for identifiers stats
     * 
     * @return array
     */
    private static function get_identifiers_stats()
    {
        $substanceModel = new Substances();

        $identifiers = array
        (
            'pdb' => 'PDB',
            'pubchem' => 'Pubchem',
            'drugbank' => 'Drugbank',
            'chEMBL' => 'ChEMBL'
        );

        $result = [];

        $maxValue = 0;

        foreach($identifiers as $column => $title)
        {
            $count_substances = $substanceModel
                ->where("$column IS NOT NULL")
                ->count_all();

            $maxValue = $count_substances > $maxValue ? $count_substances : $maxValue;

            $result[] = array
            (
                'identifier' => $title,
                'count' => $count_substances
            );
        }

        // Sort by interactions
        usort($result, function($a, $b) {
            return $b['count'] - $a['count'];
        });

        $axis_breaks = self::get_axis_breaks($result, array('count'));

        // Check max Value
        $maxValue = self::get_max_value($maxValue);

        return array
        (
            "data" => $result,
            'axisBreaks' => $axis_breaks,
            'maxValue' => $maxValue
        );
    }


    /**
     * Returns interaction data
     * 
     * @return array
     */
    private static function get_interaction_substances_adding_data()
    {
        $substanceModel = new Substances();
        $file = new File();

        $inter_data = $substanceModel->queryAll('
            SELECT s.name, s.identifier, s.SMILES, s.inchikey, s.MW, s.LogP, s.pubchem, s.drugbank, s.pdb, s.chEMBL,
                mem.name as membrane, met.name as method, i.temperature, i.charge, i.comment as note, i.Position as X_min,
                i.Position_acc as X_min_acc, i.Penetration as G_pen, i.Penetration_acc as G_pen_acc, i.Water as G_wat, i.Water_acc as G_wat_acc,
                i.LogK, i.LogK_acc, i.LogPerm, i.LogPerm_acc, i.theta, i.theta_acc, i.abs_wl, i.abs_wl_acc, i.fluo_wl, i.fluo_wl_acc, i.QY, i.QY_acc, i.lt, i.lt_acc,
                p1.citation as primary_reference, p2.citation as secondary_reference
            FROM substances s 
            JOIN interaction i ON i.id_substance = s.id
            JOIN membranes mem ON mem.id = i.id_membrane
            JOIN methods met ON met.id = i.id_method
            JOIN datasets d ON d.id = i.id_dataset
            LEFT JOIN publications p1 ON p1.id = i.id_reference
            LEFT JOIN publications p2 ON p2.id = d.id_publication
        ', array(), FALSE);

        $trans_data = $substanceModel->queryAll('
            SELECT s.name, s.identifier, s.SMILES, s.inchikey, s.MW, s.LogP, s.pubchem, s.drugbank, s.pdb, s.chEMBL,
                tt.name as target, tt.uniprot_id as uniprot, t.type, t.note, t.Km, t.Km_acc, t.EC50, t.EC50_acc, t.Ki, t.Ki_acc,
                t.IC50, t.IC50_acc, p1.citation as primary_reference, p2.citation as secondary_reference
            FROM substances s 
            JOIN transporters t ON t.id_substance = s.id
            JOIN transporter_targets tt ON tt.id = t.id_target
            JOIN transporter_datasets td ON td.id = t.id_dataset
            LEFT JOIN publications p1 ON p1.id = t.id_reference
            LEFT JOIN publications p2 ON p2.id = td.id_reference
        ',array(), FALSE);

        $substances = $substanceModel
            ->select_list('identifier, name, SMILES, inchikey, LogP, MW, pubchem, pdb, chEMBL, drugbank')
            ->get_all()
            ->as_array();

        $paths = array
        (
            $file->transform_to_CSV($trans_data, 'transporters.csv'),
            $file->transform_to_CSV($inter_data, 'passive_interactions.csv'),
            $file->transform_to_CSV($substances, 'substances.csv')
        );
        
        $file->zip($paths, self::TYPE_INTER_ADD_FILE, TRUE);

        return self::TYPE_INTER_ADD_FILE;
    }

    /**
     * Returns...
     * 
     * @return array
     */
    private function get_passive_active_int_substance_data()
    {
        $pubchem = new Pubchem();
        $file = new File();

        $pubchem_method = $pubchem->get_logP_method();
        $pubchem_membrane = $pubchem->get_logP_membrane();

        $pass_interactions = $this->queryAll('
            SELECT name, identifier, SMILES, inchikey, MW, LogP, pubchem, drugbank, pdb, chEMBL,
                membrane, method, temperature, charge, note,  X_min, X_min_acc,  G_pen,  G_pen_acc,  G_wat, G_wat_acc,
                LogK, LogK_acc, LogPerm, LogPerm_acc, theta, theta_acc, abs_wl, abs_wl_acc, fluo_wl, fluo_wl_acc, QY, QY_acc, lt, lt_acc, primary_reference, secondary_reference
            FROM 
            (
                SELECT s.*, mem.name as membrane, met.name as method, i.temperature, i.charge, i.comment as note, i.Position as X_min,
                    i.Position_acc as X_min_acc, i.Penetration as G_pen, i.Penetration_acc as G_pen_acc, i.Water as G_wat, i.Water_acc as G_wat_acc,
                    i.LogK, i.LogK_acc, i.LogPerm, i.LogPerm_acc, i.theta, i.theta_acc, i.abs_wl, i.abs_wl_acc, i.fluo_wl, i.fluo_wl_acc, i.QY, i.QY_acc, i.lt, i.lt_acc,
                    i.id as iid, p1.citation as primary_reference, p2.citation as secondary_reference
                FROM substances s 
                JOIN interaction i ON i.id_substance = s.id AND i.id_membrane != ? AND i.id_method != ?
                JOIN membranes mem ON mem.id = i.id_membrane
                JOIN methods met ON met.id = i.id_method
                JOIN datasets d ON d.id = i.id_dataset
                LEFT JOIN publications p1 ON p1.id = i.id_reference
                LEFT JOIN publications p2 ON p2.id = d.id_publication
            ) as tab
        ', array($pubchem_membrane->id, $pubchem_method->id))->as_array();

        $active_interactions = $this->queryAll('
            SELECT s.name, s.identifier, s.SMILES, s.inchikey, s.MW, s.LogP, s.pubchem, s.drugbank, s.pdb, s.chEMBL,
                tt.name as target, tt.uniprot_id as uniprot, t.type, t.note, t.Km, t.Km_acc, t.EC50, t.EC50_acc, t.Ki, t.Ki_acc,
                t.IC50, t.IC50_acc, p1.citation as primary_reference, p2.citation as secondary_reference
            FROM substances s 
            JOIN transporters t ON t.id_substance = s.id
            JOIN transporter_targets tt ON tt.id = t.id_target
            JOIN transporter_datasets td ON td.id = t.id_dataset
            LEFT JOIN publications p1 ON p1.id = t.id_reference
            LEFT JOIN publications p2 ON p2.id = td.id_reference
        ')->as_array();

        $paths = array
        (
            $file->transform_to_CSV($active_interactions, 'active.csv'),
            $file->transform_to_CSV($pass_interactions, 'passive.csv')
        );

        $file->zip($paths, self::TYPE_INTER_PASSIVE_ACITVE_FILE, TRUE);

        return self::TYPE_INTER_PASSIVE_ACITVE_FILE;
    }

    /**
     * Returns...
     * 
     * @return array
     */
    private static function get_active_interactions_stats()
    {
        $et = new Enum_types();
        return $et->get_categories(Enum_types::TYPE_TRANSPORTER_CATS);
    }


    /**
     * Returns idenfiers data
     * 
     * @return array
     */
    private static function get_identifiers_data()
    {
        $substanceModel = new Substances();
        $file = new File();

        $data = $substanceModel->where(array
            (
                'pubchem IS NOT NULL',
                'OR',
                'drugbank IS NOT NULL',
                'OR',
                'chEBI IS NOT NULL',
                'OR',
                'pdb IS NOT NULL',
            ))
            ->select_list('identifier, name, SMILES, inchikey, MW, LogP, pubchem, drugbank, chEBI as chebi, pdb')
            ->get_all()->as_array();

        $path = $file->transform_to_CSV($data, 'identifiers.csv');

        $file->zip($path, self::TYPE_IDENTIFIERS_FILE, TRUE);

        return self::TYPE_IDENTIFIERS_FILE;
    }

    
    /**
     * Returns max value
     * 
     * @param int $value
     */
    private static function get_max_value($value)
    {
        // Add margin to max value
        $l = intval(log10($value));
        $l -= 2;

        if($l)
        {
            $value += pow(10, $l); 
        }
        else
        {
            $value++;
        }

        return $value;
    }

    
    /**
     * Returns axis breaks for given set
     * 
     * @param array $data
     * @param array $attributes
     * @param int $break_limit
     * 
     * @return array
     */
    private static function get_axis_breaks($data, $attributes, $break_limit = 10000, $break_step = 0.05)
    {
        $break_step = $break_step * $break_limit;

        $values = [];
        // First, get all values
        foreach($data as $row)
        {
            foreach($attributes as $a)
            {
                $values[] = $row[$a];
            }
        }

        $values = array_unique($values);
        asort($values);

        // Get axis breaks
        $axis_breaks = array();

        $last_count = 0;

        foreach($values as $val)
        {
            $diff = $val - $last_count;

            if($diff >= $break_limit)
            {
                // echo $val . ' / ' . $last_count . ' / ' . $diff . "\n";

                $axis_breaks[] = array
                (
                    'startValue' => $last_count + $break_step,
                    'endValue' => $val - $break_step,
                    'breakSize' => 1 * (pow(10,-intval(log10($diff)) + 1))
                );  
            }
            $last_count = $val;
        }

        return $axis_breaks;
    }
}
<?php

/**
 * Stats model
 * Stores stats data for loading speed improvement
 * 
 * @param int $id
 * @param int $type
 * @param string $content
 * @param string $update_date
 * 
 * @author Jakub Juracka
 */
class Statistics extends Db
{
    /** STATS TYPE - INTERACTION ADDING STATS */
    const TYPE_INTER_ADD = 1;

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

    /** STATS TYPE - INTERACTIONS ACTIVE/PASSIVE */
    const TYPE_INTER_PASSIVE_ACITVE = 9;
    
    /**
     * Holds info about valid types
     */
    private static $valid_types = array
    (
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
        self::TYPE_INTER_PASSIVE_ACITVE
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
                break;

            case self::TYPE_INTER_ADD:
                $obj->content =self::get_interaction_substances_adding_stats();
                break;

            case self::TYPE_INTER_PASSIVE_ACITVE:
                $obj->content =$this->get_passive_active_int_substance_stats();
                break;

            case self:: TYPE_MEMBRANES_DATA:
                $obj->content =self::get_membranes_stats(TRUE);
                break;

            case self:: TYPE_METHODS_DATA:
                $obj->content =self::get_methods_stats(TRUE);
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
        $total_subs_active_interactions = $this->queryOne('
            SELECT COUNT(*) as count
            FROM 
            (
                SELECT s.id, MAX(i.id) iid, MAX(t.id) tid
                FROM substances s 
                LEFT JOIN interaction i ON i.id_substance = s.id
                LEFT JOIN transporters t ON t.id_substance = s.id
                GROUP BY s.id
            ) as tab
            WHERE tab.iid IS NOT NULL AND tab.tid IS NULL
        ')->count;

        $total_subs_pass_interactions = $this->queryOne('
            SELECT COUNT(*) as count
            FROM 
            (
                SELECT s.id, MAX(i.id) iid, MAX(t.id) tid
                FROM substances s 
                LEFT JOIN interaction i ON i.id_substance = s.id
                LEFT JOIN transporters t ON t.id_substance = s.id
                GROUP BY s.id
            ) as tab
            WHERE tab.iid IS NULL AND tab.tid IS NOT NULL
        ')->count;

        $total_subs_active_passive_interactions = $this->queryOne('
            SELECT COUNT(*) as count
            FROM 
            (
                SELECT s.id, MAX(i.id) iid, MAX(t.id) tid
                FROM substances s 
                LEFT JOIN interaction i ON i.id_substance = s.id
                LEFT JOIN transporters t ON t.id_substance = s.id
                GROUP BY s.id
            ) as tab
            WHERE tab.iid IS NOT NULL AND tab.tid IS NOT NULL
        ')->count;

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

        $last_count = -1;

        foreach($result as $row)
        {
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
            'axisBreaks' => $axis_breaks
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
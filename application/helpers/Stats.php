<?php

/**
 * Library for handling with statisticall data
 * 
 * @author Jakub Juracka
 */
class Stats extends Db
{
    private $substanceModel;
    private $interactionModel;
    private $transporterModel;
    private $membraneModel;
    private $methodModel;
    private $publicationModel;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Init models
        $this->substanceModel = new Substances();
        $this->interactionModel = new Interactions();
        $this->transporterModel = new Transporters();
        $this->membraneModel = new Membranes();
        $this->methodModel = new Methods();
        $this->publicationModel = new Publications();
    }

    /**
     * Returns total interactions (active + passive)
     * 
     * @return int
     */
    public function get_total_interactions()
    {
        return $this->interactionModel
            ->count_all() + 
            $this->transporterModel
            ->count_all();
    }

    /**
     * Returns total substances
     * 
     * @return int
     */
    public function get_total_substances()
    {
        return $this->substanceModel->count_all();
    }

    /**
     * Returns total membranes
     * 
     * @return int
     */
    public function get_total_membranes()
    {
        return $this->membraneModel->count_all();
    }

    /**
     * Returns total methods
     * 
     * @return int
     */
    public function get_total_methods()
    {
        return $this->methodModel->count_all();
    }

    /**
     * Returns data for interaction interval adding data
     * 
     * returns 2 types of results - passive and active interactions
     * 
     * @return array
     */
    public function get_all_interactions_adding_stats()
    {
        $start_timestamp = strtotime($this->queryOne('
            SELECT MIN(createDateTime) as createDateTime
            FROM
                ((SELECT createDateTime 
                FROM `interaction` 
                ORDER BY createDateTime ASC
                LIMIT 1)
            
                UNION 
                
                (SELECT create_datetime as createDateTime
                FROM `transporters`
                ORDER BY create_datetime ASC
                LIMIT 1)
            ) as tab 
        ')->createDateTime);

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

            $count_passive = ($this->interactionModel
                ->where(array
                (
                    'createDateTime <=' => date('Y-m-01', strtotime('+1 month', $ts))
                ))
                ->order_by('createDateTime', 'ASC')
                ->count_all());

            $count_active = ($this->transporterModel
                ->where(array
                (
                    'create_datetime <=' => date('Y-m-01', strtotime('+1 month', $ts))
                ))
                ->order_by('create_datetime', 'ASC')
                ->count_all());

            $result[] = array
            (
                'date' => $month_date,
                'count_passive' => $count_passive,
                'count_active' => $count_active
            );
        }

        $axis_breaks = $this->get_axis_breaks($result, array('count_passive', 'count_active'));

        return array
        (
            "data" => $result,
            'axisBreaks' => $axis_breaks
        );
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
    private function get_axis_breaks($data, $attributes, $break_limit = 10000, $break_step = 0.05)
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

    /**
     * Returns data for passive interactions
     * 
     * @return array
     */
    public function get_passive_interactions_adding_stats()
    {
        $break_limit = 10000;
        $break_step = 100;

        $start_timestamp = strtotime($this->interactionModel->order_by('createDateTime', 'ASC')->get_one()->createDateTime);
        $end_timestamp = strtotime("now");

        // Get chart months
        $months_interval = Time::get_interval_months($start_timestamp, $end_timestamp);

        if(!$months_interval)
        {
            return array();
        }

        // Get values for months
        $result = [];

        $count = 0;

        unset($months_interval[0]);

        foreach($months_interval as $month_date)
        {
            $ts = strtotime($month_date);

            $count = ($this->interactionModel
                ->where(array
                (
                    'createDateTime <=' => date('Y-m-01', strtotime('+1 month', $ts))
                ))
                ->order_by('createDateTime', 'ASC')
                ->count_all());

            $result[] = array
            (
                'date' => $month_date,
                'count' => $count
            );
        }

        // Get axis breaks if needed
        $axis_breaks = array();

        $last_count = -1;

        foreach($result as $row)
        {
            $diff = $row['count'] - $last_count;
            if($last_count >= 0 && ($diff >= $break_limit))
            {
                $axis_breaks[] = array
                (
                    'startValue' => $last_count + $break_step,
                    'endValue' => $row['count'] - $break_step,
                    'breakSize' => 1 * (pow(10,-intval(log10($diff)) + 1))
                );  
            }

            $last_count = $row['count'];
        }

        return array
        (
            "data" => $result,
            'axisBreaks' => $axis_breaks
        );
    }

    /**
     * Returns data for interaction and substance interval adding data
     * 
     * @return array
     */
    public function get_interaction_substances_adding_stats()
    {
        $break_limit = 10000;
        $break_step = 100;

        $start_timestamp = strtotime($this->interactionModel->order_by('createDateTime', 'ASC')->get_one()->createDateTime);
        $start_timestamp = strtotime($this->substanceModel->order_by('createDateTime', 'ASC')->get_one()->createDateTime) < $start_timestamp ? 
            strtotime($this->substanceModel->order_by('createDateTime', 'ASC')->get_one()->createDateTime) : $start_timestamp;
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

            $count_i = ($this->interactionModel
                ->where(array
                (
                    'createDateTime <=' => date('Y-m-01', strtotime('+1 month', $ts))
                ))
                ->order_by('createDateTime', 'ASC')
                ->count_all()) + 
                ($this->transporterModel
                ->where(array
                (
                    'create_datetime <=' => date('Y-m-01', strtotime('+1 month', $ts))
                ))
                ->order_by('create_datetime', 'ASC')
                ->count_all());

            $count_s = ($this->substanceModel
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
     * Returns data for interaction interval by reference
     * 
     * @return array
     */
    public function get_interaction_stats_by_reference($limit = 20)
    {
        $break_limit = 2000;
        $break_step = 10;

        $result = [];

        $result['primary_reference'] = $this->publicationModel
            ->join('JOIN interaction i ON publications.id = i.id_reference')
            // ->select_list('publications.citation, COUNT(*) as count')
            ->select_list('publications.id as citation, COUNT(*) as count')
            ->group_by('publications.id')
            ->order_by('count', 'DESC')
            ->limit($limit)
            ->get_all()
            ->as_array();

        $result['secondary_reference'] = $this->publicationModel
            ->select_list('publications.id, COUNT(*) as count')
            ->join(array
            (
                'datasets d ON publications.id = d.id_publication',
                'interaction i ON i.id_dataset = d.id'
            ))
            ->where('publications.id > 0')
            ->group_by('id')
            ->order_by('count', 'DESC')
            ->limit($limit)
            ->get_all()
            ->as_array();
        
        // Get axis breaks if needed
        $axis_breaks = array
        (
            'primary_reference' => [],
            'secondary_reference' => []
        );

        $last_count = -1;

        foreach($result['primary_reference'] as $row)
        {
            $diff = $last_count - $row['count'];
            if($last_count >= 0 && ($diff >= $break_limit))
            {
                $axis_breaks['primary_reference'][] = array
                (
                    'endValue' => $last_count + $break_step,
                    'startValue' => $row['count'] - $break_step,
                    'breakSize' => 1 * (pow(10,-intval(log10($diff)) + 1))
                );  
            }

            $last_count = $row['count'];
        }

        $last_count = -1;

        foreach($result['secondary_reference'] as $row)
        {
            $diff = $last_count - $row['count'];
            if($last_count >= 0 && ($diff >= $break_limit))
            {
                $axis_breaks['secondary_reference'][] = array
                (
                    'endValue' => $last_count + $break_step,
                    'startValue' => $row['count'] - $break_step,
                    'breakSize' => 1 * (pow(10,-intval(log10($diff)) + 1))
                );  
            }

            $last_count = $row['count'];
        }

        return array
        (
            "data" => $result,
            'axisBreaks' => $axis_breaks
        );
    }

    /**
     * Returns stats for passive/active interactions
     */
    public function get_passive_active_int_substance_stats()
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
            'axisBreaks' => $this->get_axis_breaks($data, ['count'])
        );
    }

    /**
     * Returns data for substnaces interval adding data
     * 
     * @return array
     */
    public function get_substances_adding_stats()
    {
        $break_limit = 10000;
        $break_step = 100;

        $start_timestamp = strtotime($this->substanceModel->order_by('createDateTime', 'ASC')->get_one()->createDateTime);
        $end_timestamp = strtotime("now");

        // Get chart months
        $months_interval = Time::get_interval_months($start_timestamp, $end_timestamp);

        if(!$months_interval)
        {
            return array();
        }

        // Get values for months
        $result = [];

        $count = 0;

        unset($months_interval[0]);

        foreach($months_interval as $month_date)
        {
            $ts = strtotime($month_date);

            $count = ($this->substanceModel
                ->where(array
                (
                    'createDateTime <=' => date('Y-m-01', strtotime('+1 month', $ts))
                ))
                ->order_by('createDateTime', 'ASC')
                ->count_all());

            $result[] = array
            (
                'date' => $month_date,
                'count' => $count
            );
        }

        // Get axis breaks if needed
        $axis_breaks = array();

        $last_count = -1;

        foreach($result as $row)
        {
            $diff = $row['count'] - $last_count;
            if($last_count >= 0 && ($diff >= $break_limit))
            {
                $axis_breaks[] = array
                (
                    'startValue' => $last_count + $break_step,
                    'endValue' => $row['count'] - $break_step,
                    'breakSize' => 1 * (pow(10,-intval(log10($diff)) + 1))
                );  
            }

            $last_count = $row['count'];
        }

        return array
        (
            "data" => $result,
            'axisBreaks' => $axis_breaks
        );
    }

    /**
     * Returns data for membranes interval adding data
     * 
     * @return array
     */
    public function get_membranes_stats($log_scale = FALSE)
    {
        $membranes = $this->membraneModel->select_list(array('id', 'name'))->get_all();

        $result = [];

        foreach($membranes as $mem)
        {
            $count_active = $this->interactionModel
                ->where('id_membrane', $mem->id)
                ->count_all();

            $count_substances = $this->interactionModel
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
            $axis_breaks = $this->get_axis_breaks($result, array('count_interactions')) + $this->get_axis_breaks($result, array('count_substances'));
        }

        return array
        (
            "data" => $result,
            'axisBreaks_interactions' => $axis_breaks,
        );
    }

    /**
     * Returns data for methods stats
     * 
     * @return array
     */
    public function get_methods_stats($log_scale = FALSE)
    {
        $methods = $this->methodModel->select_list(array('id', 'name'))->get_all();

        $result = [];

        $maxValue = 0;

        foreach($methods as $m)
        {
            $count_interactions = $this->interactionModel
                ->where('id_method', $m->id)
                ->count_all();

            $count_substances = $this->interactionModel
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
            $axis_breaks = $this->get_axis_breaks($result, array('count_interactions')) + $this->get_axis_breaks($result, array('count_substances'));
        }

        return array
        (
            "data" => $result,
            'axisBreaks_interactions' => $axis_breaks,
        );
    }

    /**
     * Returns data for identifiers stats
     * 
     * @return array
     */
    public function get_identifiers_stats()
    {
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
            $count_substances = $this->substanceModel
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

        $axis_breaks = $this->get_axis_breaks($result, array('count'));

        // Check max Value
        $maxValue = self::get_max_value($maxValue);

        return array
        (
            "data" => $result,
            'axisBreaks' => $axis_breaks,
            'maxValue' => $maxValue
            // 'axisBreaks_interactions' => $this->get_axis_breaks($result, array('count_interactions', 'count_substances'), 500, 0.5),
            // 'axisBreaks_substances' => $this->get_axis_breaks($result, array('count_substances'))
        );
    }

    /**
     * Get max value
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
}
<?php

use Egulias\EmailValidator\Result\Reason\CharNotAllowed;

/**
 * Holds info about substance pairs
 * 
 * @property int $id
 * @property int $type
 * @property string $adjustment
 * @property int $total_pairs
 * @property string $datetime
 * @property string $update_datetime
 * 
 * @author Jakub Juracka
 */
class Substance_pair_groups extends Db
{
    /**
     * Which attributes are interesting to analyze?
     */
    public static $attr_of_interest = array
    (
        'passive' => array
        (
            'Position' => 'X_min',
            'Penetration' => 'G_pen',
            'Water'     => 'G_wat',
            'LogK'      => "LogK",
            'LogPerm'   => 'LogPerm'
        ),
        'active' => array
        (
            "Km",
            "EC50",
            "IC50",
            "Ki"   
        )
    );

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'substance_pair_groups';
        parent::__construct($id);
    }

    /**
     * Returns detail of adjustment for given group
     * 
     * @return array
     */
    public function get_adjustment_detail()
    {
        if(!$this->id)
        {
            return null;
        }

        $adj = explode('/', $this->adjustment);
        $left = explode(',',$adj[0]);
        $right = isset($adj[1]) ? explode(',',$adj[1]) : null;

        if(is_array($right) && count($left) && count($right) && count($left) !== count($right))
        {
            return null;
        }

        $result = [];

        foreach($left as $k => $id)
        {
            $f1 = new Fragments($id);
            $f1g = $f1->get_functional_group();

            if(!$f1->id) // Invalid fragment
            {
                return null;
            }

            if(is_array($right))
            {
                $f2 = new Fragments($right[$k]);
                if(!$f2->id)
                {
                    return null;
                }
                $f2g = $f2->get_functional_group();

                $result[] = [array
                (
                    'id' => $f1->id,
                    'group' => $f1g ? $f1g->name : null,
                    'img'   => Html::image_structure($f1->add_hydrogens())
                ), array
                (
                    'id' => $f2->id,
                    'group' => $f2g ? $f2g->name : null,
                    'img'   => Html::image_structure($f2->add_hydrogens())
                )];
            }
            else
            {
                $result[] = [null,array
                (
                    'id' => $f1->id,
                    'group' => $f1g ? $f1g->name : null,
                    'img'   => Html::image_structure($f1->add_hydrogens())
                )];
            }
        }

        return $result;
    }

    /**
     * Updates all groups in db
     * 
     */
    public function update_all()
    {
        $data = $this->queryAll('
            SELECT GROUP_CONCAT(id_substance_pair) as ids, CONCAT(f2,"/",f1) as func_groups, f2, f1, COUNT(CONCAT(f2,"/",f1)) as total
            FROM (
                SELECT id_substance_pair, 
                    TRIM("," FROM REPLACE(GROUP_CONCAT(IF(sf2.id_fragment IS NULL, "", sf2.id_fragment) ORDER BY sf2.id_fragment ASC), ",,", ",")) as f2,
                    TRIM("," FROM REPLACE(GROUP_CONCAT(IF(sf1.id_fragment IS NULL, "", sf1.id_fragment) ORDER BY sf2.id_fragment ASC), ",,", ",")) as f1 
                FROM substance_fragmentation_pair_links as l
                LEFT JOIN substances_fragments sf1 ON sf1.id = l.id_substance_1_fragment
                LEFT JOIN substances_fragments sf2 ON sf2.id = l.id_substance_2_fragment
                GROUP BY id_substance_pair
            ) as t
            GROUP BY CONCAT(f2,"/",f1)
            ORDER BY total DESC
        ');

        function rem_empty($var)
        {
            return !empty(trim($var));
        }

        $processed = [];

        foreach($data as $row)
        {
            $id_fragments_1 = array_values(array_filter(explode(',', $row->f1 ?? ""), 'rem_empty'));
            $id_fragments_2 = array_values(array_filter(explode(',', $row->f2 ?? ""), 'rem_empty'));
            $total = $row->total;

            $pair_ids = explode(',', $row->ids);

            if($total < 10)
            {
                continue;
            }

            if(count($id_fragments_1) && count($id_fragments_2) && 
                count($id_fragments_1) !== count($id_fragments_2))
            {
                continue;   // Invalid substitution
            }

            $type = Substance_pairs_links::TYPE_ADD;

            if(count($id_fragments_1))
            {
                $type = Substance_pairs_links::TYPE_SUBS;
            }

            // Check, if data are valid func. groups
            foreach($id_fragments_2 as $k => $id)
            {
                $f = new Fragments($id);
                $f2 = new Fragments(!empty($id_fragments_1) ? $id_fragments_1[$k] : null);

                if($f->get_functional_group() === null || 
                    ($f2->id && $f2->get_functional_group() === null))
                {
                    continue 2;
                }
            }

            try
            {
                Db::beginTransaction();
            
                $record = new Substance_pair_groups();

                $record->type = $type;
                $record->total_pairs = $total;
                $record->adjustment = $type == Substance_pairs_links::TYPE_ADD ?
                    implode(',', $id_fragments_2) :
                    implode(',', $id_fragments_1) . '/' . implode(',', $id_fragments_2);

                $record->save();

                // Update substance pairs
                Db::instance()->query('
                    UPDATE `substance_fragmentation_pairs`
                    SET id_group = ? 
                    WHERE id IN ("' . implode('","', $pair_ids) .  '")
                ', array($record->id));

                Db::commitTransaction();

                $processed[] = $record->id;
            }
            catch(Exception $e)
            {
                Db::rollbackTransaction();
                throw $e;
            }
        }

        // Delete old records
        $this->query('
            DELETE FROM substance_pair_groups WHERE id NOT IN ("' . implode('","', $processed) . '");
        ');
    }

    /**
     * Updates group stats
     * 
     * @param int|null $id_group
     * 
     */
    public function update_group_stats($id_group = null)
    {
        if(!$id_group)
        {
            if($this->id)
            {
                $group = $this;
            }
            else
            {
                $group = $this->order_by('datetime ASC, id ', 'ASC')->get_one();
            }
        }
        else
        {
            $group = new Substance_pair_groups($id_group); 
        }

        if(!$group->id)
        {
            return;
        }

        // Passive
        $ints = $this->queryAll('
            SELECT DISTINCT i1.id_membrane as id_membrane, i1.id_method as id_method, i1.charge as charge
            FROM (
                SELECT *
                FROM substance_fragmentation_pairs
                WHERE id_group = ?
            ) as p
            JOIN interaction i1 ON i1.id_substance = p.id_substance_1
            JOIN interaction i2 ON i2.id_substance = p.id_substance_2 AND i1.id_membrane = i2.id_membrane AND i1.id_method = i2.id_method && i1.charge = i2.charge
        ', [$group->id]);

        foreach($ints as $i)
        {
            $record = new Substance_pair_group_types();

            $record->id_group = $group->id;
            $record->id_membrane = $i->id_membrane;
            $record->id_method = $i->id_method;
            $record->charge = $i->charge;

            $record->save();

            $attrs = array_keys(self::$attr_of_interest['passive']);

            foreach($attrs as $key => $val)
            {
                $attrs[$key] = "ROUND((i2.$val - i1.$val), 2) as $val";
            }

            // Update stats
            $is = $this->queryAll('
                SELECT i1.id_substance as id_substance_1, i2.id_substance as id_substance_2, ' . implode(', ', $attrs) . '
                FROM (
                    SELECT *
                    FROM substance_fragmentation_pairs
                    WHERE id_group = ?
                ) as p
                JOIN interaction i1 ON i1.id_substance = p.id_substance_1
                JOIN interaction i2 ON i2.id_substance = p.id_substance_2 AND i2.id_membrane = i1.id_membrane AND i2.id_method = i1.id_method AND i2.charge = i1.charge
                WHERE i1.id_membrane = ? AND i1.id_method = ? AND i1.charge '  . ($record->charge === null ? 'IS NULL ' : ' LIKE "' . $record->charge . '"') . '
            ', array($group->id, $record->id_membrane, $record->id_method));

            $join = self::$attr_of_interest['passive'];
            $sizes = self::$attr_of_interest['passive'];

            foreach($is as $i)
            {
                $sf2 = Substances_fragments::instance()->where(array
                    (
                        'id_substance' => $i->id_substance_2,
                        'order_number' => 1    
                    ))
                    ->get_one();

                if(!$sf2->id || !$sf2->fragment)
                {
                    continue;
                }

                $id_key = $i->id_substance_1 . '_'  . $i->id_substance_2;

                foreach($join as $key => $v)
                {
                    if(!is_array($join[$key]))
                    {
                        $join[$key] = []; 
                        $sizes[$key] = []; 
                    }

                    if(is_numeric($i->$key))
                    {
                        $join[$key][$id_key] = $i->$key;
                        $sizes[$key][$id_key] = $sf2->fragment->get_atom_count();
                    }
                }
            }   

            // Clear memory
            $is = $i = null;

            $stats = [];

            foreach($join as $att => $data)
            {
                $total = arr::total_numeric($data);

                if($total < 4)
                {
                    $stats[$att] = ['total' => $total, 'data' => $data];
                    continue;
                }

                $bins_keys_sd = arr::to_bins_keys($data, $total > 12 ? 12 : 8, arr::METHOD_SD, ["+/- 0.05"]);
                $bins_keys_eq = arr::to_bins_keys($data, $total > 12 ? 12 : 8, arr::METHOD_EQ_DIST, ["+/- 0.05"]);

                $t = $bins_keys_sd;
                foreach($t as $interval => $values)
                {
                    foreach($values as $kk => $v)
                    {
                        $bins_keys_sd[$interval][$kk] = $sizes[$att][$v];
                    }

                    // Add description stats
                    $d =  $bins_keys_sd[$interval];

                    if(!empty($d) && count($d) > 4)
                    {
                        $bins_keys_sd[$interval] = array
                        (
                            'total' => arr::total_numeric($d),
                            'avg'   => arr::average($d),
                            'min'   => min($d),
                            'max'   => max($d),
                            'q1'    => arr::k_quartile($d, 1),
                            'q2'    => arr::k_quartile($d, 2),
                            'q3'    => arr::k_quartile($d, 3),
                            'iqr'   => arr::iqr($d)
                        );
                    }
                    else
                    {
                        $bins_keys_sd[$interval] = ['total' => arr::total_numeric($d), 'values' => $d];
                    }
                }

                $t = $bins_keys_eq;
                foreach($t as $interval => $values)
                {
                    foreach($values as $kk => $v)
                    {
                        $bins_keys_eq[$interval][$kk] = $sizes[$att][$v];
                    }

                    // Add description stats
                    $d =  $bins_keys_eq[$interval];

                    if(!empty($d) && count($d) > 4)
                    {
                        $bins_keys_eq[$interval] = array
                        (
                            'total' => arr::total_numeric($d),
                            'avg'   => arr::average($d),
                            'min'   => min($d),
                            'max'   => max($d),
                            'q1'    => arr::k_quartile($d, 1),
                            'q2'    => arr::k_quartile($d, 2),
                            'q3'    => arr::k_quartile($d, 3),
                            'iqr'   => arr::iqr($d)
                        );
                    }
                    else
                    {
                        $bins_keys_eq[$interval] = ['total' => arr::total_numeric($d), 'values' => $d];
                    }
                }

                $stats[$att] = array
                (
                    'average' => arr::average($data),
                    'sd'      => arr::sd($data),
                    'skewness'=> arr::skewness($data),
                    'total'   => $total,
                    'bins_sd' => arr::total_items(arr::to_bins($data, $total > 12 ? 12 : 8, arr::METHOD_SD, ["+/- 0.05"]), true) ?? $data,
                    'bins_eq' => arr::total_items(arr::to_bins($data, $total > 12 ? 12 : 8, arr::METHOD_EQ_DIST,["+/- 0.05"]), true),
                    'bins_atom_counts_sd_stats' => $bins_keys_sd,
                    'bins_atom_counts_eq_stats' => $bins_keys_eq
                );
            }

            $record->stats = json_encode($stats);
            $record->save();

            $stats = $join = $record = null;
        }

        // Active
        $ints = $this->queryAll('
            SELECT DISTINCT i1.id_target as id_target
            FROM (
                SELECT *
                FROM substance_fragmentation_pairs
                WHERE id_group = ?
            ) as p
            JOIN transporters i1 ON i1.id_substance = p.id_substance_1
            JOIN transporters i2 ON i2.id_substance = p.id_substance_2 AND i1.id_target = i2.id_target 
        ', [$group->id]);

        foreach($ints as $i)
        {
            $record = new Substance_pair_group_types();

            $record->id_group = $group->id;
            $record->id_target = $i->id_target;

            $record->save();

            $attrs = self::$attr_of_interest['active'];

            foreach($attrs as $key => $val)
            {
                $attrs[$key] = "ROUND((i2.$val - i1.$val), 2) as $val";
            }

            // Update stats
            $is = $this->queryAll('
                SELECT ' . implode(', ', $attrs) . '
                FROM (
                    SELECT *
                    FROM substance_fragmentation_pairs
                    WHERE id_group = ?
                ) as p
                JOIN transporters i1 ON i1.id_substance = p.id_substance_1
                JOIN transporters i2 ON i2.id_substance = p.id_substance_2 AND i1.id_target = i2.id_target 
                WHERE i1.id_target = ?
            ', array($group->id, $record->id_target));

            $t = self::$attr_of_interest['active'];
            $join = [];

            foreach($is as $i)
            {
                foreach($t as $key)
                {
                    if(!isset($join[$key]))
                    {
                        $join[$key] = []; 
                    }

                    if(is_numeric($i->$key))
                        $join[$key][] = $i->$key;
                }
            }   

            $is = null;

            $stats = [];

            foreach($join as $att => $data)
            {
                $total = arr::total_numeric($data);

                if($total < 4)
                {
                    $stats[$att] = ['total' => $total, 'data' => $data];
                    continue;
                }

                $bins_keys_sd = arr::to_bins_keys($data, $total > 12 ? 12 : 8, arr::METHOD_SD, ["+/- 0.05"]);
                $bins_keys_eq = arr::to_bins_keys($data, $total > 12 ? 12 : 8, arr::METHOD_EQ_DIST, ["+/- 0.05"]);

                $t = $bins_keys_sd;
                foreach($t as $interval => $values)
                {
                    foreach($values as $kk => $v)
                    {
                        $bins_keys_sd[$interval][$kk] = $sizes[$att][$v];
                    }

                    // Add description stats
                    $d =  $bins_keys_sd[$interval];

                    if(!empty($d) && count($d) > 4)
                    {
                        $bins_keys_sd[$interval] = array
                        (
                            'total' => arr::total_numeric($d),
                            'avg'   => arr::average($d),
                            'min'   => min($d),
                            'max'   => max($d),
                            'q1'    => arr::k_quartile($d, 1),
                            'q2'    => arr::k_quartile($d, 2),
                            'q3'    => arr::k_quartile($d, 3),
                            'iqr'   => arr::iqr($d)
                        );
                    }
                    else
                    {
                        $bins_keys_sd[$interval] = ['total' => arr::total_numeric($d), 'values' => $d];
                    }
                }

                $t = $bins_keys_eq;
                foreach($t as $interval => $values)
                {
                    foreach($values as $kk => $v)
                    {
                        $bins_keys_eq[$interval][$kk] = $sizes[$att][$v];
                    }

                    // Add description stats
                    $d =  $bins_keys_eq[$interval];

                    if(!empty($d) && count($d) > 4)
                    {
                        $bins_keys_eq[$interval] = array
                        (
                            'total' => arr::total_numeric($d),
                            'avg'   => arr::average($d),
                            'min'   => min($d),
                            'max'   => max($d),
                            'q1'    => arr::k_quartile($d, 1),
                            'q2'    => arr::k_quartile($d, 2),
                            'q3'    => arr::k_quartile($d, 3),
                            'iqr'   => arr::iqr($d)
                        );
                    }
                    else
                    {
                        $bins_keys_eq[$interval] = ['total' => arr::total_numeric($d), 'values' => $d];
                    }
                }

                $stats[$att] = array
                (
                    'average' => arr::average($data),
                    'sd'      => arr::sd($data),
                    'skewness'=> arr::skewness($data),
                    'total'   => $total,
                    'bins_sd' => arr::total_items(arr::to_bins($data, $total > 12 ? 12 : 8, arr::METHOD_SD, ["+/- 0.05"]), true) ?? $data,
                    'bins_eq' => arr::total_items(arr::to_bins($data, $total > 12 ? 12 : 8, arr::METHOD_EQ_DIST,["+/- 0.05"]), true),
                    'bins_atom_counts_sd_stats' => $bins_keys_sd,
                    'bins_atom_counts_eq_stats' => $bins_keys_eq
                );
            }

            $record->stats = json_encode($stats);
            $record->save();

            $stats = $join = null;
        }

        $group->datetime = date('Y-m-d H:i:s');
        $group->save();
    }

    /**
     * Redefine save function
     * 
     */
    function save()
    {
        if(!$this->id)
        {
            // Check duplicities
            $exists = $this->where('adjustment', $this->adjustment)->get_one();

            if($exists->id)
            {
                if($exists->total_pairs !== $this->total_pairs)
                {
                    $exists->datetime = NULL;
                }

                $exists->total_pairs = $this->total_pairs;
                $exists->save();

                parent::__construct($exists->id);
                return;
            }
        }

        parent::save();
    }
}
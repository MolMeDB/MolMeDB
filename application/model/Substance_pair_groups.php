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
    private static $attr_of_interest = array
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
     * 
     */
    public function update_group_stats($id_group = null)
    {
        if(!$id_group)
        {
            $group = $this->order_by('datetime ASC, id ', 'ASC')->get_one();
        }
        else
        {
            $group = new Substance_pair_groups($id_group); 
        }

        if(!$group->id)
        {
            return;
        }

        $pairs = Substance_pairs::instance()->where('id_group', $group->id)->get_all();

        $substance_ids = [];

        foreach($pairs as $p)
        {
            $substance_ids[] = [$p->id_substance_1, $p->id_substance_2];
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
                $attrs[$key] = "(i2.$val - i1.$val) as $val";
            }

            // Update stats
            $is = $this->queryAll('
                SELECT ' . implode(', ', $attrs) . '
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

            foreach($is as $i)
            {
                foreach($join as $key => $v)
                {
                    if(!is_array($join[$key]))
                    {
                        $join[$key] = []; 
                    }

                    if(is_numeric($i->$key))
                        $join[$key][] = $i->$key;
                }
            }   

            $stats = [];

            foreach($join as $att => $data)
            {
                $total = arr::total_numeric($data);

                if($total < 5)
                {
                    $stats[$att] = ['total' => $total];
                    continue;
                }

                $stats[$att] = array
                (
                    'average' => arr::average($data),
                    'sd'      => arr::sd($data),
                    'total'   => $total
                );
            }

            $record->stats = json_encode($stats);
            $record->save();
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
                $attrs[$key] = "(i2.$val - i1.$val) as $val";
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

            $stats = [];

            foreach($join as $att => $data)
            {
                $total = arr::total_numeric($data);

                if($total < 5)
                {
                    $stats[$att] = ['total' => $total];
                    continue;
                }

                $stats[$att] = array
                (
                    'average' => arr::average($data),
                    'sd'      => arr::sd($data),
                    'total'   => $total
                );
            }

            $record->stats = json_encode($stats);
            $record->save();
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
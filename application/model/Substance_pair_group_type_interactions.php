<?php

/**
 * Holds info about substance pairs
 * 
 * @property int $id
 * @property int $id_group_type
 * @property int $id_interaction_1
 * @property int $id_interaction_2
 * @property int $id_trasporter_1
 * @property int $id_trasporter_2
 * 
 * @author Jakub Juracka
 */
class Substance_pair_group_type_interactions extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'substance_pair_group_type_interactions';
        parent::__construct($id);
    }

    /**
     * Updates table per one func group
     */
    public function update_one_group()
    {
        try
        {
            Db::beginTransaction();
            $spg = new Substance_pair_groups();
            $group = $spg->order_by('update_datetime ASC, id', 'ASC')->get_one();

            if(!$group->id)
            {
                throw new Exception();
            }

            $adj = explode('/', $group->adjustment);
            $g1 = explode(',',$adj[0]);
            $g2 = isset($adj[1]) ? explode(',', $adj[1]) : [];
            $total = count($g1);

            $where = 'WHERE ';

            foreach($g1 as $key => $id1)
            {
                $t1 = "(sf1.id_fragment = $id1";
                $t2 = "(sf2.id_fragment = $id1";

                if(isset($g2[$key]))
                {
                    $id2 = $g2[$key];
                    $t1 .= " AND sf2.id_fragment = $id2";
                    $t2 .= " AND sf1.id_fragment = $id2";
                }
                else
                {
                    $t1 .= " AND l.id_substance_2_fragment IS NULL";
                    $t2 .= " AND l.id_substance_1_fragment IS NULL";
                }

                $t1 .= ')'; $t2 .= ')';
                $where .= $t1 . ' OR ' . $t2 . ' OR ';
            }

            $where = preg_replace('/\s*OR\s*$/', '', $where);

            $candidate_pairs = $this->queryAll("
                SELECT DISTINCT t.id_substance_pair
                FROM (
                    SELECT l.id, l.id_substance_pair, COUNT(l.id_substance_pair) as c
                    FROM substance_fragmentation_pair_links l
                    JOIN (
                        SELECT id_substance_pair, COUNT(id_substance_pair) as c
                        FROM substance_fragmentation_pair_links
                        GROUP BY id_substance_pair
                    ) as t ON t.id_substance_pair = l.id_substance_pair AND t.c = ?
                    LEFT JOIN substances_fragments sf1 ON sf1.id = l.id_substance_1_fragment
                    LEFT JOIN substances_fragments sf2 ON sf2.id = l.id_substance_2_fragment
                    $where 
                    GROUP BY l.id_substance_pair 
                ) as t
                WHERE t.c = ?
            ", array($total, $total));

            $pairs = [];

            foreach($candidate_pairs as $p)
            {
                $pair_row_links = Substance_pairs_links::instance()->where('id_substance_pair', $p->id_substance_pair)->get_all();
                $t1 = $g1;
                $t2 = $g2;

                if(count($pair_row_links) !== $total)
                {
                    continue;
                }

                foreach($pair_row_links as $prl)
                {
                    $id1 = $prl->id_substance_1_fragment ? intval($prl->substance_1_fragment->id_fragment) : null;
                    $id2 = $prl->id_substance_2_fragment ? intval($prl->substance_2_fragment->id_fragment) : null;

                    foreach($g1 as $key => $val1)
                    {
                        if(!isset($t1[$key]))
                        {
                            continue;
                        }

                        $val1 = intval($val1);
                        $val2 = isset($t2[$key]) ? intval($t2[$key]) : null;

                        if(($val1 === $id1 && $val2 === $id2) || 
                            ($val2 === $id1 && $val1 === $id2))
                        {
                            unset($t1[$key]);

                            if(isset($t2[$key]))
                            {
                                unset($t2[$key]);
                            }

                            continue 2;
                        }
                    }
                }  

                if(!count($t1) && !count($t2))
                {
                    $pairs[] = new Substance_pairs($p->id_substance_pair);  
                }
            }

            // Ignore some membranes
            $ignore_membrane_ids = [
                Pubchem::get_logP_membrane()->id
            ];

            // For each pair, find interactions
            foreach($pairs as $p)
            {
                // Get molecule order
                $order = $this->queryOne('
                    SELECT p.id_substance_pair, GROUP_CONCAT(sf1.id_fragment ORDER BY sf1.id_fragment) as f1, 
                        GROUP_CONCAT(sf2.id_fragment ORDER BY sf1.id_fragment) as f2, 
                        sf1.id_substance as id_substance_1, sf2.id_substance as id_substance_2
                    FROM (
                        SELECT *
                        FROM substance_fragmentation_pair_links
                        WHERE id_substance_pair = ?
                        ) as p
                    LEFT JOIN substances_fragments sf1 ON sf1.id = p.id_substance_1_fragment
                    LEFT JOIN substances_fragments sf2 ON sf2.id = p.id_substance_2_fragment
                    GROUP BY id_substance_pair
                ', array($p->id));

                if(!$order->id_substance_pair)
                {
                    continue;
                }

                if($order->f1 == $adj[0])
                {
                    $first_id = $order->id_substance_1;
                }
                else if($order->f2 == $adj[0])
                {
                    $first_id = $order->id_substance_2;
                }
                else
                {
                    continue;
                }

                // Passive
                $interactions = Interactions::instance()->queryAll('
                    SELECT DISTINCT i1.id as id1, i2.id as id2, i1.id_substance as id_substance_1, i2.id_substance as id_substance_2, 
                        i1.id_membrane as id_membrane, i1.id_method as id_method, i1.charge as charge
                    FROM (
                        SELECT *
                        FROM substance_fragmentation_pairs
                        WHERE id = ?
                    ) as p
                    JOIN interaction i1 ON i1.id_substance = p.id_substance_1
                    JOIN interaction i2 ON i2.id_substance = p.id_substance_2
                    WHERE i1.id_membrane = i2.id_membrane AND i1.id_method = i2.id_method AND i1.charge = i2.charge
                ', array($p->id));

                foreach($interactions as $i)
                {
                    if(in_array($i->id_membrane, $ignore_membrane_ids))
                    {
                        continue;
                    }

                    $switch = intval($first_id) !== intval($i->id_substance_1);

                    $record = new Substance_pair_group_types();

                    $record->id_group = $group->id;
                    $record->id_membrane = $i->id_membrane;
                    $record->id_method = $i->id_method;
                    $record->charge = $i->charge;

                    $record->save();

                    // Save Interactions details
                    $s_int = new Substance_pair_group_type_interactions();
                    $s_int->id_group_type = $record->id;
                    $s_int->id_interaction_1 = !$switch ? $i->id1 : $i->id2;
                    $s_int->id_interaction_2 = !$switch ? $i->id2 : $i->id1;
                    $s_int->save();
                }

                // Active
                $interactions = Transporters::instance()->queryAll('
                    SELECT DISTINCT t1.id as id1, t2.id as id2, t1.id_target as id_target,
                        t1.id_substance as id_substance_1, t2.id_substance as id_substance_2
                    FROM (
                        SELECT *
                        FROM substance_fragmentation_pairs
                        WHERE id = ?
                    ) as p
                    JOIN transporters t1 ON t1.id_substance = p.id_substance_1
                    JOIN transporters t2 ON t2.id_substance = p.id_substance_2
                    WHERE t1.id_target = t2.id_target
                ', array($p->id));

                foreach($interactions as $i)
                {
                    $record = new Substance_pair_group_types();

                    $switch = intval($first_id) !== intval($i->id_substance_1);

                    $record->id_group = $group->id;
                    $record->id_target = $i->id_target;

                    $record->save();

                    // Save Interactions details
                    $s_int = new Substance_pair_group_type_interactions();
                    $s_int->id_group_type = $record->id;
                    $s_int->id_transporter_1 = !$switch ? $i->id1 : $i->id2;
                    $s_int->id_transporter_2 = !$switch ? $i->id2 : $i->id1;
                    $s_int->save();
                }
            }

            $group->update_datetime = date('Y-m-d H:i:s');
            $group->save();

            return $group->id;
            Db::commitTransaction();
        }
        catch(Exception $e)
        {
            Db::rollbackTransaction();
            throw $e;
        }
    }


    /**
     * Save
     */
    public function save()
    {
        if(!$this->id)
        {
            $where = ['id_group_type' => $this->id_group_type];

            if($this->id_trasporter_1 && $this->id_trasporter_2)
            {
                $where['id_transporter_1'] = $this->id_trasporter_1;
                $where['id_transporter_2'] = $this->id_trasporter_2;
            }
            else if ($this->id_interaction_1 && $this->id_interaction_2)
            {
                $where['id_interaction_1'] = $this->id_interaction_1;
                $where['id_interaction_2'] = $this->id_interaction_2;
            }
            else
            {
                throw new MmdbException('Invalid record values.');
            }

            $exists = $this->where($where)->get_one();

            if($exists->id)
            {
                parent::__construct($exists->id);
                return;
            }
        }

        parent::save();
    }
}
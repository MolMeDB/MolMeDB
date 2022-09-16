<?php

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
            SELECT t1, t2, f1, g1, f2, g2, COUNT(CONCAT(f1,f2)) as total
            FROM
            (
                SELECT 
                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(f1.id IS NULL, "", f1.id) ORDER BY f1.smiles ASC), ",,", ",")) as t1, 
                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(f2.id IS NULL, "", f2.id) ORDER BY f1.smiles ASC), ",,", "")) as t2, 
                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(f1.smiles IS NULL, "", f1.smiles) ORDER BY f1.smiles ASC), ",,", ",")) as f1, 
                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(et1.name IS NULL, "", et1.name) ORDER BY f1.smiles ASC), ",,", ",")) as g1, 
                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(f2.smiles IS NULL, "", f2.smiles) ORDER BY f1.smiles ASC), ",,", ",")) as f2,
                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(et2.name IS NULL, "", et2.name) ORDER BY f1.smiles ASC), ",,", ",")) as g2
                FROM (
                    SELECT id, l.id_substance_pair, type, IF(t.switch = 0, id_substance_1_fragment, id_substance_2_fragment) as id_substance_1_fragment, IF(t.switch = 0, id_substance_2_fragment, id_substance_1_fragment) as id_substance_2_fragment
                    FROM substance_fragmentation_pair_links l
                    JOIN (
                        SELECT l.id_substance_pair, 
                            IF(STRCMP(
                            CONCAT(
                                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(f1.id IS NULL, "", f1.id) ORDER BY f1.id ASC), ",,", ",")),
                                "/",
                                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(f2.id IS NULL, "", f2.id) ORDER BY f1.id ASC), ",,", ","))
                            ),
                            CONCAT(
                                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(f2.id IS NULL, "", f2.id) ORDER BY f2.id ASC), ",,", ",")),
                                "/",
                                TRIM("," FROM REPLACE(GROUP_CONCAT(IF(f1.id IS NULL, "", f1.id) ORDER BY f2.id ASC), ",,", ","))
                            )) = -1, 1, 0) as switch
                        FROM substance_fragmentation_pair_links l
                        LEFT JOIN substances_fragments sf1 ON sf1.id = l.id_substance_1_fragment
                        LEFT JOIN substances_fragments sf2 ON sf2.id = l.id_substance_2_fragment
                        LEFT JOIN fragments f1 ON f1.id = sf1.id_fragment
                        LEFT JOIN fragments f2 ON f2.id = sf2.id_fragment
                        GROUP BY id_substance_pair
                    ) as t ON t.id_substance_pair = l.id_substance_pair
                ) as l
                LEFT JOIN substances_fragments sf1 ON sf1.id = l.id_substance_1_fragment
                LEFT JOIN substances_fragments sf2 ON sf2.id = l.id_substance_2_fragment
                LEFT JOIN fragments f1 ON f1.id = sf1.id_fragment
                LEFT JOIN fragments f2 ON f2.id = sf2.id_fragment
                LEFT JOIN fragments_enum_types fet1 ON fet1.id_fragment = f1.id
                LEFT JOIN fragments_enum_types fet2 ON fet2.id_fragment = f2.id
                LEFT JOIN enum_types et1 ON et1.id = fet1.id_enum_type
                LEFT JOIN enum_types et2 ON et2.id = fet2.id_enum_type
                GROUP BY id_substance_pair) as t
            GROUP BY CONCAT(f1,"/",f2)
            ORDER BY total DESC
        ');

        function rem_empty($var)
        {
            return !empty(trim($var));
        }

        $processed = [];

        foreach($data as $row)
        {
            $id_fragments_1 = array_values(array_filter(explode(',', $row->t1 ?? ""), 'rem_empty'));
            $id_fragments_2 = array_values(array_filter(explode(',', $row->t2 ?? ""), 'rem_empty'));
            $total = $row->total;

            if($total < 10)
            {
                continue;
            }

            if(count($id_fragments_1) && count($id_fragments_2) && 
                count($id_fragments_1) !== count($id_fragments_2))
            {
                continue;   // Invalid substitution
            }

            if(count($id_fragments_2) > count($id_fragments_1))
            {
                $t = $id_fragments_1;
                $id_fragments_1 = $id_fragments_2;
                $id_fragments_2 = $t;
            }

            asort($id_fragments_1);
            $type = Substance_pairs_links::TYPE_ADD;

            if(count($id_fragments_2))
            {
                $type = Substance_pairs_links::TYPE_SUBS;

                $t = $id_fragments_2;
                $id_fragments_2 = []; $i = 0;

                foreach($id_fragments_1 as $key => $val)
                {
                    $id = $t[$i++];
                    $f = new Fragments($id);

                    if($f->get_functional_group() === null)
                    {
                        continue 2;
                    }

                    $id_fragments_2[$key] = $id;
                }
            }

            // Check, if data are valid func. groups
            foreach($id_fragments_1 as $id)
            {
                $f = new Fragments($id);

                if($f->get_functional_group() === null)
                {
                    continue 2;
                }
            }
        
            $record = new Substance_pair_groups();

            $record->type = $type;
            $record->total_pairs = $total;
            $record->adjustment = $type == Substance_pairs_links::TYPE_ADD ?
                implode(',', $id_fragments_1) :
                implode(',', $id_fragments_1) . '/' . implode(',', $id_fragments_2);

            $record->save();

            $processed[] = $record->id;
        }

        // Delete old records
        $this->query('
            DELETE FROM substance_pair_groups WHERE id NOT IN ("' . implode('","', $processed) . '";
        ');
    }

    
    /**
     * Returns distribution of values for given group
     * 
     * @param int $id_group
     * 
     * @return array|null
     */
    public function save_group_distributions($id_group = NULL)
    {
        if(!$id_group)
        {
            $id_group = $this->id;
        }

        $group =  new Substance_pair_groups($id_group);

        if(!$group->id)
        {
            return null;
        }

        $types = Substance_pair_group_types::instance()->where('id_group', $group->id)->get_all();

        if(!count($types))
        {
            return null;
        }

        foreach($types as $type)
        {
            $t =  Substance_pair_group_type_interactions::instance()->where('id_group_type', $type->id)->get_all();
            $attrs = self::$attr_of_interest['passive'];

            foreach($t as $record)
            {
                $int1 = new Interactions($record->id_interaction_1);
                $int2 = new Interactions($record->id_interaction_2);

                if(!$int1->id || !$int2->id)
                {
                    continue;
                }

                foreach($attrs as $attr => $v)
                {
                    if(!is_array($attrs[$attr]))
                    {
                        $attrs[$attr] = [];
                    }

                    if($int1->$attr === null || $int1->$attr === null)
                    {
                        continue;
                    }

                    $attrs[$attr][] = round(floatval($int1->$attr) - floatval($int2->$attr), 2);
                }
            }

            $type->stats = json_encode($attrs);
            $type->save();
        }
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
                $exists->total_pairs = $this->total_pairs;
                $exists->save();

                parent::__construct($exists->id);
                return;
            }
        }

        parent::save();
    }
}
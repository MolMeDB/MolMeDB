<?php

/**
 * Holds info about substance pairs
 * 
 * @property int $id
 * @property int $id_substance_1
 * @property Substances $substance_1
 * @property Fragmentation $fragmentation_1
 * @property int $id_substance_2
 * @property Substances $substance_2
 * @property Fragmentation $fragmentation_2
 * @property int $id_core
 * @property Fragments $core
 * @property int $id_group
 * @property Substance_pair_groups $group
 * @property int $substance_1_fragmentation_order
 * @property int $substnace_2_fragmentation_order
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Substance_pairs extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'substance_fragmentation_pairs';
        parent::__construct($id);
        $this->load_fragmentations();
    }

    /**
     * Loads fragmentations details
     * 
     */
    private function load_fragmentations()
    {
        if(!$this->id)
        {
            return;
        }

        $this->fragmentation_1 = Fragmentation::get_substance_fragmentation($this->id_substance_1, $this->substance_1_fragmentation_order);
        $this->fragmentation_2 = Fragmentation::get_substance_fragmentation($this->id_substance_2, $this->substance_2_fragmentation_order);
    }

    /**
     * Instance
     * 
     * @return Substance_pairs
     */
    public static function instance()
    {
        return parent::instance();
    }

    /**
     * Links to another tables
     */
    protected $has_one = array
    (
        'id_substance_1' => array
        (
            'var' => 'substnace_1',
            'class' => 'Substances'
        ),
        'id_substance_2' => array
        (
            'var' => 'substnace_2',
            'class' => 'Substances'
        ),
        'id_core' => array
        (
            'var' => 'core',
            'class' => 'Fragments'
        ),
        'id_group' => array
        (
            'var' => 'group',
            'class' => 'Substance_pair_groups'
        ),
    );

    /**
     * Returns substance pairs
     * 
     * @param int $id_substance
     * 
     * @return Substance_pairs[]
     */
    public function by_substance($id_substance)
    {
        if(!$id_substance)
        {
            return [];
        }

        $ids = $this->queryAll('SELECT id
            FROM substance_fragmentation_pairs
            WHERE id_substance_1 = ? OR id_substance_2 = ?',
            array($id_substance, $id_substance));

        $result = [];

        foreach($ids as $r)
        {
            $result[] = new Substance_pairs($r->id);
        }

        return $result;
    }

    /**
     * Returns detailed comaprison between molecule pair
     * 
     * @return Substance_pairs_comparison
     */
    public function compare()
    {
        return new Substance_pairs_comparison($this);
    }

    /**
     * Saves
     */
    public function save()
    {
        // check if exists
        $exists = $this->where(array
        (
            'id_substance_1' => $this->id_substance_1,
            'id_substance_2' => $this->id_substance_2,
        ))
        ->get_one();

        if(!$exists->id)
        {
            $exists = $this->where(array
            (
                'id_substance_2' => $this->id_substance_1,
                'id_substance_1' => $this->id_substance_2,
            ))
            ->get_one();
        }

        if($exists->id)
        {
            $f1_total = Substances_fragments::instance()->where(
                array
                (
                    'id_substance' => $this->id_substance_2,
                    'order_number' => $this->substance_2_fragmentation_order
                ))
                ->get_one()->total_fragments;

            $f2_total = Substances_fragments::instance()->where(
                array
                (
                    'id_substance' => $exists->id_substance_2,
                    'order_number' => $exists->substance_2_fragmentation_order
                ))
                ->get_one()->total_fragments;

            if($f1_total > $f2_total)
            {
                $exists->id_core = $this->id_core;
                $exists->substance_1_fragmentation_order = $this->substance_1_fragmentation_order;
                $exists->substance_2_fragmentation_order = $this->substnace_2_fragmentation_order;

                $exists->save();
            }
            parent::__construct($exists->id);
            return;
        }

        parent::save();

        $this->load_fragmentations();

        if(!$this->id)
        {
            return;
        }

        // Add detail with substitutions and aditions
        $comp = $this->compare();

        if(!$comp->status)
        {
            return;
        }

        if($comp->reverse)
        {
            $t = $this->id_substance_1;
            $t1 = $this->substance_1_fragmentation_order;
            $this->id_substance_1 = $this->id_substance_2;
            $this->id_substance_2 = $t;
            $this->substance_1_fragmentation_order = $this->substnace_2_fragmentation_order;
            $this->substnace_2_fragmentation_order = $t1;

            $this->save();
        }

        foreach($comp->additions as $a)
        {
            $a->save();
        }

        foreach($comp->substitutions as $s)
        {
            $s->save();
        }
    }

    
    /**
     * Returns new molecules for matching
     * 
     * @param int $limit
     * 
     * @return Substances[]
     */
    public function get_unmatched_molecules($limit)
    {
       $ids = Db::instance()->queryAll("
            SELECT DISTINCT id
            FROM substances
            WHERE id NOT IN (
                SELECT id_substance_1
                FROM substance_fragmentation_pairs
                WHERE id_substance_1 = id_substance_2
            ) AND id IN (
                SELECT DISTINCT id_substance
                FROM substances_fragments)
            LIMIT $limit
        ");

        $result = [];

        foreach($ids as $row)
        {
            $result[] = new Substances($row->id);
        }

        return $result;
    }

    /**
     * Fills group ids for given group
     * 
     * @param int $group_id
     */
    public function assign_group_pairs($group_id)
    {
        $group = new Substance_pair_groups($group_id);

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
                $pair = new Substance_pairs($p->id_substance_pair);
                $pair->id_group = $group->id;
                $pair->save();  
            }
        }
    }
}


/**
 * Holds info about comparison of molecule pair
 * 
 */
class Substance_pairs_comparison
{
    /** @var array */
    public $additions = [];

    /** @var array */
    public $substitutions = [];

    /** @var bool */
    public $status = false;

    /** @var bool */
    public $reverse = false;

    /**
     * Constructor
     * 
     * @param Substance_pairs $pair
     */
    function __construct($pair)
    {
        $this->prepare($pair);
    }

    /**
     * Prepairs all details about molecular pair
     * 
     * @param Substance_pairs $pair
     */
    private function prepare($pair)
    {
        if($pair->id_substance_1 == $pair->id_substance_2)
        {
            return;
        }

        $core = $pair->core;
        $core_var_ids = $core->get_all_options_ids();

        $additions = [];
        $substitutions = [];

        $fragmentation_1 = new Fragmentation(
            Substances_fragments::instance()->where(array
            (
                'id_substance'  => $pair->id_substance_1,
                'order_number'  => $pair->substance_1_fragmentation_order,
            ))
            ->in('id_fragment', $core_var_ids)
            ->order_by('ll', 'DESC')
            ->select_list('*, LENGTH(links) as ll')
            ->get_one()->id, true
        );

        $fragmentation_2 = new Fragmentation(
            Substances_fragments::instance()->where(array
            (
                'id_substance'  => $pair->id_substance_2,
                'order_number'  => $pair->substance_2_fragmentation_order,
            ))
            ->in('id_fragment', $core_var_ids)
            ->order_by('ll', 'DESC')
            ->select_list('*, LENGTH(links) as ll')
            ->get_one()->id, true
        );

        if(!$fragmentation_1->core || !$fragmentation_2->core)
        {
            throw new MmdbException('Invalid fragmentation record.');
        }

        if($pair->substance_2_fragmentation_order == 1)
        {
            foreach($fragmentation_1->side as $f)
            {
                $link = new Substance_pairs_links();

                $link->id_substance_pair = $pair->id;
                $link->type = $link::TYPE_ADD;
                $link->id_substance_1_fragment = NULL;
                $link->id_substance_2_fragment = $f->id;

                $this->reverse = true;

                $additions[] = $link;
            }
        }
        else if($pair->substance_1_fragmentation_order == 1)
        {
            foreach($fragmentation_2->side as $f)
            {
                $link = new Substance_pairs_links();

                $link->id_substance_pair = $pair->id;
                $link->type = $link::TYPE_ADD;
                $link->id_substance_1_fragment = NULL;
                $link->id_substance_2_fragment = $f->id;

                $this->reverse = false;

                $additions[] = $link;
            }
        }
        else
        { // SUBSTITUTIONS
            $links_1 = explode(',', $fragmentation_1->core->links);
            $links_2 = explode(',', $fragmentation_2->core->links);

            if(count($links_1) !== count($links_2))
            {
                throw new MmdbException('Invalid fragmentation pair.');
            }

            $pairs = [];
            $t1 = [];
            $t2 = [];

            foreach(range(0, count($links_1)-1) as $i)
            {
                $l1 = $links_1[$i];
                $l2 = $links_2[$i];

                // Find corresponding fragments
                $f1 = Substances_fragments::instance()->where(array
                    (
                        'id_substance' => $pair->id_substance_1,
                        'order_number' => $pair->substance_1_fragmentation_order,
                        'links'        => $l1
                    ))->get_one();
                $f2 = Substances_fragments::instance()->where(array
                    (
                        'id_substance' => $pair->id_substance_2,
                        'order_number' => $pair->substance_2_fragmentation_order,
                        'links'        => $l2
                    ))->get_one();

                if(!$f1->id || !$f2->id)
                {
                    throw new MmdbException('Invalid fragmentation pair.');
                }

                $pairs[] = [$f1->id, $f2->id];
                $t1[] = $f1->id;
                $t2[] = $f2->id;
            }

            asort($t1);
            asort($t2);

            $t1 = implode(',', $t1);
            $t2 = implode(',', $t2);

            if(strcmp($t1, $t2) > 0)
            {
                $this->reverse = true;
            }

            foreach($pairs as $p)
            {
                // save substitution
                $link = new Substance_pairs_links();

                $link->id_substance_pair = $pair->id;
                $link->type = $link::TYPE_SUBS;
                $link->id_substance_1_fragment = $this->reverse ? $p[1] : $p[0];
                $link->id_substance_2_fragment = $this->reverse ? $p[0] : $p[1];

                $substitutions[] = $link;
            }
        }

        $this->additions = $additions;
        $this->substitutions = $substitutions;
        $this->status = true;
    }

    /**
     * Merges substitutions by core
     * 
     * @param array $subs
     * 
     * @return array
     */
    private function merge($subs)
    {
        $cores = arr::get_values($subs, 'core_common');
        $un_cores = array_unique($cores);

        $result = [];

        foreach($subs as $key => $s)
        {
            if(array_key_exists($key, $un_cores))
            {
                $result[$key] = $s;
                continue;
            }

            $k = array_search($s['core_common'], $un_cores);

            $result[$k]['side_1'] = $result[$k]['side_1'] + $s['side_1'];
            $result[$k]['side_2'] = $result[$k]['side_2'] + $s['side_2'];
        }

        return $result;
    } 
}
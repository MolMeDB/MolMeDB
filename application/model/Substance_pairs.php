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
            $reverse = true;
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
                $link->id_substance_1_fragment = $f->id;
                $link->id_substance_2_fragment = NULL;
                // $link->id_core = $core->id;

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
                // $link->id_core = $core->id;

                $additions[] = $link;
            }
        }
        else
        { // SUBSTITUTIONS
            $links_1 = explode(',', $fragmentation_1->core->links);
            $links_2 = explode(',', $fragmentation_2->core->links);

            if(count($links_1) !== count($links_2))
            {
                print_r($links_1);
                print_r($links_2);
                print_r($pair);
                die;

                throw new MmdbException('Invalid fragmentation pair.');
            }

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

                // save substitution
                $link = new Substance_pairs_links();

                $link->id_substance_pair = $pair->id;
                $link->type = $link::TYPE_SUBS;
                $link->id_substance_1_fragment = $f1->id;
                $link->id_substance_2_fragment = $f2->id;

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
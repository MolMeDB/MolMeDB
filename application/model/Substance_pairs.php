<?php

/**
 * Holds info about substance paris
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

        $ad = $comp->additions;

        if(isset($ad['side_1']))
        {
            foreach($ad['side_1'] as $id => $a)
            {
                $new1 = new Substance_pairs_links();

                // $f1 = $this->fragmentation_1;

                $new1->id_substance_pair = $this->id;
                $new1->id_substance_1_fragment = $id;
                $new1->type = $new1::TYPE_ADD;
                // $new1->id_core = $f1->core->id_fragment;

                $new1->save();
            }
        }

        if(isset($ad['side_2']))
        {
            foreach($ad['side_2'] as $id => $a)
            {
                $new = new Substance_pairs_links();

                // $f2 = $this->fragmentation_2;

                $new->id_substance_pair = $this->id;
                $new->id_substance_2_fragment = $id;
                $new->type = $new::TYPE_ADD;
                // $new->id_core = $f2->core->id_fragment;

                $new->save();
            }
        }

        $subs = $comp->substitutions;
        foreach($subs as $s)
        {
            // $record = Fragments::instance()->where('smiles', $s['core_common'])->get_one();

            // if(!$record->id)
            // {
            //     throw new Exception('Invalid fragment smiles. Record not found.');
            // }

            foreach($s['side_1'] as $id1 => $s1)
            {
                foreach($s['side_2'] as $id2 => $s2)
                {
                    $new = new Substance_pairs_links();

                    // $new->id_core = $record->id;
                    $new->id_substance_pair = $this->id;
                    $new->id_substance_1_fragment = $id1;
                    $new->id_substance_2_fragment = $id2;
                    $new->type = $new::TYPE_SUBS;

                    $new->save();
                }
            }
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
            )
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

        $rdkit = new Rdkit();

        $core = $pair->core;
        $core_ids = $core->get_all_options_ids();
        $s1_side = $s2_side = [];
        $s1_core = $s2_core = null;

        foreach($pair->fragmentation_1->raw_fragments as $rf)
        {
            if(in_array($rf->fragment->id, $core_ids))
            {
                if($s1_core == null)
                {
                    $s1_core = $rf;
                    continue;
                }
                else if(strlen($s1_core->fragment) < strlen($rf->fragment->smiles))
                {
                    $s1_side[] = $s1_core;
                    $s1_core = $rf;
                    continue;
                }
            }

            $s1_side[] = $rf;
        }
        foreach($pair->fragmentation_2->raw_fragments as $rf)
        {
            if(in_array($rf->fragment->id, $core_ids))
            {
                if($s2_core == null)
                {
                    $s2_core = $rf;
                    continue;
                }
                else if(strlen($s2_core->fragment) < strlen($rf->fragment->smiles))
                {
                    $s2_side[] = $s2_core;
                    $s2_core = $rf;
                    continue;
                }
            }

            $s2_side[] = $rf;
        }

        if(!$s1_core || !$s2_core || !$s1_core->id || !$s2_core->id)
        {
            throw new MmdbException('Invalid fragmentation. Cannot find corresponding core.');
        }

        $s1_core_smiles = $s1_core->fragment->fill_link_numbers($s1_core->fragment->smiles, $s1_core->links);
        $s2_core_smiles = $s2_core->fragment->fill_link_numbers($s2_core->fragment->smiles, $s2_core->links);

        if(!$s1_core_smiles || !$s2_core_smiles)
        {
            throw new MmdbException('Invalid fragmentation. Cannot find corresponding core.');
        }


        if(count(explode(",", $s1_core->links)) < count($s1_side) || 
            count(explode(",", $s2_core->links)) < count($s2_side))
        {
            $this->status = false;
            return;   
        }

        $substitutions = [];
        $substitutions_raw_sides = ['side_1' => [], 'side_2' => []];

        if($pair->substance_1_fragmentation_order != 1 && $pair->substnace_2_fragmentation_order != 1)
        {
            // Compare each other and find substitutions
            foreach($s1_side as $side_1)
            {
                foreach($s2_side as $side_2)
                {
                    // Prepare func. groups
                    $fg1_smiles = $side_1->fragment->fill_link_numbers($side_1->fragment->smiles, $side_1->links);
                    $fg2_smiles = $side_2->fragment->fill_link_numbers($side_2->fragment->smiles, $side_2->links);

                    $s1_joined_raw = Fragments::join_by_links([$s1_core_smiles, $fg1_smiles], false);
                    $s2_joined_raw = Fragments::join_by_links([$s2_core_smiles, $fg2_smiles], false);

                    if(!$s1_joined_raw || !$s2_joined_raw)
                    {
                        throw new MmdbException("Cannot compare molecular pair.");
                    }

                    // Check, if core is the same
                    if(strtolower(explode('.', $s1_joined_raw)[0]) == strtolower(explode('.', $s2_joined_raw)[0]))
                    {
                        $substitutions[] = array // TODO
                        (
                            'core_common' => preg_replace('/\[[\*0-9]+:[\*0-9]*\]/', '[*:]', explode('.', $s1_joined_raw)[0]),
                            'core_1' => $s1_core_smiles,
                            'core_2' => $s2_core_smiles,
                            'side_1' => [$side_1->id => $fg1_smiles],
                            'side_2' => [$side_2->id => $fg2_smiles],
                        );
                        $substitutions_raw_sides['side_1'][$side_1->id] = $fg1_smiles;
                        $substitutions_raw_sides['side_2'][$side_2->id] = $fg2_smiles;
                        continue;
                    }

                    // Also check with canonization and fragmentation
                    $s1_joined = Fragments::join_by_links([$s1_core_smiles, $fg1_smiles], true); 
                    $s2_joined = Fragments::join_by_links([$s2_core_smiles, $fg2_smiles], true);

                    // Add hydrogens instead of remaining links
                    $s1_joined = preg_replace('/\[[\*0-9]+:\]/', '[H]', $s1_joined);
                    $s2_joined = preg_replace('/\[[\*0-9]+:\]/', '[H]', $s2_joined);

                    // Fragment both
                    $s1_fragments = $rdkit->fragment_molecule($s1_joined, false, 120); 
                    $s2_fragments = $rdkit->fragment_molecule($s2_joined, false, 120);

                    if($s1_fragments == null || $s2_fragments == null)
                    {
                        throw new MmdbException("Cannot compare molecular pair. Fragmentation error.");
                    }

                    $new_core_1 = $new_core_2 = null;

                    foreach($s1_fragments as $key => $fragmentation)
                    {
                        if(count($fragmentation->fragments) != 2)
                        {
                            continue;
                        }

                        $k = null;

                        foreach($fragmentation->fragments as $key2 => $f)
                        {
                            if(preg_replace('/\[\*:[0-9]+\]/', '[*:]', $f->smiles) == $side_1->fragment->smiles)
                            {
                                $k = $key2;
                                break;
                            }
                        }

                        if($k !== null)
                        {
                            $k = ($k+1) % 2;
                            $new_core_1 = $fragmentation->fragments[$k]->smiles;
                        }
                    }

                    foreach($s2_fragments as $key => $fragmentation)
                    {
                        if(count($fragmentation->fragments) != 2)
                        {
                            continue;
                        }

                        $k = null;

                        foreach($fragmentation->fragments as $key2 => $f)
                        {
                            if(preg_replace('/\[\*:[0-9]+\]/', '[*:]', $f->smiles) == $side_2->fragment->smiles)
                            {
                                $k = $key2;
                                break;
                            }
                        }

                        if($k !== null)
                        {
                            $k = ($k+1) % 2;
                            $new_core_2 = $fragmentation->fragments[$k]->smiles;
                        }
                    }

                    if(strtolower($new_core_1) == strtolower($new_core_2))
                    {
                        $substitutions[] = array // TODO
                        (
                            'core_common' => preg_replace('/\[[\*0-9]+:[\*0-9]*\]/', '[*:]', $new_core_1),
                            'core_1' => $s1_core_smiles,
                            'core_2' => $s2_core_smiles,
                            'side_1' => [$side_1->id => $fg1_smiles],
                            'side_2' => [$side_2->id => $fg2_smiles],
                        );
                        $substitutions_raw_sides['side_1'][$side_1->id] = $fg1_smiles;
                        $substitutions_raw_sides['side_2'][$side_2->id] = $fg2_smiles;
                        continue;
                    }
                }
            }
        }

        // Merge substitutions on the same atom
        $substitutions = $this->merge($substitutions);

        // Find additions
        $additions = ['side_1' => [], 'side_2' => []];

        foreach($s1_side as $side_1)
        {
            if(array_key_exists($side_1->id, $substitutions_raw_sides['side_1']))
            {
                continue;
            }

            $fg1_smiles = $side_1->fragment->fill_link_numbers($side_1->fragment->smiles, $side_1->links);

            $additions['side_1'][$side_1->id] = $fg1_smiles;
        }
        foreach($s2_side as $side_2)
        {
            if(array_key_exists($side_2->id, $substitutions_raw_sides['side_2']))
            {
                continue;
            }

            $fg2_smiles = $side_2->fragment->fill_link_numbers($side_2->fragment->smiles, $side_2->links);

            $additions['side_2'][$side_2->id] = $fg2_smiles;
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
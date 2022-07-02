<?php

use Fragmentation as GlobalFragmentation;

/**
 * Holds info about fragmentations and do some operations on them
 * 
 * @author Jakub Juracka
 */
class Fragmentation
{
    /**
     * @var Substances_fragments[]
     */
    public $raw_fragments = [];

    /**
     * @var Substances_fragments
     */
    public $core;

    /**
     * @var Substances_fragments[]
     */
    public $side = [];

    /**
     * @var int
     */
    public $id_substance;

    /**
     * @var int
     */
    public $order_number;

    /**
     * Constructor
     * 
     * @param int $id - Any id from table substance_fragments
     * @param bool $is_core - Set fragment with given ID as core?
     */
    function __construct($id = NULL, $is_core = false)
    {
        $p = new Substances_fragments($id);

        if($p->id)
        {
            $this->id_substance = $p->id_substance;
            $this->order_number = $p->order_number;

            $this->raw_fragments = $p->where(array
            (
                'id_substance'  => $p->id_substance,
                'order_number'  => $p->order_number
            ))
            ->order_by('CHAR_LENGTH(links) DESC, similarity', 'DESC')
            ->get_all();

            foreach($this->raw_fragments as $k => $f)
            {
                if(($is_core && $id == $f->id) || (!$is_core && $k == 0))
                {
                    $this->core = $f;
                    continue;
                }
                else
                {
                    $this->side[] = $f;
                }
            }
        }
    }

    /**
     * Returns array of all molecule fragmentations
     * 
     * @param int $id_substance
     * 
     * @return Fragmentation[]
     */
    public static function get_all_substance_fragmentations($id_substance)
    {   
        $repr_ids = arr::get_values(Substances_fragments::instance()->where('id_substance', $id_substance)
            ->group_by('order_number')
            ->select_list('id, order_number')
            ->distinct()
            ->get_all(), 'id');

        $all_fragmentations = [];

        foreach($repr_ids as $id)
        {
            $all_fragmentations[] = new Fragmentation($id);
        }

        return $all_fragmentations;
    }

    /**
     * Finds all fragmentations containing given fragment_id
     * 
     * @param int $id_fragment
     * @param int $ignore_id_substance - Exclude fragmentations linked to given id_substance?
     * 
     * @return Fragmentation[]
     */
    public static function get_all_with_fragment_id($id_fragment, $ignore_id_substance = null)
    {
        $where = array('id_fragment' => $id_fragment);

        if($ignore_id_substance)
        {
            $where['id_substance !='] = $ignore_id_substance;
        }

        // Real danger of huge number of results - Returns max 100,000 results 
        $id_substances = arr::get_values(Substances_fragments::instance()
            ->where($where)
            ->select_list('id_substance')
            ->distinct()
            ->limit(100000)
            ->get_all(), 'id_substance');

        $results = [];
        $saved = [];

        foreach($id_substances as $id)
        {
            if(!isset($saved[$id]))
            {
                $saved[$id] = [];
            }

            $records = Substances_fragments::instance()->where(array
            (
                'id_substance'  => $id,
                'id_fragment'   => $id_fragment 
            ))
            ->get_all();

            foreach($records as $r)
            {
                if(in_array($r->order_number, $saved[$id]))
                {
                    continue;
                }

                $results[$id . '_' . $r->order_number] = new Fragmentation($r->id, true);
                $saved[$id][] = $r->order_number;
            }
        }

        return $results;
    }
}
<?php

/**
 * Holds info about fragments of substances
 * 
 * @property int $id
 * @property int $id_substance
 * @property int $id_fragment
 * @property Fragments $fragment
 * @property string $links
 * @property int $order_number
 * @property float $similarity
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Substances_fragments extends Db
{

    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'substances_fragments';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_fragment'
    );


    /**
     * Returns free order number
     * 
     * @param int $id_substance
     * 
     * @return int
     */
    public function get_free_order_number($id_substance)
    {
        $d = $this->queryOne('
            SELECT MAX(order_number) as m
            FROM substances_fragments
            WHERE id_substance = ?
        ', array($id_substance));

        if(!$d || !$d->m)
        {
            return 1;
        }

        return $d->m+1;
    }

        /**
     * Returns fragments for given molecule
     * 
     * @param int $id_substance
     * 
     * @return array
     */
    public function get_all_substance_fragments($id_substance)
    {
        $records = $this->where('id_substance', $id_substance)->get_all();

        // Prepare results
        if(!count($records))
        {
            return [];
        }

        $structure = [];
        $order = $records[0]->order_number;

        foreach($records as $r)
        {
            if($r->order_number !== $order)
            {
                $order = $r->order_number;
            }

            if(!isset($structure[$order]))
            {
                $structure[$order] = [];
            }

            $options = Fragments_options::instance()->where('id_parent', $r->id_fragment)->get_all();
            $subfr = [];

            foreach($options as $o)
            {
                $subfr[] = (object) array
                (
                    'smiles' => $o->child->smiles,
                    'deletions' => $o->deletions
                );
            }

            $structure[$order][] = (object)array
            (
                'id' => $r->id_fragment,
                'links' => $r->links,
                'smiles' => $r->fragment->smiles,
                'subfragments' => $subfr
            );
        }

        // Prepare structure
        foreach($structure as $row)
        {
            foreach($row as $r)
            {
                $links = explode(',', $r->links);
                $smiles = $r->smiles;

                foreach($links as $l)
                {
                    $t = '[' . $l . ':]';
                    $smiles = preg_replace('/\[\*:\]/', $t, $smiles, 1);
                }

                $r->smiles = $smiles;

                foreach($r->subfragments as $fr)
                {
                    $total = preg_match_all('/\[\*:\]/', $fr->smiles);
                    $d = explode(',', $fr->deletions);

                    if(!$total)
                    {
                        continue;
                    }

                    foreach(range(1, $total) as $i)
                    {
                        if(in_array($i, $d))
                        {
                            continue;
                        }

                        $fr->smiles = preg_replace('/\[\*:\]/', '[*:' . $i . ']', $fr->smiles, 1);
                    }
                }
            }
        }

        // print_r($structure);
        // die;

        return $structure;
    }

    /**
     * Checks, if exists given fragmentation
     * 
     * TODO - předělat s total_fragments atributem
     * 
     * @param int $id_substance
     * @param array $id_fragments
     * 
     * @return boolean
     */
    public function fragmentation_exists($id_substance, $id_fragments)
    {
        $ids = implode(',', $id_fragments);

        $d = $this->queryAll("
            SELECT *
            FROM substances_fragments
            WHERE id_fragment IN ($ids) AND id_substance = ?
        ", array($id_substance));

        $orders = [];

        foreach($d as $row)
        {
            if(isset($orders[$row->order_number]))
            {
                $orders[$row->order_number][] = $row->id_fragment;
            }
            else
            {
                $orders[$row->order_number] = [$row->id_fragment];
            }
        }

        foreach($orders as $ids)
        {
            $t = $id_fragments;

            foreach($ids as $id)
            {
                $k = array_search($id, $t, true);

                if($k === false)
                {
                    continue 2;
                }

                unset($t[$k]);
            }

            if(empty($t))
            {
                return true;
            }
        }

        return false;
    }


    /**
     * Returns all non-fragmented substances
     * 
     * @return Substances[]
     */
    public function get_non_fragmented_substances($limit = 1000)
    {
        $all = $this->queryAll('SELECT DISTINCT s.id
            FROM `substances` s
            LEFT JOIN substances_fragments sf ON sf.id_substance = s.id
            JOIN validator_identifiers vi ON vi.id_substance = s.id
            LEFT JOIN error_fragments ef ON ef.id_substance = s.id AND ef.type = ?
            WHERE sf.id IS NULL AND vi.identifier = ? AND vi.state = ? AND (ef.datetime < ? OR ef.id IS NULL)
            LIMIT ' . $limit, 
                array
                (
                    Error_fragments::TYPE_FRAGMENTATION_ERROR, 
                    Validator_identifiers::ID_SMILES, 
                    Validator_identifiers::STATE_VALIDATED,
                    date('Y-m-d H:i:s', strtotime('-1 day'))
                ));

        $result = [];

        foreach($all as $row)
        {
            $result[] = new Substances($row->id);
        }

        return $result;
    }
}
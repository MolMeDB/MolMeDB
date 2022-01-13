<?php

/**
 * Holds info about fragments of substances
 * 
 * @property int $id
 * @property int $id_substance
 * @property int $id_fragment
 * @property string $links
 * @property int $order_number
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
     * Checks, if exists given fragmentation
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
                $orders[$row->order_number][] = $row->id;
            }
            else
            {
                $orders[$row->order_number] = [$row->id];
            }

            if(count($orders[$row->order_number]) == count($id_fragments))
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
            WHERE sf.id IS NULL
            LIMIT ' . $limit);

        $result = [];

        foreach($all as $row)
        {
            $result[] = new Substances($row->id);
        }

        return $result;
    }
}
<?php

/**
 * Holds info about substance links
 * 
 * @property int $id_substance
 * @property string $identifier
 * @property int $user_id
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Substance_links extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'substance_links';
        parent::__construct($id);
    }

    /**
     * Get records by substance_id
     * 
     * @param int $substance_id
     * 
     * @return array
     */
    public function get_by_substance($substance_id)
    {
        return $this->where('id_substance', $substance_id)->get_all();
    }

    /**
     * Get record by identifier
     * 
     * @param string $identifier
     * 
     * @return Substances
     */
    public function get_substance_by_identifier($identifier)
    {
        $p = $this->where('identifier LIKE', $identifier)->get_one();

        if($p->id_substance)
        {
            return new Substances($p->id_substance);
        }

        return new Substances();
    }
}
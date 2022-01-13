<?php

/**
 * Holds info about molecule fragments
 * 
 * @property int $id
 * @property string $smiles
 * 
 * @author Jakub Juracka
 */
class Fragments extends Db
{
    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'fragments';
        parent::__construct($id);
    }

    /**
     * Prepares fragment
     * 
     * @param string $smiles
     * 
     * @return object|null
     */
    public function prepare_fragment($smiles)
    {
        if(!$smiles)
        {
            return null;
        }

        preg_match_all('/\[\*:([0-9]+)\]/', $smiles, $all);

        $smiles = preg_replace('/\[\*:[0-9]+\]/', '[*:]', $smiles);

        return (object)array
        (
            'smiles' => $smiles,
            'count'  => count($all[1]),
            'links'  => implode(',', $all[1])
        );
    }

    /**
     * Overloading of save function
     */
    public function save()
    {
        $exists = $this->where('smiles', $this->smiles)->get_one();

        if($exists->id)
        {
            parent::__construct($exists->id);
            return;
        }

        parent::save();
    }
}
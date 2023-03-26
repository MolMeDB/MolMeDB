<?php

/**
 * Holds info about molecule fragments
 * 
 * @property int $id
 * @property int $id_fragment
 * @property Fragments $fragment
 * @property string $smiles
 * @property int $cosmo_flag
 * 
 * @author Jakub Juracka
 */
class Fragment_ionized extends Db
{
    /** MAX FRAGMENT SIZE (number of chars) */
    const MAX_SIZE = 1023;

    /**
     * COSMO flags
     */
    const COSMO_F_OPTIMIZE_ERR_COMLETE = 1;
    const COSMO_F_OPTIMIZE_ERR_PARTIAL = 2;
    const COSMO_F_COSMO_DOWNLOADED     = 3;
    const COSMO_F_COSMO_PARSED         = 4;

    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'fragments_ionized';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_fragment'  
    );

    /**
     * Instance builder
     * 
     * @return Fragment_ionized
     */
    public static function instance()
    {
        return new Fragment_ionized();
    }

    /**
     * Returns all SMILES of ionized molecule
     * - If not exists in DB, saves also new records
     * 
     * @param Fragments $fragment
     * 
     * @return Fragment_ionized[]
     */
    public function get_all_ionized($fragment)
    {
        if(!$fragment || !$fragment->id || !$fragment->is_neutral())
        {
            throw new MmdbException('Invalid fragment.');
        }

        // Check if already exists
        $exists = Run_ionization::instance()->where('id_fragment', $fragment->id)->get_one();

        if($exists && $exists->id)
        {
            return true;
        }

        $rdkit = new Rdkit();

        // Return all ionized fragments
        $all = $rdkit->get_ionization_states($fragment->smiles);

        if($all == FALSE)
        {
            // Error occured
            throw new MmdbException('Cannot get ionization states. Please, try again.');
        }

        // If ok, save records and log
        $log = new Run_ionization();

        $log->id_fragment = $fragment->id;
        $log->ph_start = $all->pH_start;
        $log->ph_end = $all->pH_end;
        $log->save();

        // To make sure, that neutral is included
        $all->molecules[] = $fragment->smiles;

        // Save records
        foreach($all->molecules as $smiles)
        {
            if(!$smiles)
            {
                continue;
            }

            // Check if exists
            $exists = $this->where(array
            (
                'smiles LIKE' => $smiles
            ))->get_one();

            if($exists->id)
            {
                if($exists->id_fragment != $fragment->id)
                {
                    throw new MmdbException('Error! Ionized fragment already assigned to another fragment (id: ' . $exists->id_fragment . ")");
                }
                continue;
            }

            $new = new Fragment_ionized();

            $new->id_fragment = $fragment->id;
            $new->smiles = $smiles;

            $new->save();
        }

        return $this->where('id_fragment', $fragment->id)->get_all();
    }
}
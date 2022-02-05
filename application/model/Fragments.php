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

        // After adding new fragment, create options
        if($this->id)
        {
            if(!preg_match_all('/\[\*:\]/', $this->smiles))
            {
                return;
            }

            $rdkit = new Rdkit();

            $subfragments = Text::str_replace_combine_all('[*:]', '[H]', $this->smiles);

            foreach($subfragments as $dels => $smi)
            {
                // Smiles must be canonized first!
                $smi = preg_replace('/\[\*:\]/', '[*:1]', $smi);

                $c_smi = $rdkit->canonize_smiles($smi);

                // Try repeatedly - max 3-times
                $limit = 3;

                while(!$c_smi && $limit)
                {
                    $c_smi = $rdkit->canonize_smiles($smi);
                    $limit--;
                }

                if(!$c_smi)
                {
                    throw new Exception('Cannot canonize smiles - ' . $smi);
                }

                $c_smi = preg_replace('/\[\*:1\]/', '[*:]', $c_smi);

                // Add new
                $fragment = new Fragments();
                $fragment->smiles = $c_smi;
                $fragment->save();

                $new = new Fragments_options();
                $new->id_parent = $this->id;
                $new->id_child = $fragment->id;
                $new->deletions = $dels;
                $new->save();
            }
        }
    }
}
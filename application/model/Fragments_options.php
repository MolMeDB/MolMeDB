<?php

/**
 * Holds info about molecule fragment options
 * 
 * @property int $id
 * @property int $id_parent
 * @property Fragments $parent
 * @property int $id_child
 * @property Fragments $child
 * @property string $deletions
 * 
 * @author Jakub Juracka
 */
class Fragments_options extends Db
{
    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'fragments_options';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_parent' => array
        (
            'var' => 'parent',
            'class' => 'Fragments'
        ),
        'id_child' => array
        (
            'var' => 'child',
            'class' => 'Fragments'
        ),
    );

    /**
     * Instance builder
     * 
     * @return Fragments_options
     */
    public static function instance()
    {
        return new Fragments_options();
    }

    /**
     * Returns all options for given fragment
     * 
     * @param int $id_fragment
     * @param bool $exclude bigger - Finds only smaller or equal fragments
     * 
     * @return int[]
     */
    public function get_all_options_ids($id_fragment, $exclude_bigger = false)
    {
        $all = [$id_fragment];
        $c1 = 0;
        $c2 = count($all);

        while($c1 != $c2)
        {
            $c1 = count($all);

            if($exclude_bigger)
            {
                $t = $this->queryAll('
                    SELECT id_child, 0 as id_parent
                    FROM fragments_options
                    WHERE id_parent IN ("' . implode('","', $all) . '")
                ');

                $t2 = arr::get_values($t, 'id_child');  

                $all = arr::union($all, $t2);
                $all = array_unique($all);
            }
            else
            {
                $t = $this->queryAll('
                    SELECT id_parent, id_child
                    FROM fragments_options
                    WHERE id_child IN ("' . implode('","', $all) . '") OR id_parent IN ("' . implode('","', $all) . '")
                ');

                $t1 = arr::get_values($t, 'id_parent');
                $t2 = arr::get_values($t, 'id_child');  

                $all = arr::union($all, $t1, $t2);
                $all = array_unique($all);
            }
            
            $c2 = count($all);
        }

        return array_unique($all);
    }

     /**
     * Checks, if given smiles are mutual fragment options
     * 
     * @param string $smiles_1
     * @param string $smiles_2
     * @param bool - Should be smiles at first canonized?
     * 
     * @return boolean
     */
    public function is_option($smiles_1, $smiles_2, $canonize = false)
    {
        if($canonize)
        {
            $rdkit = new Rdkit();
            $smiles_1 = $rdkit->canonize_smiles($smiles_1);
            $smiles_2 = $rdkit->canonize_smiles($smiles_2);
        }

        if(!$smiles_1 || !$smiles_2)
        {
            return false;
        }

        $fragment_1 = Fragments::instance()->where('smiles LIKE', $smiles_1)->get_one();
        $fragment_2 = Fragments::instance()->where('smiles LIKE', $smiles_2)->get_one();

        if(!$fragment_1->id || !$fragment_2->id)
        {
            return false;
        }

        $f1_options = $this->get_all_options_ids($fragment_1->id);

        return in_array($fragment_2->id, $f1_options);
    }
}
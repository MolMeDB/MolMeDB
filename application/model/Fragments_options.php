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
     * 
     * @return int[]
     */
    public function get_all_options_ids($id_fragment)
    {
        $all = [$id_fragment];

        $t = $this->queryAll('
            SELECT id_parent, id_child
            FROM fragments_options
            WHERE id_child = ? OR id_parent = ?
        ', array($id_fragment, $id_fragment));

        foreach($t as $r)
        {
            $all[] = $r->id_child;
            $all[] = $r->id_parent;
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
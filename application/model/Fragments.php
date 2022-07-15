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
    /** MAX FRAGMENT SIZE (number of chars) */
    const MAX_SIZE = 1023;

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
     * Instance builder
     * 
     * @return Fragments
     */
    public static function instance()
    {
        return new Fragments();
    }

    /**
     * Adds hydrogens to current fragment
     * 
     * @return string
     */
    public function add_hydrogens()
    {
        if(!$this->smiles)
        {
            return;
        }

        return preg_replace('/\[\*:\]/', '[H]', $this->smiles);
    }

    /**
     * Returns parent without links
     * 
     * @return Fragments
     */
    public function remove_links()
    {
        $candidates = Fragments_options::instance()->where('id_parent', $this->id)->get_all();

        foreach($candidates as $c)
        {
            if(!preg_match('/\[\*:\]/', $c->child->smiles))
            {
                return $c->child;
            }
        }

        return $this;
    }

    /**
     * Returns total count of atoms in fragment
     * 
     * @return int
     */
    public function get_atom_count()
    {
        $atoms = Periodic_table::atoms_short(true);

        $t = $atoms;

        foreach($t as $key => $el)
        {
            $atoms[$key] = strlen($el) > 1 ? "\[$el\]" : $el;
        }

        $regexp = '(' . implode('|', $atoms) . '){1}';

        $smiles = $this->smiles;

        if(preg_match_all("/$regexp/i", $smiles, $matches))
        {
            return count($matches[0]);
        }

        return 0;
    }

    /**
     * Returns functional group of current fragment if exists
     * 
     * @param int $id_fragment
     * 
     * @return Enum_types|null
     */
    public function get_functional_group($id_fragment = null, $strict = true)
    {
        if(!$id_fragment && $this->id)
        {
            $id_fragment = $this->id;
        }

        if(!$id_fragment)
        {
            return null;
        }

        if($strict)
        {
            $fet = Fragments_enum_types::instance()->where('id_fragment', $id_fragment)->get_one();
        }
        else
        {
            $variants = Fragments_options::instance()->get_all_options_ids($id_fragment);

            $fet = Fragments_enum_types::instance()->in('id_fragment', $variants)->select_list('id_enum_type, id')->distinct()->get_one();
        }

        return $fet->id ? $fet->enum_type : null;
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
     * Fills numbers to fragment links
     * 
     * @param string $smiles
     * @param array|null $link_numbers
     * 
     * @return string
     */
    public function fill_link_numbers($smiles, $link_numbers = null)
    {
        if(preg_match_all('/\[\*:\]/', $smiles, $all))
        {
            $all = $all[0];
            
            if(is_array($link_numbers) && count($all) !== count($link_numbers))
            {
                return $smiles;
            }

            if(!$link_numbers)
            {
                $link_numbers = range(1, count($all));
            }

            foreach($link_numbers as $l)
            {
                $smiles = preg_replace('/\[\*:\]/', "[$l:]", $smiles, 1);
            }
        }

        return $smiles;
    }


    /**
     * Overloading of save function
     */
    public function save()
    {
        if(strlen($this->smiles) > self::MAX_SIZE)
        {
            throw new MmdbException('Cannot save fragment. Smiles size exceede allowed limit [' . self::MAX_SIZE . '].');
        }

        $exists = $this->where('smiles LIKE', $this->smiles)->get_one();

        if($exists->id)
        {
            parent::__construct($exists->id);
            return;
        }

        parent::save();

        // After adding new fragment, create options
        if($this->id)
        {
            $new = new Fragments_options();
            $new->id_parent = $this->id;
            $new->id_child = $this->id;
            $new->deletions = NULL;
            $new->save();

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
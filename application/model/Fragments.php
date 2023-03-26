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
     * Checks, if fragment has neutral charge
     * 
     * @return bool
     */
    public function is_neutral($smiles = NULL)
    {
        if(!$smiles && $this && $this->id)
        {
            $smiles = $this->smiles;
        }

        if(!$smiles)
        {
            return false;
        }

        return preg_match('/[+-]+/', $smiles) !== false;
    }

    /**
     * Returns charge of molecule
     * 
     * @return bool
     */
    public function get_charge($smiles = NULL)
    {
        if(!$smiles && $this && $this->id)
        {
            $smiles = $this->smiles;
        }

        if(!$smiles)
        {
            return false;
        }

        preg_match_all('/\[.*([+-]+\d*)\]/U', $smiles, $output);

        $total = 0;

        if(count($output) > 1)
        {
            $d = $output[1];

            foreach($d as $c)
            {
                if(preg_match('/[+-]\d+/', $c))
                {
                    $total += intval($c);
                }
                else
                {
                    $total += substr_count($c, '+') - substr_count($c, '-');
                }
            }
        }

        return $total;
    }

    /**
     * Returns all options for given fragment
     * 
     * @param int $id_fragment
     * 
     * @return int[]
     */
    public function get_all_options_ids($id_fragment = null)
    {
        if(!$id_fragment && $this->id)
        {
            $id_fragment = $this->id;
        }

        if(!$id_fragment)
        {
            return null;
        }

        return Fragments_options::instance()->get_all_options_ids($id_fragment);
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
     * Returns substance which has validated current fragment
     * 
     * @return Substances
     */
    public function assigned_compound()
    {
        if(!$this || !$this->id)
        {
            return new Substances();
        }

        $r = Substances_fragments::instance()->where(array
            (
                'id_fragment' => $this->id,
                'total_fragments' => 1
            ))
            ->get_one();

        return new Substances($r && $r->id ? $r->id_substance : NULL);
    }

    /**
     * Prepares fragment
     * 
     * @param string $smiles
     * 
     * @return object|null
     */
    public function prepare_fragment($smiles = null)
    {
        if(!$smiles && $this->smiles)
        {
            $smiles = $this->smiles;
        }
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
     * @param array|string|null $link_numbers
     * 
     * @return string
     */
    public function fill_link_numbers($smiles, $link_numbers = null)
    {
        if(preg_match_all('/\[\*:\]/', $smiles, $all))
        {
            $all = $all[0];

            if(!is_array($link_numbers))
            {
                $link_numbers = explode(',', $link_numbers);
            }
            
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
     * Joins fragments
     * - is based on corresponding link numbers
     * 
     * @param string[] $smiles_list
     * 
     * @return string|null - Null if cannot join
     */
    public static function join_by_links($smiles_list, $canonize = true)
    {
        if(!count($smiles_list))
        {
            return '';
        }

        $rdkit = new Rdkit();

        $max_required = 0;
        $allowed_in_run = 9;

        $list_clear = [];

        foreach($smiles_list as $smi)
        {
            $list_clear[] = preg_replace('/\[[\*0-9]+:\]/', '', $smi);
            if(preg_match_all('/\[([0-9]+):\]/', $smi, $all))
            {
                $all = $all[1];
                $max_required = $max_required < arr::max($all) ? arr::max($all) : $max_required;
            }
        }

        $max = 0;

        foreach($list_clear as $smi)
        {
            if(preg_match_all('/[0-9]{1}/', $smi, $all))
            {
                $all = $all[0];
                $max = $max < arr::max($all) ? arr::max($all) : $max;
            }
        }

        $allowed_in_run -= $max;

        $rounds = $max_required/$allowed_in_run;
        $rounds = $rounds == (int)$rounds ? $rounds : (int)($rounds) + 1;

        $current = 9-$allowed_in_run+1;

        if($current > 9)
        {
            return null;
        }

        while(count($smiles_list) > 1)
        {
            $smi = array_shift($smiles_list);

            if(!preg_match_all('/\[([0-9]+):\]/', $smi, $all))
            {
                return null;
            }

            $all = $all[0];
            $found = false;
            $l = 1;

            foreach($all as $a)
            {
                $t = $smiles_list;
                $k_arr = $a;

                foreach($t as $key => $smi2)
                {
                    if(strpos($smi2, $k_arr, 1) === false)
                    {
                        continue;
                    }

                    $found = true;
                    $smiles_list[$key] = str_replace($k_arr, "$current", $smi, $l) . "." . str_replace($k_arr, "$current", $smi2, $l);
                    $smiles_list[$key] = preg_replace('/(\(([0-9]+)\))/', '$2', $smiles_list[$key]);

                    if($current == 9)
                    {
                        $smiles_list[$key] = $rdkit->canonize_smiles($smiles_list[$key]);
                        $current = 0;  
                    }
                    
                    $current++;
                    break 2;
                }
            }

            if(!$found)
            {
                return null;
            }
        }


        return $canonize ? $rdkit->canonize_smiles(array_shift($smiles_list)) : array_shift($smiles_list);
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

        // Match functional groups
        if($this->id)
        {
            $func_groups = Enum_types::get_by_type(Enum_types::TYPE_FRAGMENT_CATS);

            if(!$func_groups)
            {
                return;
            }

            foreach($func_groups as $fg)
            {
                if($fg->content === NULL || $fg->content == "")
                {
                    continue;
                }

                if(preg_match("/$fg->content/", $this->smiles))
                {
                    $new = new Fragments_enum_types();

                    $new->id_fragment = $this->id;
                    $new->id_enum_type = $fg->id;

                    $new->save();
                }
            }
        }

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
<?php

/**
 * Method model
 * 
 * @property integer $id
 * @property integer $type
 * @property string $name
 * @property string $description
 * @property string $references
 * @property string $keywords
 * @property string $CAM
 * @property string $idTag
 * @property integer $user_id
 * @property datetime createDateTime
 * @property datetime $editDateTime
 * 
 */
class Methods extends Db
{
    /** Method specical types */
    const PUBCHEM_LOGP_TYPE = 1;
    const CHEMBL_LOGP_TYPE = 2;

    /** Valid types */
    private static $valid_types = array
    (
        self::PUBCHEM_LOGP_TYPE,
        self::CHEMBL_LOGP_TYPE,
    );

    /**
     * Method constructor
     * 
     * @param integer $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'methods';
        parent::__construct($id);
    }

    /**
     * Instance
     * 
     * @return Methods
     */
    public static function instance()
    {
        return parent::instance();
    }

    /**
     * Returns method by type
     * 
     * @param int $type
     * 
     * @return Methods
     */
    public function get_by_type($type)
    {
        if(!in_array($type, self::$valid_types))
        {
            return NULL;
        }

        $mem = $this->where('type', $type)
            ->get_one();

        return $mem->id ? $mem : NULL;
    }
    

    /**
     * Returns methods linked to given category
     * 
     * @param int $enum_type_link_id
     * 
     * @return Iterable_object
     */
    public function get_linked_data($enum_type_link_id)
    {
        return $this->queryAll('
            SELECT m.*
            FROM methods m
            JOIN method_enum_type_links l ON l.id_method = m.id AND l.id_enum_type_link = ?
        ', array($enum_type_link_id));
    }

    /**
     * Loads search whisper for API response
     * 
     * @param string $q - QUERY
     * 
     * @return array
     */
    public function loadSearchWhisper($q)
    {
        $data = $this->queryAll('
                SELECT DISTINCT * FROM
                    (SELECT DISTINCT id, name, "" as pattern
                    FROM methods
                    WHERE name LIKE ?

                    UNION

                    SELECT m.id, m.name, CONCAT("category: ",et2.name, " > ", et1.name) as pattern
                    FROM methods m
                    JOIN method_enum_type_links as mtl ON mtl.id_method = m.id
                    JOIN enum_type_links as etl ON etl.id = mtl.id_enum_type_link
                    JOIN enum_types et1 ON et1.id = etl.id_enum_type
                    JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
                    WHERE et1.name LIKE ? OR et2.name LIKE ?

                    UNION

                    SELECT id, name, description as pattern
                    FROM methods
                    WHERE description LIKE ?

                    UNION

                    SELECT id, name, keywords as pattern
                    FROM methods
                    WHERE keywords LIKE ?) as tab
                ', array($q, $q, $q, $q, $q));

        $res = array();
        $used = array();

        foreach($data as $row)
        {
            if(in_array($row['id'], $used))
                    continue;

            // Remove HTML tags
            $additional = strip_tags($row['pattern']);

            // Remove special chars
            $additional = str_replace(array("\n","\r", "\t"), '', $additional);

            $pos = stripos($additional, rtrim(ltrim($q, '%'), '%'));
            $max = strlen($additional);


            if($pos !== false && $max > 80)
            {
                $start = $pos <= 20 ? 0 : $pos - 40;
                $len = $pos + 80 >= $max ? $max : 80;

                $pos = $start;

                $additional = '...' . substr($additional, $start, $len) . '...';
            }
            elseif($additional != '')
            {
                $additional = substr($additional, 0, 80) . '...';
            }

            $res[] = array
            (
                    'name'          => $row['name'],
                    'pattern'       => $additional,
            );

            $used[] = $row['id'];
        }

        return $res;
    }

    /**
     * Returns count of interactions for given method/all methods
     * 
     * @param integer $id - method ID
     * 
     * @return array
     */
    public function get_interactions_count($id = NULL)
    {
        if($id)
        {
            $this->queryOne('
                SELECT id_method as id, m.name, COUNT(*) as count
                FROM interaction i
                JOIN methods as m ON m.id = i.id_method
                WHERE id_method = ?
                ORDER BY name ASC
            ', array($id));
        }
        else
        {
            return $this->queryAll('
                SELECT id_method as id, m.name, COUNT(id_method) as count
                FROM interaction i
                JOIN methods as m ON m.id = i.id_method
                GROUP BY (id_method)
                ORDER BY name ASC
            ');
        }
    }

    /**
     * Returns all methods structured by categories
     * 
     * @return array
     */
    public function get_all_structured() : array
    {
        return Enum_types::instance()->get_categories(Enum_types::TYPE_METHOD_CATS);
    }

    /**
     * Returns all methods structured by categories
     * 
     * @return array
     */
    public function get_structured_for_substance(int $id_substance, bool $only_visible = true) : array
    {
        // Get all methods
        $all = Enum_types::instance()->get_categories(Enum_types::TYPE_METHOD_CATS);

        // Filter for just available
        $required = $this->check_avail_by_substance($id_substance, $only_visible)->as_array();
        $required = array_column($required, 'id_method');

        function check_category(array $cat, array $required)
        {
            if(isset($cat['children']) && 
                is_array($cat['children']) && 
                count($cat['children']))
            {
                $res = $cat;

                foreach($cat['children'] as $k => $c)
                {
                    if(($protected_cat = check_category($c, $required)) === false)
                    {
                        unset($res['children'][$k]);
                    }
                    else
                    {
                        $res['children'][$k] = $protected_cat;
                    }
                }

                if(!count($res['children']))
                {
                    return false;
                }
                else
                {
                    // Reload indices
                    $res['children'] = array_values($res['children']);
                    return $res;
                }
            }
            else if(isset($cat['last']) && $cat['last'])
            {
                // Check
                if(!in_array($cat['id_element'], $required))
                {
                    return false;
                }
                
                return $cat;
            }

            // invalid structure
            return false;
        }

        $result = $all;

        // Filtering
        foreach($all as $key => $category)
        {
            if(($protected_cat = check_category($category, $required)) === false)
            {
                unset($result[$key]);
            }
            else
            {
                $result[$key] = $protected_cat;
            }
        }

        return array_values($result);
    }
    
    /**
     * Checks method availability of interaction
     * for given substance - used in API
     * 
     * @param integer $substance_id
     * @param bool $only_visible
     * 
     * @return Iterable_object
     */
    public function check_avail_by_substance(int $substance_id, bool $only_visible = true) : Iterable_object
    {
        $only_visible = $only_visible ? Interactions::VISIBLE : Interactions::INVISIBLE;

        $sql = "
            SELECT COUNT(*) as count, id_method 
            FROM interaction
            WHERE id_substance = ? AND visibility = ?
            GROUP BY id_method";

        return $this->queryAll($sql, array($substance_id, $only_visible));
    }


    /**
     * Returns empty methods
     * 
     * @return Iterable_object
     */
    public function get_empty_methods()
    {
        $data = $this->queryAll('
            SELECT m.id
            FROM methods m
            LEFT JOIN interaction i ON i.id_method = m.id
            WHERE i.id IS NULL
        ');

        $res = [];

        foreach($data as $row)
        {
            $res[] = new Methods($row->id);
        }

        return $res;
    }

    /**
     * Returns enum_type_link for given method
     * 
     * @return Enum_type_links
     */
    public function get_category_link()
    {
        if(!$this->id)
        {
            return null;
        }

        $row = $this->queryOne('
            SELECT etl.*
            FROM method_enum_type_links ml
            JOIN enum_type_links etl ON etl.id = ml.id_enum_type_link AND ml.id_method = ?
        ', array($this->id));

        return new Enum_type_links($row->id);
    }

    /**
     * Set enum_type_link for given method
     * 
     */
    public function set_category_link($link_id)
    {
        if(!$this->id || !$link_id)
        {
            throw new Exception('Invalid instance.');
        }

        return $this->query('
            INSERT INTO `method_enum_type_links` VALUES(?,?);
        ', array($link_id, $this->id));
    }


    /**
     * Returns methods without links
     */
    public function get_all_without_links()
    {
        return $this->queryAll('
            SELECT m.*
            FROM methods m
            LEFT JOIN method_enum_type_links metl ON metl.id_method = m.id
            WHERE metl.id_enum_type_link IS NULL
            ORDER BY name
        ');
    }

    /**
     * Unlink category
     */
    public function unlink_category($id = NULL)
    {
        if(!$id && $this->id)
        {
            $id = $this->id;
        }

        return $this->query('
            DELETE FROM `method_enum_type_links`
            WHERE `id_method` = ?
        ', array($id));
    }

    /**
     * Returns data to selector
     * 
     * @return array
     */
    public function get_all_selector_data()
    {
        $data = $this->queryAll(
            'SELECT m.*, et_cat.name as category, et_subcat.name as subcategory
            FROM methods m
            LEFT JOIN method_enum_type_links metl ON metl.id_method = m.id
            LEFT JOIN enum_type_links etl ON etl.id = metl.id_enum_type_link
            LEFT JOIN enum_types et_cat ON et_cat.id = etl.id_enum_type_parent
            LEFT JOIN enum_types et_subcat ON et_subcat.id = etl.id_enum_type
            ORDER BY category ASC, subcategory ASC'
        );

        $result = [];

        foreach($data as $row)
        {   
            $total = Interactions::instance()->where(array
            (   
                'visibility' => Interactions::VISIBLE,
                'id_method'  => $row->id
            ))->count_all();

            $result[] = array
            (
                'id' => $row->id,
                'name' => $row->name,
                'category' => $row->category ? $row->category . ' --- ' . $row->subcategory : 0,
                'total' => 'Total interactions: ' . $total
            );
        }

        return $result;
    }

    /**
     * Returns membranes with categories
     * 
     * @return Iterable_object
     */
    public function get_active_categories()
    {
        $result = [];

        $data = $this->queryAll('
            SELECT m.id, et_cat.name as category, et_subcat.name as subcategory
            FROM methods m
            LEFT JOIN method_enum_type_links metl ON metl.id_method = m.id
            LEFT JOIN enum_type_links etl ON etl.id = metl.id_enum_type_link
            LEFT JOIN enum_types et_cat ON et_cat.id = etl.id_enum_type_parent
            LEFT JOIN enum_types et_subcat ON et_subcat.id = etl.id_enum_type
        ');

        foreach($data as $row)
        {
            $result[$row->id] = array
            (
                'category' => $row->category,
                'subcategory' => $row->subcategory
            );
        }

        return new Iterable_object($result, TRUE);
    }


     /**
     * Returns all membrane interactions
     * 
     * @return Iterable_object
     * 
     * @author Jakub Juracka
     */
    public function get_interactions()
    {
        return $this->queryAll('
            SELECT DISTINCT s.name, s.identifier, s.SMILES, s.inchikey, s.MW, s.LogP, s.pubchem, s.drugbank, s.pdb, s.chEMBL,
                mem.name as membrane, met.name as method, i.temperature, i.charge, i.comment as note, i.Position as X_min,
                i.Position_acc as X_min_acc, i.Penetration as G_pen, i.Penetration_acc as G_pen_acc, i.Water as G_wat, i.Water_acc as G_wat_acc,
                i.LogK, i.LogK_acc, i.LogPerm, i.LogPerm_acc, i.theta, i.theta_acc, i.abs_wl, i.abs_wl_acc, i.fluo_wl, i.fluo_wl_acc, i.QY, i.QY_acc, i.lt, i.lt_acc,
                p1.citation as primary_reference, p2.citation as secondary_reference
            FROM substances s 
            JOIN interaction i ON i.id_substance = s.id
            JOIN membranes mem ON mem.id = i.id_membrane
            JOIN methods met ON met.id = i.id_method AND met.id = ?
            JOIN datasets d ON d.id = i.id_dataset
            LEFT JOIN publications p1 ON p1.id = i.id_reference
            LEFT JOIN publications p2 ON p2.id = d.id_publication
        ', array($this->id));
    }
        
}

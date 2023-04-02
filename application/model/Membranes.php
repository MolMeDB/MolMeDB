<?php

/**
 * Membrane model
 * 
 * @property integer $id
 * @property integer $id_cosmo_file
 * @property Files $cosmo_file
 * @property integer $type
 * @property string $name
 * @property string $description
 * @property string $references
 * @property string $keywords
 * @property string $CAM
 * @property string $idTag
 * @property integer $user_id
 * @property datetime $createDateTime
 * @property datetime $editDateTime
 * 
 * @property Enum_type_links $enum_type_link
 * 
 * @method Membranes instance
 * 
 */
class Membranes extends Db
{   
    /** Membrane specical types */
    const LOGP_TYPE = 1;

    /** Valid types */
    private static $valid_types = array
    (
        self::LOGP_TYPE
    );

    protected $has_one = array
    (
        'id_cosmo_file' => array
        (
            'var' => 'cosmo_file',
            'class' => 'Files'
        )
    );

    /**
     * Returns membrane by type
     * 
     * @param int $type
     * 
     * @return Membranes
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
     * Returns membrane detail for public
     * 
     * @return array|null
     */
    public function get_public_detail($add_description = False)
    {
        if(!$this->id)
        {
            return null;
        }

        $res = array
        (
            'id' => $this->id,
            'name' => $this->name  
        );

        if($add_description)
        {
            $res['description'] = str_replace('&nbsp', " ", str_replace("\r", '', strip_tags($this->description)));
        }

        return $res;
    }

    /**
     * Membranes constructor
     * 
     * @param integer $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'membranes';
        parent::__construct($id);

        if($this->id)
        {
            $et_id = $this->queryOne('
                SELECT id_enum_type_link as id
                FROM membrane_enum_type_links
                WHERE id_membrane = ?
            ', array($this->id))->id;

            $this->enum_type = new Enum_type_links($et_id);
        }
    }

    /**
     * Returns empty membranes
     * 
     * @return Iterable_object
     */
    public function get_empty_membranes()
    {
        $data = $this->queryAll('
            SELECT m.id
            FROM membranes m
            LEFT JOIN interaction i ON i.id_membrane = m.id
            WHERE i.id IS NULL
        ');

        $res = [];

        foreach($data as $row)
        {
            $res[] = new Membranes($row->id);
        }

        return $res;
    }

    /**
     * Tries to find membrane by doi/pmid/category/id/name 
     * - Can be set category structure also
     * 
     * @return Membranes[]
     */
    public function find_all($query)
    {
        $result = [];

        $by_identifier = new Membranes($query);
        if(!$by_identifier->id)
        {
            $by_identifier = Membranes::instance()->where('name LIKE', $query)->get_one();
        }

        if($by_identifier->id)
        {
            $result = [$by_identifier];
        }
        else
        {
            // Try to find category by path
            $query = 'Membranes.' . $query;
            $cats = explode('.', $query);
            $cats_objects = [];

            foreach($cats as $c)
            {
                $exists = Enum_types::instance()->where(array
                (
                    'name LIKE' => $c,
                    'type'      => Enum_types::TYPE_MEMBRANE_CATS
                ))->get_one();

                if(!$exists->id)
                {
                    ResponseBuilder::not_found('Category `' . $c .'` not found.');
                }

                $cats_objects[] = $exists;
            }

            $current = array_shift($cats_objects);
            $curr_link_id = null;

            // Check category structure validity
            foreach($cats_objects as $c)
            {
                $link = Enum_type_links::instance()->where(array
                (
                    'id_enum_type' => $c->id,
                    'id_enum_type_parent' => $current->id,
                ) + ($curr_link_id ? ['id_parent_link' => $curr_link_id] : []))
                ->get_one();

                if(!$link->id)
                {
                    ResponseBuilder::not_found('Category `' . $c->name . '` is not child of category `' . $current->name . '`');
                }

                $current = $c;
                $curr_link_id = $link->id;
            }

            $link_ids = [];

            // Only one category provided
            if(!$curr_link_id)
            {
                $links = Enum_type_links::instance()->where('id_enum_type_parent', $current->id)->get_all();
                $link_ids = arr::get_values($links, 'id');
                $last_total = 0;

                while($last_total != count($link_ids))
                {
                    $last_total = count($link_ids);
                    $links = Enum_type_links::instance()->in('id_parent_link', $link_ids)->get_all();
                    $link_ids = array_unique(array_merge($link_ids, arr::get_values($links, 'id')));
                }
            }
            else
            {
                $links = Enum_type_links::instance()
                    ->where('id_parent_link', $curr_link_id)
                    ->get_all();
                $link_ids = array_merge(arr::get_values($links, 'id'), [$curr_link_id]);
            }

            if(count($link_ids))
            {
                $result = Membranes::instance()
                    ->join('JOIN membrane_enum_type_links etl ON etl.id_membrane = membranes.id')
                    ->in('etl.id_enum_type_link', $link_ids)
                    ->get_all();
            }
        }
        return $result;
    }

    /**
     * Returns membranes linked to given category
     * 
     * @param int $enum_type_link_id
     * 
     * @return Iterable_object
     */
    public function get_linked_data($enum_type_link_id)
    {
        return $this->queryAll('
            SELECT m.*
            FROM membranes m
            JOIN membrane_enum_type_links l ON l.id_membrane = m.id AND l.id_enum_type_link = ?
        ', array($enum_type_link_id));
    }

    /**
     * Returns enum_type_link for given membrane
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
            FROM membrane_enum_type_links ml
            JOIN enum_type_links etl ON etl.id = ml.id_enum_type_link AND ml.id_membrane = ?
        ', array($this->id));

        return new Enum_type_links($row->id);
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
            FROM membranes m
            LEFT JOIN membrane_enum_type_links metl ON metl.id_membrane = m.id
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
                'id_membrane' => $row->id,
                'visibility'  => Interactions::VISIBLE
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
     * Set enum_type_link for given membrane
     * 
     */
    public function set_category_link($link_id)
    {
        if(!$this->id || !$link_id)
        {
            throw new Exception('Invalid instance.');
        }

        return $this->query('
            INSERT INTO `membrane_enum_type_links` VALUES(?,?);
        ', array($link_id, $this->id));
    }

    /**
     * Returns membranes without links
     */
    public function get_all_without_links()
    {
        return $this->queryAll('
            SELECT m.*
            FROM membranes m
            LEFT JOIN membrane_enum_type_links metl ON metl.id_membrane = m.id
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
            DELETE FROM `membrane_enum_type_links`
            WHERE `id_membrane` = ?
        ', array($id));
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
            FROM membranes m
            LEFT JOIN membrane_enum_type_links metl ON metl.id_membrane = m.id
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
     * Loads whisper detail
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
                    FROM membranes
                    WHERE name LIKE ?

                    UNION

                    SELECT m.id, m.name, CONCAT("category: ",et2.name, " > ", et1.name) as pattern
                    FROM membranes m
                    JOIN membrane_enum_type_links as mtl ON mtl.id_membrane = m.id
                    JOIN enum_type_links as etl ON etl.id = mtl.id_enum_type_link
                    JOIN enum_types et1 ON et1.id = etl.id_enum_type
                    JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
                    WHERE et1.name LIKE ? OR et2.name LIKE ?
                    
                    UNION
                    
                    SELECT id, name, description as pattern
                    FROM membranes
                    WHERE description LIKE ?
                    
                    UNION
                    
                    SELECT id, name, keywords as pattern
                    FROM membranes
                    WHERE keywords LIKE ?) as tab
                ', array($q, $q, $q, $q, $q));
        
        $res = array();
        $used = array();
        
        foreach($data as $row)
        {
            if(in_array($row['id'], $used))
            {
                    continue;
            }

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
                'pattern'   => $additional,
            );
            
            $used[] = $row['id'];
        }
        
        return $res;
    }
    
    /**
     * Returns count of interactions for given membrane/all membranes
     * 
     * @param integer $id - Membrane ID
     * 
     * @return array
     */
    public function get_interactions_count($id = NULL)
    {
        if($id)
        {
            $this->queryOne('
                SELECT id_membrane as id, m.name, COUNT(*) as count
                FROM interaction i
                JOIN membranes as m ON m.id = i.id_membrane
                WHERE id_membrane = ?
                ORDER BY name ASC
            ', array($id));
        }
        else
        {
            return $this->queryAll('
                SELECT id_membrane as id, m.name, COUNT(id_membrane) as count
                FROM interaction i
                JOIN membranes as m ON m.id = i.id_membrane
                GROUP BY (id_membrane)
                ORDER BY name ASC
            ');
        }
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
            JOIN membranes mem ON mem.id = i.id_membrane AND mem.id = ?
            JOIN methods met ON met.id = i.id_method
            JOIN datasets d ON d.id = i.id_dataset
            LEFT JOIN publications p1 ON p1.id = i.id_reference
            LEFT JOIN publications p2 ON p2.id = d.id_publication
        ', array($this->id));
    }

}

<?php

/**
 * Membrane model
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
 * @property datetime $createDateTime
 * @property datetime $editDateTime
 * 
 * @property Enum_type_links $enum_type_link
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
                    
                    SELECT id, name, description as pattern
                    FROM membranes
                    WHERE description LIKE ?
                    
                    UNION
                    
                    SELECT id, name, keywords as pattern
                    FROM membranes
                    WHERE keywords LIKE ?
                    
                    UNION
                    
                    SELECT m.id, m.name, CONCAT(cm.name, " - ", csm.name) as pattern
                    FROM membranes m
                    LEFT JOIN cat_mem_mem cmm ON cmm.mem_id = m.id
                    LEFT JOIN cat_membranes cm ON cm.id = cmm.cat_id
                    LEFT JOIN cat_subcat_membranes csm ON csm.id = cmm.subcat_id
                    WHERE cm.name LIKE ? OR csm.name LIKE ?) as tab
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

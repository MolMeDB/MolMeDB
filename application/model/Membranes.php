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
    public function get_linked_membranes($enum_type_link_id)
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
    public function get_membranes_without_links()
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
     * 
     */

    // /**
    //  * Returns membrane category
    //  * 
    //  * @param integer $id
    //  * 
    //  * @return Iterable
    //  */
    // public function get_category($id = NULL)
    // {
    //     if(!$id)
    //     {
    //             $id = $this->id;
    //     }

    //     return $this->queryOne('
    //         SELECT m.*, cat.name as category, subcat.name as subcategory
    //         FROM membranes m
    //         LEFT JOIN cat_mem_mem cm ON cm.mem_id = m.id
    //         LEFT JOIN cat_membranes cat ON cat.id = cm.cat_id
    //         LEFT JOIN cat_subcat_membranes subcat ON subcat.id = cm.subcat_id
    //         WHERE m.id = ?',
    //             array($id));
    // }
    
    // // public function search($data, $pagination)
    // // {
    // //     $data = '%' . $data . '%';
    // //     return $this->queryAll('
    // //      SELECT DISTINCT name, identifier, s.id, SMILES, MW, pubchem, drugbank, chEBI, pdb, chEMBL
    // //      FROM substances as s 
    // //      JOIN interaction as i ON s.idSubstance = i.idSubstance 
    // //      WHERE i.idMembrane IN (SELECT idMembrane 
    // //                                       FROM membranes as m 
    // //                                       WHERE m.name LIKE ?)
    // //            AND i.visibility = 1
    // //      ORDER BY IF(name RLIKE "^[a-z]", 1, 2), name
    // //      LIMIT ?,?', array($data, ($pagination-1)*10,10));
    // // }
    
    // /**
    //  * Returns empty membranes
    //  * 
    //  * @return Iterable_object
    //  */
    // public function get_empty_membranes()
    // {
    //     $data = $this->queryAll('
    //         SELECT m.id
    //         FROM membranes m
    //         LEFT JOIN interaction i ON i.id_membrane = m.id
    //         WHERE i.id IS NULL
    //     ');

    //     $res = [];

    //     foreach($data as $row)
    //     {
    //         $res[] = new Membranes($row->id);
    //     }

    //     return $res;
    // }


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

    // /**
    //  * Inserts membrane category
    //  * 
    //  * @param integer $cat - Category ID
    //  * @param integer $subcat - Subcategory ID
    //  * 
    //  */
    // public function save_membrane_category($cat, $subcat)
    // {    
    //     if(!$this->id)
    //     {
    //         throw new Exception('Wrong membrane instance.');
    //     }

    //     return $this->insert('cat_mem_mem', array
    //     (
    //         'cat_id' => $cat, 
    //         'subcat_id' => $subcat, 
    //         "mem_id" => $this->id
    //     ));
    // }
    
    // /**
    //  * Gets all membrane categories
    //  * 
    //  * @return Iterable
    //  */
    // public function get_all_categories()
    // {
    //     return $this->queryAll('SELECT * FROM cat_membranes ORDER BY name');
    // }
    
    // /**
    //  * Gets all membrane subcategories
    //  * 
    //  * @return Iterable
    //  */
    // public function get_all_subcategories()
    // {
    //     return $this->queryAll('SELECT id, name FROM cat_subcat_membranes');
    // }
    
    // /**
    //  * Gets membranes for given categories
    //  * 
    //  * @param integer $cat_id Category ID
    //  * @param integer $subcat_id Subcategory ID      
    //  */
    // public function get_membranes_by_categories($cat_id, $subcat_id)
    // {
    //     return $this->queryAll('
    //         SELECT mem_id as id, m.name as name
    //         FROM cat_mem_mem
    //         JOIN membranes as m ON mem_id = m.id
    //         WHERE cat_id = ? AND subcat_id = ?', 
    //         array
    //         (
    //             $cat_id, 
    //             $subcat_id
    //         ));
    // }
    
    // public function get_membrane_category($idMembrane)
    // {
    //         return $this->queryAll('
    //             SELECT cat_id, subcat_id
    //             FROM cat_mem_mem
    //             WHERE mem_id = ?
    //             LIMIT 1', array($idMembrane));
    // }
    
    // /**
    //  * Edit membrane category
    //  * 
    //  * @param integer $idCat - category id
    //  * @param integer $idSubcat - subcategory id
    //  * 
    //  */
    // public function editCategory($idCat, $idSubcat)
    // {
    //     if(!$this)
    //     {
    //         throw new Exception('Wrong instance.');
    //     }

    //     // Delete old record
    //     $this->query('DELETE FROM `cat_mem_mem` WHERE `mem_id` = ?', array($this->id));
        
    //     // Save new record
    //     return $this->insert('cat_mem_mem', array
    //     (
    //         'cat_id'    => $idCat,
    //         'subcat_id' => $idSubcat,
    //         'mem_id'    => $this->id
    //     ));
    // }
    

    /**
     * Returns membranes for given params
     * 
     * @param array $substances_ids
     * @param array $methods_ids
     * 
     * @return array
     */
    // public function get_membranes_by_params($substances_ids = array(), $methods_ids = array())
    // {
    //      if(empty($substances_ids) && empty($methods_ids))
    //      {
    //         return $this->queryAll('SELECT id, name FROM membranes ORDER BY name');
    //      }
         
    //      if(empty($methods_ids))
    //      {
    //         return $this->queryAll('
    //             SELECT DISTINCT m.id, m.name
    //             FROM interaction i
    //             JOIN membranes m ON (m.id = i.id_membrane)
    //             WHERE i.id_substance IN (' . implode(',', $substances_ids) . ')');
    //      }
         
    //      if(empty($substances_ids))
    //      {
    //         return array();
    //      }
         
    //      return $this->queryAll('
    //         SELECT DISTINCT m.id, m.name
    //         FROM interaction i
    //         JOIN membranes m ON (m.id = i.id_membrane)
    //         WHERE i.id_substance IN (' . implode(',', $substances_ids) . ')
    //         AND i.id_method IN (' . implode(',', $methods_ids) . ')' 
    //         );
    // }
}

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

                    SELECT id, name, description as pattern
                    FROM methods
                    WHERE description LIKE ?

                    UNION

                    SELECT id, name, keywords as pattern
                    FROM methods
                    WHERE keywords LIKE ?) as tab
                ', array($q, $q, $q));

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
     * Checks method availability of interaction
     * for given substance - used in API
     * 
     * @param integer $substance_id
     * 
     * @return Iterable
     */
    public function check_avail_by_substance($substance_id)
    {
        $sql = "
            SELECT COUNT(*) as count, id_method 
            FROM interaction
            WHERE id_substance = ? AND visibility = 1 
            GROUP BY id_method";

        return $this->queryAll($sql, array($substance_id));
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
    public function get_methods_without_links()
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
     * REST API function
     * Gets method for given params
     * 
     * @param array $substance_ids
     * @param array $membranes_ids
     */
    // public function get_methods_by_params($substances_ids = array(), $membranes_ids = array())
    // {
    //     if(empty($substances_ids) && empty($membranes_ids))
    //     {
    //         return $this->queryAll('SELECT id, name FROM methods ORDER BY name');
    //     }
        
    //     if(empty($membranes_ids))
    //     {
    //         return $this->queryAll('
    //             SELECT DISTINCT m.id, m.name
    //             FROM interaction i
    //             JOIN methods m ON (m.id = i.id_method)
    //             WHERE i.id_substance IN (' . implode(',', $substances_ids ) . ')');
    //     }
        
    //     if(empty($substances_ids))
    //     {
    //         return array();
    //     }
        
    //     return $this->queryAll('
    //         SELECT DISTINCT m.id, m.name
    //         FROM interaction i
    //         JOIN methods m ON (m.id = i.id_method)
    //         WHERE i.id_substance IN (' . implode( ',', $substances_ids) . ')
    //         AND i.id_membrane IN (' . implode(',', $membranes_ids) . ')' );
    // }
        
}

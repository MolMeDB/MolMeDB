<?php

/**
 * Method model
 * 
 * @property integer $id
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
    
    public function insert_method($name, $description, $keywords, $CAM, $references)
    {
        $userManager = new UserManager();
        $user = $userManager->returnUser();
        
        $idTag = strlen($CAM) < 3 ? str_replace(" ","_",$name) : str_replace(" ","_",$CAM);
        
        $parametres = array('name' => $name, 'description' => $description, 'keywords' => $keywords, 
            'CAM' => $CAM, 'user_id' => $user['id'], 'references' => $references, 'idTag' => $idTag);
        
        return $this->insert("methods", $parametres);
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

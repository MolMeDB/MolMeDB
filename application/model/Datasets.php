<?php

/**
 * Dataset model
 * 
 * @property integer $id
 * @property integer $type
 * @property integer $visibility
 * @property string $name
 * @property integer $id_membrane
 * @property integer $id_method
 * @property integer $id_publication
 * @property integer $id_user_upload
 * @property integer $id_user_edit
 * @property datetime $lastUpdateDatetime
 * @property datetime $createDateTime
 * 
 * @property Membranes $membrane
 * @property Methods $method
 * @property Publications $publication
 * @property Users $author
 * @property Users $editor
 */
class Datasets extends Db
{
    /** VISIBILITY CONSTANTS */
	const VISIBLE = 1;
    const INVISIBLE = 2;
    
    /** TYPES */
    const PUBCHEM = 1;
    const CHEMBL = 2;

	private $enum_visibilities = array
	(
		self::VISIBLE => 'Visible',
		self::INVISIBLE => 'Invisible'
    );
    
    /** Valid types */
    private static $valid_types = array
    (
        self::PUBCHEM,
        self::CHEMBL
    );

    /** Foreign keys to other tables */
    protected $has_one = array
    (
        'id_membrane',
        'id_method',
        'id_publication',
        'id_user_upload' => array
        (
            'var' => 'author',
            'class' => 'Users'
        ),
        'id_user_edit' => array
        (
            'var' => 'editor',
            'class' => 'Users'
        )
    );

    /**
     * Returns count of interactions assigned to the dataset
     * 
     * @param int $dataset_id
     * 
     * @return int
     */
    public function get_count_interactions($dataset_id = NULL)
    {
        if(!$dataset_id && !$this->id)
        {
            return NULL;
        }

        if(!$dataset_id)
        {
            $dataset_id = $this->id;
        }

        $total = $this->queryOne('
            SELECT COUNT(id) as count
            FROM interaction
            WHERE id_dataset = ?
        ', array($dataset_id))->count;

        return $total ? $total : 0;
    }


    /**
     * Get dataset by type
     * 
     * @param int $type
     * 
     * @return Datasets
     */
    public function get_by_type($type)
    {
        if(!in_array($type, self::$valid_types))
        {
            return new Iterable_object([]);
        }

        $obj = $this->where('type', $type)
            ->get_one();

        return $obj->id ? new Datasets($obj->id) : null;
    }

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'datasets';
        parent::__construct($id);
    }

    /**
     * Get duplicate datasets
     * 
     * @return array - Returns dataset IDS grouped by duplicities
     */
    public function get_duplicites()
    {
        $dps = $this->queryAll('
            SELECT id_membrane, id_method, id_publication, DATE(createDateTime) as date,id_user_upload, COUNT(id) count
            FROM datasets
            GROUP BY id_membrane, id_method, id_publication, date, id_user_upload
            HAVING count > 1
        ');

        $res = array();
        $proccessed = array();

        $all_datasets = $this->order_by('id', 'ASC')->get_all();

        for($i = 0; $i < count($all_datasets); $i++)
        {
            $d1 = $all_datasets[$i];
            $d_ids = [$d1->id];

            if(in_array($d1->id, $proccessed))
            {
                continue;
            }

            for($k = $i+1; $k < count($all_datasets); $k++)
            {
                $d2 = $all_datasets[$k];

                if($d2->id_membrane == $d1->id_membrane && 
                    $d2->id_method == $d1->id_method &&
                    $d2->id_publication == $d1->id_publication &&
                    $d2->id_user_upload == $d1->id_user_upload)
                {
                    $d_ids[] = $d2->id;
                }
                else
                {
                    break;
                }
            }

            if(count($d_ids) > 1)
            {
                $res[] = $d_ids;
                $proccessed = array_merge($proccessed, $d_ids);
            }
        }

        foreach($dps as $d)
        {
            $data = $this->queryAll('
                SELECT id
                FROM datasets
                WHERE id_membrane = ? AND id_publication = ? AND id_method = ? AND
                    DATE(createDateTime) = ? AND id_user_upload = ?
            ', array($d->id_membrane, $d->id_publication, $d->id_method, $d->date, $d->id_user_upload));

            $ids = [];

            foreach($data as $row)
            {
                $ids[] = $row->id;
            }
            
            if(count($ids))
            {
                $flag = TRUE;

                for($i = 0; $i < count($res); $i++)
                {
                    $intersection = array_intersect($res[$i], $ids);

                    if(count($intersection) > 0)
                    {
                        $flag = FALSE;
                        $res[$i] = array_unique(array_merge($res[$i], $ids));
                        break;
                    }
                }

                if($flag)
                {
                    $res[] = $ids;
                }
            }
        }

        return $res;
    }

    /**
	 * Gets enum visibility by const number
	 * 
	 * @param integer $visibility
	 * 
	 * @return string
	 */
	public function get_enum_visibility($visibility = NULL)
	{
		if(!$visibility && !$this)
		{
			return '';
		}

		if(!$visibility)
		{
			$visibility = $this->visibility;
		}

		if(!array_search($visibility, $this->enum_visibilities))
		{
			return $this->enum_visibilities[$visibility];
		}

		return '';
    }  
    
    /**
     * Returns empty datasets
     * 
     * @return Iterable_object
     */
    public function get_empty_datasets()
    {
        $data = $this->queryAll('
            SELECT id
            FROM datasets
            WHERE id NOT IN
                (SELECT DISTINCT id_dataset
                FROM interaction)
        ');

        $res = [];

        foreach($data as $row)
        {
            $res[] = new Datasets($row->id);
        }

        return $res;
    }
    
    
    /**
     * Gets access right for current dataset
     * 
     * @param boolean $group - Flag for getting groups access rights
     * 
     * @return array
     */
    public function get_rights($group = false)
    {
        if(!$this->id)
        {
            return NULL;
        }

        $id = $this->id;

        $ids_users = $this->queryAll('SELECT id_user FROM access_ds_usr WHERE id_dataset = ?', array($id));
        $ids_users_by_groups = $this->queryAll('SELECT `gp_usr`.`id_user` FROM `access_ds_gp` JOIN `gp_usr` ON `id_gp` = `id_group` WHERE `id_dataset` = ?', array($id));
        $users_all = $this->queryAll('SELECT name, id FROM users');
        
        $rights_type = array('YES','NO');
        $result = array();
        $i = 0;
        
        if(!$group)
        {
            foreach($users_all as $user)
            {
                $result[$i] = array();
                $result[$i]['id'] = $user['id'];
                $result[$i]['name'] = $user['name'];
                
                if($this->item_in_array($ids_users, $user['id']))
                {
                    $result[$i]['access'] = $rights_type[0];
                    $result[$i]['inherited'] = $rights_type[1];
                }
                else if($this->item_in_array($ids_users_by_groups, $user['id']))
                {
                    $result[$i]['access'] = $rights_type[0];
                    $result[$i]['inherited'] = $rights_type[0];
                }
                else 
                {
                    $result[$i]['access'] = $rights_type[1];
                    $result[$i]['inherited'] = $rights_type[1];
                }
                $i++;   
            }
            return $result;
        }
        
        $groups = $this->queryAll('SELECT * FROM groups');
        $access_groups = $this->queryAll('SELECT id_gp FROM access_ds_gp WHERE id_dataset = ?', array($id));
        
        foreach($groups as $group)
        {
            $result[$i] = array();
            $result[$i]['id'] = $group['id'];
            $result[$i]['name'] = $group['gp_name'];
            if($this->item_in_array($access_groups, $group['id']))
            {
                $result[$i]['access'] = $rights_type[0];
            }
            else
            {
                $result[$i]['access'] = $rights_type[1];
            }
            $i++;
        }
        
        return $result;
        
    }
    

    // DELETE
    private function item_in_array($array = array(), $item){
        foreach($array as $i)
            if($i[0] === $item)
                return true;
            
        return false;
    }
    
    
    
    /**
     * Toggle dataset access rights for given user/group
     * 
     * @param integer $id_entity
     * @param string $group
     */
    public function change_rights($id_entity, $group)
    {
        if($group === 'true')
        {
            $check = $this->queryOne('SELECT id_gp FROM access_ds_gp WHERE id_dataset = ? AND id_gp = ?', array($this->id, $id_entity));
            
            if($check->id_gp)
            {
                return $this->query('DELETE FROM `access_ds_gp` WHERE `id_dataset` = ? AND `id_gp` = ?', array($this->id, $id_entity));
            }

            return $this->insert('access_ds_gp', array('id_dataset' => $this->id, "id_gp" => $id_entity));
        }
        else
        {
            $check = $this->queryOne('SELECT id_user FROM access_ds_usr WHERE id_dataset = ? AND id_user = ?', array($this->id, $id_entity));

            if($check->id_user)
            {
                return $this->query('DELETE FROM `access_ds_usr` WHERE `id_dataset` = ? AND `id_user` = ?', array($this->id, $id_entity));
            }

            return $this->insert('access_ds_usr', array('id_dataset' => $this->id, "id_user" => $id_entity));
        }
    }
}

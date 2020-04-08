<?php

/**
 * Dataset model
 * 
 * @property integer $id
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
 * @property UserManager $author
 * @property UserManager $editor
 */
class Datasets extends Db
{
    /** VISIBILITY CONSTANTS */
	const VISIBLE = 1;
	const INVISIBLE = 2;

	private $enum_visibilities = array
	(
		self::VISIBLE => 'Visible',
		self::INVISIBLE => 'Invisible'
	);

    /** Foreign keys to other tables */
    public $has_one = array
    (
        'id_membrane',
        'id_method',
        'id_publication',
        'id_user_upload' => array
        (
            'var' => 'author',
            'class' => 'UserManager'
        ),
        'id_user_edit' => array
        (
            'var' => 'editor',
            'class' => 'UserManager'
        )
    );

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'datasets';
        parent::__construct($id);
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

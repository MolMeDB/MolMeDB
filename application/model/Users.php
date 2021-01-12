<?php

/**
 * User model
 * 
 * @property integer $id
 * @property string $name
 * @property string $password
 * @property bool $guest
 * @property bool $superadmin
 * @property datetime $createDateTime
 * 
 */
class Users extends Db
{
    /**
     * Constructor
     * 
     * @param integer $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'users';
        parent::__construct($id);
    }

    /**
     * Gets hash for given $password
     * 
     * @param string $password
     * 
     * @return string
     */
    public function return_fingerprint($password)
    {
        $salt = 'fd16sdfd2ew#$%';
        return hash('sha256', $password . $salt);
    }

    /**
     * Login function
     * 
     * @param string $name
     * @param string $password
     */
    public function login($name, $password)
    {
        $user = $this->queryOne('
            SELECT name, id, superadmin, guest
            FROM users
            WHERE name = ? AND password = ?', 
            array
            (
                $name, 
                $this->return_fingerprint($password)
            ));

        if(!$user->id)
        {
            throw new ErrorUser('Try again');
        }

        $admin = $this->queryOne('
            SELECT * FROM gp_usr
            WHERE id_user = ? AND id_group = ?', 
            array($user->id, 1));

        $this->saveLog($user['id']);
        
        $_SESSION['user'] = $user->as_array();

        if($admin->id_group)
        {
            $_SESSION['user']['admin'] = True;
        }
        else
        {
            $_SESSION['user']['admin'] = False;
        }
    }
    
    /**
     * Save log about logging/logout user
     * 
     * @param integer $user_id
     * @param bool $log_in
     */
    public function saveLog($user_id, $log_in = True)
    {
        $this->insert('log_login', array('user_id' => $user_id, 'login' => $log_in));
    }
    
    /**
     * Registration
     * 
     * @param string $name
     * @param string $password
     * @param string $password_confirm
     * @param integer $year
     */
    public function sign_up($name, $password, $password_confirm, $year)
    {
        if ($year != date('Y'))
        {
            throw new ErrorUser('Wrong antispam');
        }

        if ($password != $password_confirm)
        {
            throw new ErrorUser('Password doesn\'t match');
        }

        $user = new Users();

        try
        {
            // save new user
            $user->name = $name;
            $user->password = $this->return_fingerprint($password);
            $user->guest = 0;
            $user->superadmin = 0;

            $user->save();
        } 
        catch (PDOException $error) 
        {
            throw new ErrorUser('Wrong username');
        }
    }
    
    /**
     * Logout function
     */
    public function logout()
    {
        $this->saveLog($_SESSION['user']['id'], 0);
        unset($_SESSION['user']);
    }
    
    /**
     * Gets user's detail
     * 
     * @return Users
     */
    public function returnUser()
    {
        if (isset($_SESSION['user']))
        {
            return $_SESSION['user'];
        }
        return null;
    }
    
    /**
     * Gets all groups
     */
    public function get_all_groups()
    {
        return $this->queryAll('SELECT * FROM groups');
    }
    
    /**
     * Gets users for given group ID
     * 
     * @param integer $idGroup
     */
    public function get_users_by_group($idGroup)
    {
        return $this->queryAll('
            SELECT u.id, u.name, IF(g.id_group IS NOT NULL, 1, 0) as gp 
            FROM users u
            LEFT JOIN gp_usr g ON g.id_user = u.id
            WHERE g.id_group = ? OR g.id_group IS NULL 
        ', array($idGroup));
    }
    
    /**
     * Changes access rights
     * 
     * @param integer $idGroup
     * 
     */
    public function toggle_group($idGroup)    
    {
        if(!$this)
        {
            throw new Exception('Wrong instance');
        }

        $ind = $this->queryOne('SELECT id_group FROM gp_usr WHERE id_group = ? AND id_user = ?', array($idGroup, $this->id));

        if($ind->id_group)
        {
            $this->query("DELETE FROM `gp_usr` WHERE `id_group` = ? AND `id_user` = ?", array($idGroup, $this->id));
        }
        else
        { 
            $this->insert('gp_usr', array("id_group" => $idGroup, "id_user" => $this->id));
        }
    }
}

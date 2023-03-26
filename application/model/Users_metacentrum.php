<?php

/**
 * Metacentrum users database
 * 
 * @param int $id
 * @param int $id_user
 * @param Users $user
 * @param string $login
 * @param string $password
 * @param int $enabled
 * 
 * @author Jakub Juračka
 */
class Users_metacentrum extends Db 
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'users_metacentrum';
        parent::__construct($id);
    }

    protected $has_one = array
    (
        'id_user'
    );

    /**
     * Hash password
     */
    public static function hash($password)
    {
        // Check if hash settings exists
        $setting_path = SYS_ROOT . 'hash/';
        $filename = 'PS';

        if(!file_exists($setting_path))
        {
            mkdir($setting_path, 0700, true);
        }

        if(!file_exists($setting_path.$filename))
        {
            $length = random_int(5,20);
            // Create new
            $setting = array
            (
                'pre_salt'  => substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()', ceil($length/strlen($x)) )),1,$length),
                'post_salt' => substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()', ceil($length/strlen($x)) )),1,$length),
                'shift'     => random_int(2,20)
            );

            // Save to the file
            if(!file_put_contents($setting_path.$filename, json_encode($setting)))
            {
                throw new MmdbException('Cannot save hash setting file.');
            }

            chmod($setting_path.$filename, 0600);
        }

        // Load setting from file
        $setting = json_decode(file_get_contents($setting_path . $filename));

        $hash = $setting->pre_salt . $password . $setting->post_salt;

        $t = $hash;
        $hash = '';

        // Shift ciphre apply
        foreach(str_split($t) as $c)
        {
            $hash .= chr(ord($c) + $setting->shift);
        }
        
        return $hash;
    }

    /**
     * Unhash password
     */
    public static function unhash($hash)
    {
        // Check if hash settings exists
        $setting_path = SYS_ROOT . 'hash/';
        $filename = 'PS';

        if(!file_exists($setting_path.$filename))
        {
            throw new MmdbException('Hash function not deined.');
        }

        // Load setting
        $setting = json_decode(file_get_contents($setting_path . $filename));

        $unhashed = '';
        foreach(str_split($hash) as $c)
        {
            $unhashed .= chr(ord($c) - $setting->shift);
        }

        // Remove prefix
        $unhashed = substr($unhashed, strlen($setting->pre_salt));
        // Remove suffix
        $unhashed = substr($unhashed, 0, strlen($unhashed) - strlen($setting->post_salt));

        return $unhashed;
    }
}
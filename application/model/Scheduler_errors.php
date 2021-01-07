<?php

/**
 * Scheduler errors handler
 * 
 * @property int $id
 * @property int $id_substance
 * @property string $error_text
 * @property int $count
 * @property string $last_update
 * @property int $id_user
 * 
 * @property Users $user
 * 
 * @author Jakub Juračka
 */
class Scheduler_errors extends Db
{
    public $has_one = array
    (
        'id_user'
    );

    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'scheduler_errors';
        parent::__construct($id);
    }

    /**
     * Adds/updates record
     * 
     * @param $id_substance
     * @param $error_text
     * 
     * @return Scheduler_errors
     */
    public function add($id_substance, $error_text)
    {
        $log = new Scheduler_errors();

        $user_id = isset($_SESSION['user']) && isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : NULL;

        $exists = $this->where(array
            (
                'id_substance'  => $id_substance,
                'error_text'    => $error_text 
            ))
            ->get_one();

        if($exists->id)
        {
            $exists->count = $exists->count + 1;
            $exists->id_user = $user_id ? $user_id : $exists->id_user;
            $exists->save();
            return $exists;
        }
        else
        {
            $log->id_substance = $id_substance;
            $log->error_text = $error_text;
            $log->count = 1;
            $log->id_user = $user_id;

            $log->save();
            return $log;
        }
    }

    /**
     * Adds record from object diff
     * 
     * @param int $id_substance
     * @param array|object $error_text
     * 
     * @return Scheduler_errors
     */
    public function add_diff($id_substance, $diff)
    {
        if(is_object($diff))
        {
            $diff = $diff->as_array();
        }

        $log = new Scheduler_errors();

        $user_id = isset($_SESSION['user']) && isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : NULL;

        // Make text from diff
        $error_text = 'Changes - ';

        foreach($diff as $key => $vals)
        {
            $p = $vals['prev'];
            $c = $vals['curr'];

            if(!$p || !$c || $p == $c)
            {
                continue;
            }

            $error_text .= "[$key: $p => $c] ";
        }
        
        $log->id_substance = $id_substance;
        $log->error_text = $error_text;
        $log->count = 1;
        $log->id_user = $user_id;

        $log->save();
        return $log;
    }
}
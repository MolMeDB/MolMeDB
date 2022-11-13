<?php

/**
 * Validator identifiers changes model
 * 
 * @property int $id
 * @property int $id_old
 * @property Validator_identifiers $old
 * @property int $id_new
 * @property Validator_identifiers $new
 * @property int $id_user
 * @property Users $user
 * @property string $message
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Validator_identifier_changes extends Db
{
    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'validator_identifiers_changes';
        parent::__construct($id);
    }

    /**
     * Links to other tables
     */
    protected $has_one = array
    (
        'id_user',
        'id_old' => array
        (
            'var' => 'old',
            'class' => 'Validator_identifiers'
        ),
        'id_new' => array
        (
            'var' => 'new',
            'class' => 'Validator_identifiers'
        ),
    );

   /**
    * 
    */
    function save()
    {
        if(!$this->id && $this->id_new && $this->id_old)
        {
            $last = $this->where('id_old', $this->id_old)->order_by('id', 'DESC')->get_one();

            if($last->id && $last->id_new === NULL)
            {
                $last->id_new = $this->id_new;
                $last->save();
                
                parent::__construct($last->id);
                return;
            }
        }

        parent::save();
    }
}
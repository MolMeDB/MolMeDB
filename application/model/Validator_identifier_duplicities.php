<?php

/**
 * Validator identifiers duplicities model
 * 
 * @property int $id
 * @property int $id_validator_identifier_1
 * @property Validator_identifiers $validator_identifier_1
 * @property int $id_validator_identifier_2
 * @property Validator_identifiers $validator_identifier_2
 * @property int $state
 * @property int $id_user
 * @property Users $user
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Validator_identifier_duplicities extends Db
{
    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'validator_identifiers_duplicities';
        parent::__construct($id);
    }

    /**
     * States
     */
    const STATE_NEW = 1;
    const STATE_NON_DUPLICITY = 2;

    /**
     * Links to other tables
     */
    protected $has_one = array
    (
        'id_user',
        'id_validator_identifier_1' => array
        (
            'var' => 'validator_identifier_1',
            'class' => 'Validator_identifiers'
        ),
        'id_validator_identifier_2' => array
        (
            'var' => 'validator_identifier_2',
            'class' => 'Validator_identifiers'
        ),
    );

    /**
     * Checks, if given substances are possible duplicities
     * 
     * @param int $substance_id_1
     * @param int $substance_id_2
     * 
     * @return boolean
     */
    public function is_possible_duplicity($substance_id_1, $substance_id_2)
    {
        $res = $this->queryOne('
            SELECT *
            FROM validator_identifiers_duplicities vid
            JOIN validator_identifiers vi1 ON vi1.id = vid.id_validator_identifier_1 
            JOIN validator_identifiers vi2 ON vi2.id = vid.id_validator_identifier_2
            WHERE ((vi1.id_substance = ? AND vi2.id_substance = ?) OR (vi1.id_substance = ? AND vi2.id_substance = ?))
                AND vid.state = ?
        ', array
        (
            $substance_id_1,
            $substance_id_2,
            $substance_id_2,
            $substance_id_1,
            self::STATE_NEW
        ));

        return $res->id ? TRUE : FALSE;
    }

    /**
     * Checks, if given substances are not duplicities
     * 
     * @param int $substance_id_1
     * @param int $substance_id_2
     * 
     * @return boolean
     */
    public function is_non_duplicity($substance_id_1, $substance_id_2)
    {
        $res = $this->queryOne('
            SELECT *
            FROM validator_identifiers_duplicities vid
            JOIN validator_identifiers vi1 ON vi1.id = vid.id_validator_identifier_1 
            JOIN validator_identifiers vi2 ON vi2.id = vid.id_validator_identifier_2
            WHERE ((vi1.id_substance = ? AND vi2.id_substance = ?) OR (vi1.id_substance = ? AND vi2.id_substance = ?))
                AND vid.state = ?
        ', array
        (
            $substance_id_1,
            $substance_id_2,
            $substance_id_2,
            $substance_id_1,
            self::STATE_NON_DUPLICITY
        ));

        return $res->id ? TRUE : FALSE;
    }

    /**
     * Save overloading
     */
    public function save()
    {
        if($this->id_validator_identifier_1 == $this->id_validator_identifier_2)
        {
            throw new Exception('Ids of identifiers cannot be same.');
        }

        if($this->id_validator_identifier_1 > $this->id_validator_identifier_2)
        {
            $t = $this->id_validator_identifier_1;
            $this->id_validator_identifier_1 = $this->id_validator_identifier_2;
            $this->id_validator_identifier_2 = $t;
        }

        if(!$this->state)
        {
            $this->state = self::STATE_NEW;
        }

        parent::save();
    }
   
}
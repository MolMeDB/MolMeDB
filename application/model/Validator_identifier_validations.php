<?php

/**
 * Validator identifiers validations model
 * 
 * @property int $id
 * @property int $id_validator_identifier
 * @property Validator_identifiers $validator_identifier
 * @property int $id_user
 * @property Users $user
 * @property int $state
 * @property string $message
 * @property string $datetime
 * 
 * @author Jakub Juracka
 */
class Validator_identifier_validations extends Db
{
    /** STATES */
    const STATE_NEW = 0;
    const STATE_VALIDATED = 1;
    const STATE_INVALID = 2;

    /**
     * Enum states
     */
    private static $enum_states = array
    (
        self::STATE_NEW => 'new',
        self::STATE_VALIDATED => 'validated'
    );


    /**
     * Constructor
     * 
     * @param int $id
     */
    function __construct($id = NULL)
    {
        $this->table = 'validator_identifiers_validations';
        parent::__construct($id);
    }

    /**
     * Links to other tables
     */
    protected $has_one = array
    (
        'id_user',
        'id_validator_identifier',
    );

    
}
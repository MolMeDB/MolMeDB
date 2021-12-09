<?php

/**
 * Validator values class
 * 
 * @property int $id
 * @property int $id_validation
 * @property string $value
 * @property int $flag
 * @property string $description
 * 
 * @author Jakub Juracka
 */
class Validator_value extends Db
{
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'validation_values';
        parent::__construct($id);
    }

}
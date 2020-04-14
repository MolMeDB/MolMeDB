<?php 

/**
 * Config model
 * 
 * @property integer $id
 * @property string $attribute
 * @property string $value
 * 
 */
class Configs extends Db
{
    /** CONFIG ATTRIBUTES */
    const EUROPEPMC_URI = 'europepmc_uri';
    const RDKIT_URI = 'rdkit_uri';

    /** Defines valid config attributes */
    private $valid_attributes = array
    (
        self::EUROPEPMC_URI,
        self::RDKIT_URI
    );
    
    /**
     * Constructor
     */
    function __construct($id = NULL)
    {
        $this->table = 'config';
        parent::__construct($id);
    }

    /**
     * Checks if given attribute is valid
     */
    public function is_valid_attribute($attribute)
    {
        return in_array($attribute, $this->valid_attributes);
    }
}
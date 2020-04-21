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
    const LAST_SMILES_UPDATE = 'smiles_updated_date';
    const DB_DRUGBANK_PATTERN = 'db_drugbank_pattern';
    const DB_PUBCHEM_PATTERN = 'db_pubchem_pattern';
    const DB_PDB_PATTERN = 'db_pdb_pattern';

    /** Defines valid config attributes */
    private $valid_attributes = array
    (
        self::EUROPEPMC_URI,
        self::RDKIT_URI,
        self::LAST_SMILES_UPDATE,
        self::DB_DRUGBANK_PATTERN,
        self::DB_PUBCHEM_PATTERN,
        self::DB_PDB_PATTERN,
    );

    /** Defines, which values must be regexp */
    private static $regexp_attrs = array
    (
        self::DB_DRUGBANK_PATTERN,
        self::DB_PUBCHEM_PATTERN,
        self::DB_PDB_PATTERN,
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
     * Checks current input validity
     * 
     * @throws Exception If not correct value
     */
    public function check_value()
    {
        // Check regexp validity
        if(in_array($this->attribute, self::$regexp_attrs) && $this->value &&
            trim($this->value) != '')
        {
            $this->value = '/' . trim($this->value, '/') . '/';

            $nonsense_text = 'asdl[pwdawp[dl';

            // Not valid regexp?
            if(@preg_match($this->value, $nonsense_text) === false)
            {
                throw new Exception('Regullar expression for pattern ' . $this->attribute . ' is invalid.');
            }
        }
    }

    /**
     * Checks if given attribute is valid
     */
    public function is_valid_attribute($attribute)
    {
        return in_array($attribute, $this->valid_attributes);
    }
}
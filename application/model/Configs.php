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
    const DB_CHEMBL_PATTERN = 'db_chembl_pattern';
    const DB_CHEBI_PATTERN = 'db_chebi_pattern';
    
    // Scheduler attributes
    const S_ACTIVE = 'scheduler_active';
    const S_SMILES_VALIDATIONS = 'scheduler_smiles_validations';
    const S_3D_STRUCTURE_AUTOFILL = 'scheduler_3D_structure_autofill';
    const S_DUPL_CHECK = 'scheduler_duplicity_check';  
    const S_FILL_MISSING_RDKIT = 'scheduler_fill_missing_rdkit';  
    const S_FILL_MISSING_REMOTE = 'scheduler_fill_missing_remote';  
    const S_DELETE_EMPTY_SUBSTANCES = 'scheduler_delete_empty_substances';
    const S_DELETE_EMPTY_SUBSTANCES_TIME = 'scheduler_delete_empty_substances_TIME';
    const S_VALIDATE_PASSIVE_INT = 'scheduler_validate_passive_int';
    const S_VALIDATE_PASSIVE_INT_TIME = 'scheduler_validate_passive_int_TIME';
    const S_VALIDATE_ACTIVE_INT = 'scheduler_VALIDATE_ACTIVE_INT';
    const S_VALIDATE_ACTIVE_INT_TIME = 'scheduler_VALIDATE_ACTIVE_INT_TIME';
    const S_VALIDATE_SUBSTANCES = 'scheduler_validate_substances';
    const S_VALIDATE_SUBSTANCES_TIME = 'scheduler_validate_substances_time';
    const S_UPDATE_STATS = 'scheduler_update_stats';
    const S_UPDATE_STATS_TIME = 'scheduler_update_stats_time';
    const S_CHECK_PUBLICATIONS = 'scheduler_check_publications';
    const S_CHECK_PUBLICATIONS_TIME = 'scheduler_check_publications_time';
    const S_CHECK_MEMBRANES_METHODS = 'scheduler_check_membranes_methods';
    const S_CHECK_MEMBRANES_METHODS_TIME = 'scheduler_check_membranes_methods_time';
    const S_CHECK_REVALIDATE_3D_STRUCTURES = 'scheduler_revalidate_3d_structures';
    const S_CHECK_REVALIDATE_3D_STRUCTURES_LAST_ID = 'scheduler_revalidate_3d_structures_last_id';
    const S_CHECK_REVALIDATE_3D_STRUCTURES_IS_RUNNING = 'scheduler_revalidate_3d_structures_is_running';

    // Email attributes
    const EMAIL_ENABLED = "email_enabled";
    const EMAIL = "email_server";
    const EMAIL_SERVER_USERNAME = "email_server_username";
    const EMAIL_SMTP_SERVER = "email_smtp_server";
    const EMAIL_SMTP_PORT = "email_smtp_port";
    const EMAIL_SMTP_USERNAME = "email_smtp_username";
    const EMAIL_SMTP_PASSWORD = "email_smtp_password";
    const EMAIL_ADMIN_EMAILS = 'email_admin_emails';


    /** Defines valid config attributes */
    private $valid_attributes = array
    (
        self::EUROPEPMC_URI,
        self::RDKIT_URI,
        self::LAST_SMILES_UPDATE,
        self::DB_DRUGBANK_PATTERN,
        self::DB_PUBCHEM_PATTERN,
        self::DB_CHEBI_PATTERN,
        self::DB_CHEMBL_PATTERN,
        self::DB_PDB_PATTERN,
        self::S_ACTIVE,
        self::S_SMILES_VALIDATIONS,
        self::S_3D_STRUCTURE_AUTOFILL,
        self::S_DUPL_CHECK,
        self::S_FILL_MISSING_RDKIT,
        self::S_FILL_MISSING_REMOTE,
        self::S_DELETE_EMPTY_SUBSTANCES,
        self::S_DELETE_EMPTY_SUBSTANCES_TIME,
        self::S_VALIDATE_PASSIVE_INT,
        self::S_VALIDATE_PASSIVE_INT_TIME,
        self::S_VALIDATE_ACTIVE_INT,
        self::S_VALIDATE_ACTIVE_INT_TIME,
        self::S_VALIDATE_SUBSTANCES,
        self::S_VALIDATE_SUBSTANCES_TIME,
        self::S_UPDATE_STATS,
        self::S_UPDATE_STATS_TIME,
        self::S_CHECK_PUBLICATIONS,
        self::S_CHECK_PUBLICATIONS_TIME,
        self::S_CHECK_MEMBRANES_METHODS,
        self::S_CHECK_MEMBRANES_METHODS_TIME,
        self::S_CHECK_REVALIDATE_3D_STRUCTURES,
        self::S_CHECK_REVALIDATE_3D_STRUCTURES_LAST_ID,
        self::S_CHECK_REVALIDATE_3D_STRUCTURES_IS_RUNNING,
        self::EMAIL_ENABLED,
        self::EMAIL,
        self::EMAIL_SERVER_USERNAME,
        self::EMAIL_SMTP_PORT,
        self::EMAIL_SMTP_SERVER,
        self::EMAIL_SMTP_USERNAME,
        self::EMAIL_SMTP_PASSWORD,
        self::EMAIL_ADMIN_EMAILS,
    );

    /** Defines, which values must be regexp */
    private static $regexp_attrs = array
    (
        self::DB_DRUGBANK_PATTERN,
        self::DB_PUBCHEM_PATTERN,
        self::DB_PDB_PATTERN,
        self::DB_CHEBI_PATTERN,
        self::DB_CHEMBL_PATTERN,
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
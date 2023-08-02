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
    const S_DUPL_CHECK = 'scheduler_duplicity_check';  
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
    const S_FRAGMENT_MOLECULES = 'scheduler_fragment_molecules';
    const S_UPDATE_PAIR_GROUPS = 'scheduler_update_pair_groups';
    const S_UPDATE_PAIR_GROUPS_TIME = 'scheduler_update_pair_groups_time';
    const S_UPDATE_PAIR_GROUPS_STATS_TIME = 'scheduler_update_pair_groups_stats_time';
    const S_MATCH_FUNCT_PAIRS = 'scheduler_match_func_pairs';
    const S_AUTOUPLOAD_INTERACTIONS = 'scheduler_autoupload_interactions';
    const S_AUTOUPLOAD_INTERACTIONS_TIME = 'scheduler_autoupload_interactions_time';

    // Email attributes
    const EMAIL_ENABLED = "email_enabled";
    const EMAIL = "email_server";
    const EMAIL_SERVER_USERNAME = "email_server_username";
    const EMAIL_SMTP_SERVER = "email_smtp_server";
    const EMAIL_SMTP_PORT = "email_smtp_port";
    const EMAIL_SMTP_USERNAME = "email_smtp_username";
    const EMAIL_SMTP_PASSWORD = "email_smtp_password";
    const EMAIL_ADMIN_EMAILS = 'email_admin_emails';

    // COSMO attributes
    const COSMO_ENABLED = "cosmo_enabled";
    const COSMO_TOTAL_RUNNING = "cosmo_total_in_queue_running";
    const COSMO_LAST_UPDATE = "cosmo_last_update";
    const COSMO_URL = "cosmo_url";
    const COSMO_METACENTRUM_MAX_SDF = "cosmo_max_sdf";
    const COSMO_METACENTRUM_MAX_ION = "cosmo_max_ion";
    const COSMO_METACENTRUM_MAX_RUNNING = "cosmo_max_running";


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
        self::S_DUPL_CHECK,
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
        self::S_FRAGMENT_MOLECULES,
        self::S_UPDATE_PAIR_GROUPS,
        self::S_UPDATE_PAIR_GROUPS_TIME,
        self::S_UPDATE_PAIR_GROUPS_STATS_TIME,
        self::S_MATCH_FUNCT_PAIRS,
        self::S_AUTOUPLOAD_INTERACTIONS,
        self::S_AUTOUPLOAD_INTERACTIONS_TIME,
        // Email
        self::EMAIL_ENABLED,
        self::EMAIL,
        self::EMAIL_SERVER_USERNAME,
        self::EMAIL_SMTP_PORT,
        self::EMAIL_SMTP_SERVER,
        self::EMAIL_SMTP_USERNAME,
        self::EMAIL_SMTP_PASSWORD,
        self::EMAIL_ADMIN_EMAILS,
        // COSMO
        self::COSMO_ENABLED,
        self::COSMO_TOTAL_RUNNING,
        self::COSMO_LAST_UPDATE,
        self::COSMO_URL,
        self::COSMO_METACENTRUM_MAX_SDF,
        self::COSMO_METACENTRUM_MAX_ION,
        self::COSMO_METACENTRUM_MAX_RUNNING
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
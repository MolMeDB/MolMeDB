<?php

/**
 * Validates uploaded data
 */
class Upload_validator 
{
    /** ROUND NUM DECIMALS */
    const ROUND_DEC = 2;

    /** MAX LENGTH OF SUBSTANCE NAME */
    const NAME_MAX_LEN = 90;

    /** Defines attributes */
    const NAME = 'Name';
    const PRIMARY_REFERENCE = 'Primary_reference';
    const Q = 'Q';
    const X_MIN = 'X_min';
    const X_MIN_ACC = 'X_min_acc';
    const G_PEN = 'G_pen';
    const G_PEN_ACC = 'G_pen_acc';
    const G_WAT = 'G_wat';
    const G_WAT_ACC = 'G_wat_acc';
    const LOG_K = 'LogK';
    const LOG_K_ACC = 'LogK_acc';
    const LOG_P = 'LogP';
    const LOG_PERM = 'LogPerm';
    const LOG_PERM_ACC = 'LogPerm_acc';
    const THETA = 'theta';
    const THETA_ACC = 'theta_acc';
    const ABS_WL = 'abs_wl';
    const ABS_WL_ACC = 'abs_wl_acc';
    const FLUO_WL = 'fluo_wl';
    const FLUO_WL_ACC = 'fluo_wl_acc';
    const QY = 'QY';
    const QY_ACC = 'QY_acc';
    const LT = 'lt';
    const LT_ACC = 'lt_acc';
    const MW = 'MW';
    const SMILES = 'SMILES';
    const DRUGBANK = 'DrugBank_ID';
    const PUBCHEM = 'PubChem_ID';
    const PDB = 'PDB_ID';
    const UNIPROT_ID = "Uniprot_id";
    const CHEMBL_ID = "Chembl_id";
    const CHEBI_ID = "Chebi_id";
    const AREA = 'Area';
    const VOLUME = 'Volume';
    const TARGET = "Target";
    const TYPE = "Type";
    const KM = "Km";
    const KM_ACC = "Km_acc";
    const KI = "Ki";
    const KI_ACC = "Ki_acc";
    const IC50 = "IC50";
    const IC50_ACC = "IC50_acc";
    const EC50 = "EC50";
    const EC50_ACC = "EC50_acc";
    const COMMENT = "Note";



    /** Valid dataset types */
    private static $valid_dataset_attrs = array
    (
        self::NAME,
        self::PRIMARY_REFERENCE,
        self::Q,
        self::X_MIN,
        self::X_MIN_ACC,
        self::G_PEN,
        self::G_PEN_ACC,
        self::G_WAT,
        self::G_WAT_ACC,
        self::LOG_K,
        self::LOG_K_ACC,
        self::LOG_P,
        self::LOG_PERM,
        self::LOG_PERM_ACC,
        self::THETA,
        self::THETA_ACC,
        self::ABS_WL,
        self::ABS_WL_ACC,
        self::FLUO_WL,
        self::FLUO_WL_ACC,
        self::QY,
        self::QY_ACC,
        self::LT,
        self::LT_ACC,
        self::MW,
        self::SMILES,
        self::DRUGBANK,
        self::PUBCHEM,
        self::CHEMBL_ID,
        self::CHEBI_ID,
        self::PDB,
        self::AREA,
        self::VOLUME,
        self::COMMENT
    );

    /** Valid transporter types */
    private static $valid_transporter_attrs = array
    (
        self::TARGET,
        self::COMMENT,
        self::TYPE,
        self::KM,
        self::KM_ACC,
        self::KI,
        self::KI_ACC,
        self::IC50,
        self::IC50_ACC,
        self::EC50,
        self::EC50_ACC,
        self::NAME,
        self::LOG_P,
        self::MW,
        self::DRUGBANK,
        self::PUBCHEM,
        self::PDB,
        self::UNIPROT_ID,
        self::PRIMARY_REFERENCE,
        self::SMILES,
    );

    /** Numeric attributes */
    private static $numeric_types = array
    (
        self::X_MIN,
        self::X_MIN_ACC,
        self::G_PEN,
        self::G_PEN_ACC,
        self::G_WAT,
        self::G_WAT_ACC,
        self::LOG_K,
        self::LOG_K_ACC,
        self::LOG_P,
        self::LOG_PERM,
        self::LOG_PERM_ACC,
        self::THETA,
        self::THETA_ACC,
        self::ABS_WL,
        self::ABS_WL_ACC,
        self::FLUO_WL,
        self::FLUO_WL_ACC,
        self::QY,
        self::QY_ACC,
        self::LT,
        self::LT_ACC,
        self::MW,
        self::AREA,
        self::VOLUME,
        self::KM,
        self::KM_ACC,
        self::KI,
        self::KI_ACC,
        self::EC50,
        self::EC50_ACC,
        self::IC50,
        self::IC50_ACC,
    );

    /** Non-negative attributes - must be numeric! */
    private static $non_negative_types = array
    (
        self::X_MIN_ACC,
        self::G_PEN_ACC,
        self::G_WAT_ACC,
        self::LOG_K_ACC,
        self::LOG_PERM_ACC,
        self::THETA_ACC,
        self::ABS_WL_ACC,
        self::FLUO_WL_ACC,
        self::QY_ACC,
        self::LT_ACC,
        self::MW,
        self::AREA,
        self::VOLUME,
        self::KM_ACC,
        self::KI_ACC,
        self::EC50_ACC,
        self::IC50_ACC
    );

    /** DB identifiers */
    private static $db_identifiers = array
    (
        self::DRUGBANK,
        self::PUBCHEM,
        self::PDB,
        self::UNIPROT_ID,
        self::CHEBI_ID,
        self::CHEMBL_ID
    );

    /** Which vals shoud be rounded ? */
    private static $round_attr = array
    (
        self::X_MIN,
        self::X_MIN_ACC,
        self::G_PEN,
        self::G_PEN_ACC,
        self::G_WAT,
        self::G_WAT_ACC,
        self::LOG_K,
        self::LOG_K_ACC,
        self::LOG_P,
        self::LOG_PERM,
        self::LOG_PERM_ACC,
        self::THETA,
        self::THETA_ACC,
        self::ABS_WL,
        self::ABS_WL_ACC,
        self::FLUO_WL,
        self::FLUO_WL_ACC,
        self::QY,
        self::QY_ACC,
        self::LT,
        self::LT_ACC,
        self::MW,
        self::AREA,
        self::VOLUME,
        self::KM,
        self::KI,
        self::EC50,
        self::IC50,
    );

    /**
     * Returns valid dataset attribute types
     * 
     * @return array
     */
    public static function get_dataset_attributes()
    {
        return self::$valid_dataset_attrs;
    }

    /**
     * Returns valid transporters attribute types
     * 
     * @return array
     */
    public static function get_transporter_attributes()
    {
        return self::$valid_transporter_attrs;
    }

    /**
     * Checks validity of input and returns correctly formated value
     * 
     * @param string $attr
     * @param $value
     * 
     * @return string|integer|float|null
     */
    public static function get_attr_val($attr, $value)
    {
        if(!$value === NULL || $value === '')
        {
            return NULL;
        }

        $value = str_replace("\"", "", $value);
        $value = trim($value);

        // Is attribute numeric?
        if(in_array($attr, self::$numeric_types))
        {         
            $value = str_replace(',', '.', $value);
            $value = $value != '' ? floatval($value) : NULL;
          
            if($value === NULL)
            {
                return NULL;
            }
            
            // Should be value non-negative?
            if($value && in_array($attr, self::$non_negative_types))
            {
                $value = abs($value);
            }

            if(in_array($attr, self::$round_attr))
            {
                $value = round($value, self::ROUND_DEC);
            }

            return $value;
        }

        // Other validations
        switch($attr)
        {
            // Get reference ID for reference
            case self::PRIMARY_REFERENCE:
                return self::get_reference($value);

            // Canonize smiles
            case self::SMILES:
                return self::canonize_smiles($value);

            // NAME CHECK
            case self::NAME:
                if(!$value || $value == "" || strlen($value) > self::NAME_MAX_LEN)
                {
                    return NULL;
                }
                return ucfirst($value);

            // Type of transporter
            case self::TYPE:
                if(!Transporters::is_valid_shortcut($value))
                {
                    throw new Exception('Invalid transporter type.');
                }
                return Transporters::get_type_from_shortcut($value);

            // Check Drugbank ID validity
            case self::DRUGBANK:
                if(!self::check_identifier_format($value, self::DRUGBANK))
                {
                    throw new Exception('Wrong drugbank accession number format - ' . $value . '.');
                };
                return $value;

            // Check PDB ID validity
            case self::PDB:
                if(!self::check_identifier_format($value, self::PDB))
                {
                    throw new Exception('Wrong PDB ID format - ' . $value . '.');
                };
                return $value;

            // Check Pubchem ID validity
            case self::PUBCHEM:
                if(!self::check_identifier_format($value, self::PUBCHEM))
                {
                    throw new Exception('Wrong pubchem ID format - ' . $value . '.');
                };
                return $value;

            // Check CHEMBL ID validity
            case self::CHEMBL_ID:
                $value = is_numeric($value) ? 'CHEMBL' . $value : $value;
                if(!self::check_identifier_format($value, self::CHEMBL_ID))
                {
                    throw new Exception('Wrong CHEMBL ID format - ' . $value . '.');
                };
                return $value;

            // Check Pubchem ID validity
            case self::CHEBI_ID:
                $value = ltrim($value, 'CHEBI:');
                if(!self::check_identifier_format($value, self::CHEBI_ID))
                {
                    throw new Exception('Wrong CHEBI ID format - ' . $value . '.');
                };
                return $value;

            default:
                return trim($value) != '' ? $value : NULL;
        }
    }

    /**
     * Checks identifier format
     * 
     * @param string $val
     * @param integer $type
     * 
     * @return boolean
     */
    public static function check_identifier_format($val, $type, $non_empty_patterns = False)
    {
        if(!in_array($type, self::$db_identifiers))
        {
            return false;
        }

        $val = strtoupper(trim($val));

        if($val === '')
        {
            return True;
        }

        $config = new Config();
        $pattern = '';

        switch($type)
        {
            case self::DRUGBANK:
                $pattern = $config->get(Configs::DB_DRUGBANK_PATTERN);
                break;

            case self::PDB:
                $pattern = $config->get(Configs::DB_PDB_PATTERN);
                break;

            case self::PUBCHEM:
                $pattern = $config->get(Configs::DB_PUBCHEM_PATTERN);
                break;

            case self::CHEMBL_ID:
                $pattern = $config->get(Configs::DB_CHEMBL_PATTERN);
                break;

            case self::CHEBI_ID:
                $pattern = $config->get(Configs::DB_CHEBI_PATTERN);
                break;
        }

        if(!$pattern || $pattern == "")
        {
            return !$non_empty_patterns;
        }

        return preg_match($pattern, $val);
    }

    /**
     * Canonizes SMILES
     * 
     * @param string $smiles
     * 
     * @return string|null
     */
    public static function canonize_smiles($smiles)
    {
        if(!$smiles || $smiles == '')
        {
            return NULL;
        }

        $rdkit = new Rdkit();

        if($rdkit->is_connected())
        {
            return $rdkit->canonize_smiles($smiles);
        }

        return $smiles;
    }

    /**
     * Finds reference by given query or creates new one
     * 
     * @param string $query
     * 
     * @return integer|null
     */
    public static function get_reference($query)
    {
        if($query == '')
        {
            return NULL;
        }

        $publication_model = new Publications();

        // Try to find in DB
        $reference = $publication_model->get_by_query($query);

        if($reference->id)
        {
            return $reference->id;
        }

        // Use external database if it is possible
        $europePMC = new EuropePMC();

        if($europePMC->is_connected())
        {
            $external_data = $europePMC->search($query);

            if(count($external_data))
            {
                $reference = $publication_model->where(array
                    (
                        'doi' => $external_data[0]['doi']
                    ))
                    ->get_one();

                if($reference->id)
                {
                    return $reference->id;
                }

                $ext = $external_data[0];

                $pub_timestamp = strtotime($ext['publicatedDate']);
                
                // Not exists? Add new record to DB
                $ref = new Publications();
                
                $ref->doi = $ext['doi'];
                $ref->pmid = $ext['pmid'];
                $ref->title = $ext['title'];
                $ref->authors = $ext['authors'];
                $ref->journal = $ext['journal'];
                $ref->issue = $ext['issue'];
                $ref->volume = $ext['volume'];
                $ref->year = $ext['year'];
                $ref->page = $ext['pages'];
                $ref->publicated_date = $pub_timestamp ? date('Y-m-d', $pub_timestamp) : NULL;
                $ref->citation = $ref->make_citation();

                $ref->save();

                return $ref->id;
            }
        }

        // Not exists? Add new record to DB
        $reference = new Publications();

        $reference->citation = $query;

        $reference->save();

        return $reference->id;
    }
}
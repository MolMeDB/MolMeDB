<?php

abstract class Identifier_loader
{
    /** Holds info about last used identifier type */
    public $last_identifier = null;

    /**
     * Checks, if given remote server is reachable
     * 
     * @return boolean
     */
    abstract function is_reachable();

    /**
     * Checks, if given identifier is valid
     * 
     * @param string $identifier
     * 
     * @return boolean
     */
    abstract function is_valid_identifier($identifier);

    /**
     * Returns PDB id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_pdb($substance);

    /**
     * Returns Pubchem id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_pubchem($substance);

    /**
     * Returns drugbank id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_drugbank($substance);

    /**
     * Returns chembl id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_chembl($substance);

    /**
     * Returns chebi id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_chebi($substance);

    /**
     * Returns SMILES for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_smiles($substance);

    /**
     * Returns 3D structure for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_3d_structure($substance);

    /**
     * Returns name for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_name($substance);

    /**
     * Returns title for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_title($substance);

    /**
     * Returns inchikey for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    abstract function get_inchikey($substance);

    /**
     * Returns attribute value by given attribute type
     * 
     * @param Substances $substance
     * @param int $identifier_type
     * 
     * @return string|false - False, if not found
     */
    public function get_by_type($substance, $identifier_type)
    {
        if(!Validator_identifiers::is_valid_identifier($identifier_type))
        {
            return false;
        }

        switch($identifier_type)
        {
            case Validator_identifiers::ID_SMILES:
                return $this->get_smiles($substance);
                break;
            case Validator_identifiers::ID_NAME:
                return $this->get_name($substance);
                break;
            case Validator_identifiers::ID_PDB:
                return $this->get_pdb($substance);
                break;
            case Validator_identifiers::ID_PUBCHEM:
                return $this->get_pubchem($substance);
                break;
            case Validator_identifiers::ID_INCHIKEY:
                return $this->get_inchikey($substance);
                break;
            case Validator_identifiers::ID_CHEMBL:
                return $this->get_chembl($substance);
                break;
            case Validator_identifiers::ID_CHEBI:
                return $this->get_chebi($substance);
                break;
            case Validator_identifiers::ID_DRUGBANK:
                return $this->get_drugbank($substance);
                break;
            default:
                return false;
        }
    }
}
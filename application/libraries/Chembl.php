<?php

/**
 * Chembl api handler
 */
class Chembl extends Identifier_loader
{
    /** Holds connection */
    private $client;

    /** STATUS */
    private $STATUS = false;

    /** Holds info about last used identifier type */
    public $last_identifier = null;

    /** INTERACTION DATA DETAIL */
    const logP_MEMBRANE_TYPE = Membranes::LOGP_TYPE;
    const logP_METHOD_TYPE = Methods::CHEMBL_LOGP_TYPE;
    const DATASET_TYPE = Datasets::CHEMBL;
    const PUBLICATION_TYPE = Publications::CHEMBL;

    /** Data holder */
    private $data = [];

    /** SMILES source attribute */
    public $SMILES_SOURCE_TYPE = Validator_identifiers::ID_CHEMBL;


    /**
     * Constructor
     */
    function __construct()
    {
        $this->reconnect();
    }

    /**
     * Checks, if given remote server is reachable
     * 
     * @return boolean
     */
    function is_reachable()
    {
        if(!$this->is_connected())
        {
            $this->reconnect();
        }

        return $this->is_connected();
    }

    /**
     * Reconnect
     *
     */
    function reconnect()
    {
        try 
        {
            $this->client = new Http_request('https://www.ebi.ac.uk/chembl/api/data/');
            $this->STATUS =  $this->client->test_connection();
        } 
        catch (Exception $e) 
        {
            $this->STATUS = false;
        }
    }

    /**
     * Checks, if given identifier is valid
     * 
     * @param string $identifier
     * 
     * @return boolean
     */
    function is_valid_identifier($identifier)
    {
        if(!$this->is_reachable())
        {
            return null;
        }

        $uri = "molecule/$identifier";
        $method = Http_request::METHOD_GET;
        $params = ['format' => 'json'];

        try 
        {
            $response = $this->client->request($uri, $method, $params);

            if($response)
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        catch(Exception $e)
        {
            return false;
        }
    }

    /**
     * Returns PDB id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_pdb($substance)
    {
        return false;
    }

    /**
     * Returns inchikey for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_inchikey($substance)
    {
        return false;
    }

    /**
     * Returns Pubchem id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_pubchem($substance)
    {
        return false;
    }

    /**
     * Returns drugbank id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_drugbank($substance)
    {
        return false;
    }

    /**
     * Returns chembl id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_chembl($substance)
    {
        return false;
    }

    /**
     * Returns chebi id for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_chebi($substance)
    {
        return false;
    }

    /**
     * Returns SMILES for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_smiles($substance)
    {
        $data = $this->get_data($substance->chEMBL);

        if(!$data)
        {
            return false;
        }

        return $data->SMILES;
    }

    /**
     * Returns name for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_name($substance)
    {
        return false;
    }

    /**
     * Returns 3D structure for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_3d_structure($substance)
    {
        return false;
    }

    /**
     * Returns membrane for LogP value
     * 
     * @return Membranes
     */
    public static function get_logP_membrane()
    {
        $membrane = new Membranes();
        return $membrane->get_by_type(self::logP_MEMBRANE_TYPE);
    }

    /**
     * Returns method for LogP value
     * 
     * @return Methods
     */
    public static function get_logP_method()
    {
        $method = new Methods();
        return $method->get_by_type(self::logP_METHOD_TYPE);
    }

    /**
     * Returns dataset for new interaction details
     * 
     * @return Datasets
     */
    public static function get_dataset()
    {
        $ds = new Datasets();
        return $ds->get_by_type(self::DATASET_TYPE);
    }

    /**
     * Returns dataset for new interaction details
     * 
     * @return Datasets
     */
    public static function get_publication()
    {
        $p = new Publications();
        return $p->get_by_type(self::PUBLICATION_TYPE);
    }

    /**
     * Return service status
     * 
     * @return boolean
     */
    public function is_connected()
    {
        return $this->STATUS;
    }

    /**
     * Returns data from pubchem source
     * 
     * @param string $chembl_id
     * 
     * @return object|null
     */
    public function get_data($chembl_id)
    {
        if(!$chembl_id)
        {
            return NULL;
        }

        if(isset($this->data[$chembl_id]))
        {
            return $this->data[$chembl_id];
        }

        $this->last_identifier = Validator_identifiers::ID_CHEMBL;

        $uri = "molecule/$chembl_id";
        $method = Http_request::METHOD_GET;
        $params = ['format' => 'json'];

        try 
        {
            $response = $this->client->request($uri, $method, $params);
            $result = array();

            if($response && $response->molecule_properties)
            {
                $d = $response->molecule_properties;

                $result['LogP'] = $d->alogp ? $d->alogp : NULL;
                $result['formula'] = $d->full_molformula ? $d->full_molformula : NULL;
                $result['MW'] = $d->mw_freebase ? $d->mw_freebase : NULL;
            }

            if($response && $response->molecule_structures)
            {
                $d = $response->molecule_structures;

                $result['SMILES'] = $d->canonical_smiles ? $d->canonical_smiles : NULL;
                $result['inchi'] = $d->standard_inchi ? $d->standard_inchi : NULL;
                $result['inchikey'] = $d->standard_inchi_key ? $d->standard_inchi_key : NULL;
            }

            // Return result
            if(!empty($result))
            {
                $this->data[$chembl_id] = new Iterable_object($result);
                return $this->data[$chembl_id];
            }

            return NULL;
        } 
        catch (Exception $e) 
        {
            return NULL;
        }
    }

}
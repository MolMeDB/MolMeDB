<?php

/**
 * Drugbank api handler
 */
class Drugbank extends Identifier_loader
{
    /** Holds connection */
    private $client;

    /** STATUS */
    private $STATUS = false;

    /** Holds info about last used identifier type */
    public $last_identifier = null;

    /**
     * Constructor
     */
    function __construct()
    {
        try 
        {
            $this->client = new Http_request('https://go.drugbank.com/');

            // Try to connect
            $this->STATUS =  $this->client->test_connection('test');
        } 
        catch (Exception $e) 
        {
            $this->STATUS = false;
        }
    }

       /**
     * Checks, if given remote server is reachable
     * 
     * @return boolean
     */
    function is_reachable()
    {
        return $this->is_connected();
    }

    /**
     * Checks, if given identifier is valid
     * 
     * @param string $identifier
     * 
     * @return boolean|null - Null if the server is unreachable
     */
    function is_valid_identifier($identifier)
    {
        if(!$this->is_reachable())
        {
            return null;
        }

        $uri = 'drugs/' . $identifier;

        try
        {
            $response = $this->client->request($uri, Http_request::METHOD_GET, [], FALSE, 10, FALSE);

            if($response)
            {
                return true;
            }
        }
        catch(Exception $e)
        {
        }
        return false;
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
        return false;
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
     * For given SMILES returns InChIKey
     * 
     * @param Substances $subtsance
     * 
     * @return string|False
     */
    public function get_inchikey($substance)
    {
        return null;
    }

    /**
     * Finds 3D structure for given molecule
     * 
     * @param Substances $substance
     * 
     * @return string|null
     */
    public function get_3d_structure($substance)
    {
        if(!$substance->drugbank)
        {
            return NULL;
        }

        $this->last_identifier = Validator_identifiers::ID_DRUGBANK;

        $uri = "structures/small_molecule_drugs/$substance->drugbank.sdf";
        $method = Http_request::METHOD_GET;

        try
        {        
            $response = $this->client->request($uri, $method, array(), FALSE, 10, FALSE);

            if($response)
            {
                return $response;
            }
        }
        catch(Exception $e)
            {
                return NULL;
        }
        
        return NULL;
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

}
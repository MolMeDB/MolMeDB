<?php

/**
 * Rdkit class for handling rdkit request
 * for server service
 */
class Rdkit extends Identifier_loader
{
    /** SERVICE STATUS */
    private static $STATUS = false;

    /** Holds connection */
    private static $client;

    /** Holds info about last used identifier type */
    public $last_identifier = null;

    /**
     * Constructor
     * Checks service status
     */
    function __construct()
    {
        // Add to the system setting in next update
        if(!self::is_connected())
        {
            self::connect();
        }
    }

    /**
     * Connect to remote server
     */
    public static function connect()
    {
        if(self::is_connected())
        {
            return;
        }

        try
        {
            self::$client = new Http_request(Config::get(Configs::RDKIT_URI));
            // Try to connect
            self::$STATUS = self::$client->test_connection('test');
        }
        catch(Exception $e)
        {
           self::$STATUS = false;
           throw new Exception('Cannot establish connection to RDKIT server.');
        }
    }

    /**
     * Return service status
     * 
     * @return boolean
     */
    public static function is_connected()
    {
        return self::$client !== NULL && self::$STATUS;
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
     * @return boolean
     */
    function is_valid_identifier($identifier)
    {
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
     * For given SMILES returns it in canonized form
     * 
     * @param string $smiles
     * 
     * @return $string
     */
    public function canonize_smiles($smiles)
    {
        if(!self::$STATUS)
        {
            return null;
        }

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'smiles/canonize';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $smiles
        );

        try
        {
            $response = self::$client->request($uri, $method, $params);

            if(!empty($response) && isset($response[0]) && $response[0] != '')
            {
                return $response[0];
            }

            return False;
        }
        catch(Exception $e)
        {
            return false;
        }
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
        if(!self::$STATUS)
        {
            return false;
        }

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'makeInchi';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $substance->SMILES
        );

        try
        {
            $response = self::$client->request($uri, $method, $params);

            if(!empty($response) && isset($response[0]) && $response[0] != '')
            {
                return $response[0];
            }

            return False;
        }
        catch(Exception $e)
        {
            return false;
        }
    }

    /**
     * For given SMILES returns general info
     * 
     * @param string $smiles
     * 
     * @return $string
     */
    public function get_general_info($smiles)
    {
        if(!self::$STATUS)
        {
            return NULL;
        }

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'general';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $smiles
        );

        try
        {
            $response = self::$client->request($uri, $method, $params);

            if(!empty($response))
            {
                return $response;
            }

            return NULL;
        }
        catch(Exception $e)
        {
            return NULL;
        }
    }

    /**
     * For given SMILES returns SDF content (3D structure)
     * 
     * @param Substances $substance
     * 
     * @return $string
     */
    public function get_3d_structure($substance)
    {
        if(!self::$STATUS || !$substance->SMILES)
        {
            return NULL;
        }

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = '3dstructure/generate';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $substance->SMILES
        );

        try
        {
            $response = self::$client->request($uri, $method, $params, false, 6*60);

            if(!empty($response) && isset($response[0]) && $response[0] != '')
            {
                return $response[0];
            }

            return NULL;
        }
        catch(Exception $e)
        {
            return NULL;
        }
    }

    
    /**
     * For given SMILES returns SDF content (2D structure)
     * 
     * @param Substances $substance
     * 
     * @return $string
     */
    public function get_2d_structure($substance)
    {
        if(!self::is_connected())
        {
            self::connect();
        }

        if(!$substance->SMILES)
        {
            return NULL;
        }

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = '2dstructure/generate';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $substance->SMILES
        );

        try
        {
            $response = self::$client->request($uri, $method, $params);

            if(!empty($response) && isset($response[0]) && $response[0] != '')
            {
                return $response[0];
            }

            return NULL;
        }
        catch(Exception $e)
        {
            return NULL;
        }
    }


    /**
     * Fragments givne molecule by SMILES
     * 
     * @param string $smiles
     * 
     * @return
     */
    public function fragment_molecule($smiles)
    {
        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'mmpa/fragment';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'mol' => $smiles
        );

        try
        {
            $response = self::$client->request($uri, $method, $params);

            if(!empty($response) && isset($response[0]) && $response[0] != '')
            {
                return $response;
            }

            return NULL;
        }
        catch(Exception $e)
        {
            return NULL;
        }
    }

}
<?php

/**
 * Rdkit class for handling rdkit request
 * for server service
 */
class Rdkit extends Identifier_loader
{
    /** SERVICE STATUS */
    private static $STATUS = false;

    /** Holds connection 
     * @var Http_request
    */
    private static $client;

    /** Holds info about last used identifier type */
    public $last_identifier = null;

    /** METRICS */
    const METRIC_TANIMOTO = "Tanimoto";
    const METRIC_DICE = "Dice";
    const METRIC_COSINE = "Cosine";
    const METRIC_SOKAL = "Sokal";
    const METRIC_RUSSEL = "Russel";
    const METRIC_KULCZYNSKI = "Kulczynski";
    const METRIC_MCCONNAUGHEY = "McConnaughey";

    const METRIC_DEFAULT = self::METRIC_COSINE;

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
     * Returns title for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_title($substance)
    {
        return false;
    }

    /**
     * Returns fingerprint of given substance
     * 
     * @param Substances $substance
     * 
     * @return Vector|false - False, if not found.
     *  - Returned fingerprint must have lenght equal to 2048
     */
    function get_fingerprint($substance)
    {
        if(!self::$STATUS || !$substance->SMILES)
        {
            return null;
        }

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'mol/fingerprint';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'mol' => $substance->SMILES
        );

        try
        {
            $response = self::$client->request($uri, $method, $params);

            if($response && strlen($response->fingerprint) === 2048)
            {
                return new Vector(str_split($response->fingerprint), true);
            }

            return false;
        }
        catch(Exception $e)
        {
            return false;
        }
    }

    /**
     * Returns all ionization states of given molecule = list of smiles
     * 
     * @param string $smiles
     * @param int $limit - Maximum number of output records
     * 
     * @return object
     * @throws MmDbException
     */
    function get_ionization_states($smiles, $limit = 10)
    {
        if(!self::$STATUS || !$smiles)
        {
            return null;
        }

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'smiles/allCharges';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $smiles,
            'limit' => $limit
        );

        try
        {
            $response = self::$client->request($uri, $method, $params);

            if(is_object($response) || is_array($response))
            {
                $result = [];
                $result['molecules'] = [];
                $result['pH_start'] = $response->pH_start;
                $result['pH_end'] = $response->pH_end;
                // check all charges and filter variants with lowest charge
                if(property_exists($response, 'molecules'))
                {
                    if(count($response->molecules) > 4)
                    {
                        $result['molecules'] = array
                        (
                            $smiles  
                        );  

                        return (object) $result;
                    }

                    $candidates = [];
                    foreach($response->molecules as $smi)
                    {
                        $charge = Fragments::instance()->get_charge($smi);

                        if(abs($charge) > 2)
                        {
                            continue;
                        }

                        $candidates[] = array
                        (
                            'charge' => $charge,
                            'smiles' => $smi
                        );
                    }

                    usort($candidates, function($a, $b){ return intval($a['charge']) < intval($b['charge']); });

                    $total = 0;
                    foreach($candidates as $c)
                    {
                        if($total >= $limit) break;
                        $result['molecules'][] = $c['smiles'];
                        $total++;
                    }
                }

                return (object) $result;
            }

            return false;
        }
        catch(Exception $e)
        {
            return false;
        } 
    }

    /**
     * Returns conformers of molecule in SDF format - prepared for the COSMOperm calculations
     * 
     * @param string $smiles - Molecule SMILES
     * @param string $name - Molecule name/identifier
     * 
     * @return object[]|false - List of SDF records
     */
    function get_cosmo_conformers($smiles, $name)
    {
        if(!self::$STATUS || !$smiles)
        {
            return null;
        }

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'cosmo/conformers';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $smiles,
            'name' => $name
        );

        try
        {
            $response = self::$client->request($uri, $method, $params, FALSE, 180);

            if(is_object($response) || is_array($response))
            {
                return $response;
            }

            return false;
        }
        catch(Exception $e)
        {
            return false;
        } 
    }

    /**
     * Returns fingerprint for given smiles
     * 
     * @param string $smiles
     * 
     * @return Vector|false - False, if not found.
     *  - Returned fingerprint must have lenght equal to 2048
     */
    function get_fingerprint_by_smiles($smiles)
    {
        if(!self::$STATUS || !$smiles)
        {
            return null;
        }

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'mol/fingerprint';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'mol' => $smiles
        );

        try
        {
            $response = self::$client->request($uri, $method, $params);

            if($response && strlen($response->fingerprint) === 2048)
            {
                return new Vector(str_split($response->fingerprint), true);
            }

            return false;
        }
        catch(Exception $e)
        {
            return false;
        }
    }

    /**
     * For given SMILES returns it in canonized form
     * 
     * @param string $smiles
     * 
     * @return string|false
     */
    public function canonize_smiles($smiles, $throwable = false)
    {
        if(!self::$STATUS)
        {
            return null;
        }

        // Protect links
        $smiles = preg_replace('/\[([0-9]+):\]/', '[*:$1]', $smiles);

        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'smiles/canonize';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $smiles
        );

        try
        {
            $response = self::$client->request($uri, $method, $params, false, 30); 

            if(!empty($response) && isset($response[0]) && $response[0] != '')
            {
                // Protect links
                $smiles = preg_replace('/\[\*:([0-9]+)\]/', '[$1:]', $response[0]);
                return $smiles;
            }

            return false;
        }
        catch(Exception $e)
        {
            if($throwable)
            {
                throw new MmdbException('Cannot canonize smiles.', 'Cannot canonize smiles.', 0, $e);
            }
            return $this->canonize_smiles($smiles, true);
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
     * @param bool $throwable
     * @param int $timeout - Request timeout
     * 
     * @return
     */
    public function fragment_molecule($smiles, $throwable = false, $timeout = 120)
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
            $response = self::$client->request($uri, $method, $params, FALSE, $timeout);

            if(!empty($response) && isset($response[0]) && $response[0] != '')
            {
                return $response;
            }

            return NULL;
        }
        catch(Exception $e)
        {
            if($throwable)
            {
                throw new MmdbException($e->getMessage());
            }
            return NULL;
        }
    }


    /**
     * Computes similarity between two given molecules
     * 
     * @param string $smiles_1
     * @param string $smiles_2
     * @param string $metric
     * 
     * @return false,float
     */
    public function compute_similarity($smiles_1, $smiles_2, $metric = self::METRIC_DEFAULT)
    {
        $this->last_identifier = Validator_identifiers::ID_SMILES;

        $uri = 'mol/similarity';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi1' => $smiles_1,
            'smi2' => $smiles_2
        );

        try
        {
            $response = self::$client->request($uri, $method, $params);

            if($response && $response->similarity && $response->similarity->$metric)
            {
                return $response->similarity->$metric;
            }

            return false;
        }
        catch(Exception $e)
        {
            return false;
        }
    }

}
<?php

/**
 * Pubchem api handler
 */
class Pubchem extends Identifier_loader
{
    /** Holds connection */
    private $client;

    /** STATUS */
    private $STATUS = false;

    /** Data holder */
    private $data = [];

    /** Holds info about last used identifier type */
    public $last_identifier = null;

    /** INTERACTION DATA DETAIL */
    const logP_MEMBRANE_TYPE = Membranes::LOGP_TYPE;
    const logP_METHOD_TYPE = Methods::PUBCHEM_LOGP_TYPE;
    const DATASET_TYPE = Datasets::PUBCHEM;
    const PUBLICATION_TYPE = Publications::PUBCHEM;

    /** Search types */
    const CID = 1;
    const NAME = 2;
    const SMILES = 3;
    const INCHIKEY = 4;

    /**
     * Valid search types
     */
    private static $valid_search_types = array
    (
        self::CID,
        self::NAME,
        self::SMILES,
        self::INCHIKEY
    );

    /**
     * Constructor
     */
    function __construct()
    {
        try 
        {
            // Add to the system setting in next update
            $this->client = new Http_request('https://pubchem.ncbi.nlm.nih.gov/rest/pug/');

            // Try to connect
            $this->STATUS =  $this->client->test_connection('test');
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
    public function is_valid_identifier($identifier)
    {
        if(!$this->is_connected())
        {
            throw new Exception('Cannot connect to Pubchem server.');
        }

        $this->last_identifier = Validator_identifiers::ID_PUBCHEM;

        $format = 'JSON';

        $uri = "compound/cid/$identifier/property/Title/$format";
        $method = Http_request::METHOD_GET;

        try 
        {
            $response = $this->client->request($uri, $method);

            if($response && $response->PropertyTable && 
                $response->PropertyTable->Properties && 
                count($response->PropertyTable->Properties))
            {
                return true;
            }

            return false;
        } 
        catch (Exception $e) 
        {
            return false;
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
     * Returns name for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_name($substance)
    {
        $data = $this->get_data($substance->pubchem);

        if(!$data)
        {
            return false;
        }

        return $data->name;
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
        $data = $this->get_data($substance->pubchem);

        if(!$data)
        {
            return false;
        }

        return $data->name;
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
        return FALSE;
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
        // TODO
        return FALSE;   
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
        // TODO
        return FALSE;
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
        // TODO
        return FALSE;
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
        // TODO
        return FALSE;
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
     * Returns SMILES for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_smiles($substance)
    {
        if(!$substance || !$substance->pubchem)
        {
            return NULL;
        }

        $this->last_identifier = Validator_identifiers::ID_PUBCHEM;

        $identifier = $substance->pubchem;
        $properties = 'CanonicalSMILES,IsomericSMILES';
        $format = 'JSON';

        $uri = "compound/cid/$identifier/property/$properties/$format";
        $method = Http_request::METHOD_GET;

        try 
        {
            $response = $this->client->request($uri, $method);

            if($response && $response->PropertyTable && 
                $response->PropertyTable->Properties && 
                count($response->PropertyTable->Properties))
            {
                $data = $response->PropertyTable->Properties[0];

                $smiles = $data->IsomericSMILES ? $data->IsomericSMILES : NULL;
                $smiles = !$smiles && $data->CanonicalSMILES ? $data->CanonicalSMILES : $smiles;

                return $smiles;
            }

            return NULL;
        } 
        catch (Exception $e) 
        {
            return NULL;
        } 
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
     * Finds 3D structure for given molecule
     * 
     * @param Substances $substance
     * 
     * @return string|null
     */
    public function get_3d_structure($substance)
    {
        if(!$substance->pubchem)
        {
            return NULL;
        }

        $this->last_identifier = Validator_identifiers::ID_PUBCHEM;

        $uri = "compound/CID/$substance->pubchem/record/SDF/?record_type=3d&response_type=save";
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

    /**
     * Tries to serach on pubchem server
     * 
     * @param string $query
     * @param int $type
     * 
     * @return object List of possible records
     */
    public function search($query, $type)
    {
        if(!in_array($type, self::$valid_search_types))
        {
            throw new Exception('Invalid search type');
        }

        $format = 'json';
        $properties = 'IUPACName,CanonicalSMILES';

        $uri = 'compound/';

        switch($type)
        {
            case self::CID:
                $uri .= 'cid/';
                break;
            case self::SMILES:
                $uri .= 'smiles/';
                break;
            case self::NAME:
                $uri .= 'name/';
                break;
            case self::INCHIKEY:
                $uri .= 'inchikey/';
                break;
        }

        $uri .= "$query/property/$properties/$format";
        $method = Http_request::METHOD_GET;

        try
        {
            $response = $this->client->request($uri, $method);

            if($response && $response->PropertyTable && 
                $response->PropertyTable->Properties && 
                count($response->PropertyTable->Properties))
            {
                if(count($response->PropertyTable->Properties) > 1)
                {
                    return NULL;
                }

                $data = $response->PropertyTable->Properties[0];

                $smiles = $data->CanonicalSMILES ? $data->CanonicalSMILES : NULL;

                return (object)array
                (
                    'pubchem_id'    => Upload_validator::get_attr_val(Upload_validator::PUBCHEM, $data->CID),
                    'SMILES'        => $smiles,
                    'name'          => $data->IUPACName,
                );
            }

            return NULL;
        }
        catch (Exception $e)
        {
            return NULL;
        }

    }

    /**
     * Returns data from pubchem source
     * 
     * @param string $pubchem_id
     * 
     * @return object|null
     */
    public function get_data($pubchem_id)
    {
        if(!$pubchem_id)
        {
            return NULL;
        }

        if(isset($this->data[$pubchem_id]))
        {
            return $this->data[$pubchem_id];
        }

        $this->last_identifier = Validator_identifiers::ID_PUBCHEM;

        $properties = 'XLogP,CanonicalSMILES,IsomericSMILES,InChIKey,IUPACName,Title,MolecularWeight';
        $format = 'JSON';

        $uri = "compound/cid/$pubchem_id/property/$properties/$format";
        $method = Http_request::METHOD_GET;

        try 
        {
            $response = $this->client->request($uri, $method);

            if($response && $response->PropertyTable && 
                $response->PropertyTable->Properties && 
                count($response->PropertyTable->Properties))
            {
                $data = $response->PropertyTable->Properties[0];

                $smiles = $data->CanonicalSMILES ? $data->CanonicalSMILES : NULL;
                $smiles = !$smiles && $data->IsomericSMILES ? $data->IsomericSMILES : $smiles;

                $this->data[$pubchem_id] = new Iterable_object(array
                (
                    'pubchem_id'    => property_exists($data, "CID") ? $data->CID : null,
                    'MW'            => property_exists($data, "MolecularWeight") ? $data->MolecularWeight : null,
                    'SMILES'        => $smiles,
                    'inchikey'      => property_exists($data, "InChIKey") ? $data->InChIKey : null,
                    'title'         => property_exists($data, "Title") ? $data->Title : null,
                    'name'          => property_exists($data, "IUPACName") ? $data->IUPACName : null,
                    'logP'          => property_exists($data, "XLogP") ? $data->XLogP : null
                ));

                return $this->data[$pubchem_id];
            }

            return NULL;
        } 
        catch (Exception $e) 
        {
            return NULL;
        }
    }

}
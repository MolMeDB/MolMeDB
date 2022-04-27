<?php

/**
 * UniChem api handler
 */
class UniChem extends Identifier_loader
{
    /** Holds connection */
    private $client;

    /** STATUS */
    private $STATUS = false;

    /** Holds info about last used identifier type */
    public $last_identifier = null;

    /** SOURCE IDs */
    const CHEMBL = 1;
    const DRUGBANK = 2;
    const PDB = 3;
    const CHEBI = 7;
    const PUBCHEM = 22;

    /** Data holder */
    private $data = [];


    /**
     * Constructor
     */
    function __construct()
    {
        try 
        {
            // Add to the system setting in next update
            $this->client = new Http_request('https://www.ebi.ac.uk/unichem/rest/');

            // Try to connect
            $this->STATUS =  $this->client->test_connection('test');
        } 
        catch (Exception $e) 
        {
            $this->STATUS = false;
            throw new MmdbException('Unichem service is unreachable.');
        }
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
     * Checks, if given remote server is reachable
     * 
     * @return boolean
     */
    function is_reachable()
    {
        return $this->is_connected();
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
        $data = $this->get_identifiers($substance->inchikey);

        if(!$data)
        {
            return false;
        }

        return $data->pdb;
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
        $data = $this->get_identifiers($substance->inchikey);

        if(!$data)
        {
            return false;
        }

        return $data->pubchem;
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
        $data = $this->get_identifiers($substance->inchikey);

        if(!$data)
        {
            return false;
        }

        return $data->drugbank;
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
        $data = $this->get_identifiers($substance->inchikey);

        if(!$data)
        {
            return false;
        }

        return $data->chembl;
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
        $data = $this->get_identifiers($substance->inchikey);

        if(!$data)
        {
            return false;
        }

        return $data->chebi;
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
     * Returns identifiers
     * 
     * @param string $inchikey
     * 
     * @return object|false
     */
    public function get_identifiers($inchikey)
    {
        if(!$inchikey)
        {
            return false;
        }

        if(isset($this->data[$inchikey]))
        {
            return $this->data[$inchikey];
        }

        $this->last_identifier = Validator_identifiers::ID_INCHIKEY;

        $uri = 'inchikey/' . $inchikey;
        $method = Http_request::METHOD_GET;

        try 
        {
            $response = $this->client->request($uri, $method);

            if (!empty($response)) 
            {
                $this->data[$inchikey] = $this->parse_identifiers_response($response);
                return $this->data[$inchikey];
            }

            return False;
        } 
        catch (Exception $e) 
        {
            return false;
        }
    }

    /**
     * Parses response
     * 
     * @param array $response
     * 
     * @return object
     */
    private function parse_identifiers_response($response)
    {
        $pdb = NULL;
        $pubchem = NULL;
        $chembl = NULL;
        $chebi = NULL;
        $drugbank = NULL;

        foreach($response as $row)
        {
            $id = $row->src_id;
            $identifier = $row->src_compound_id;

            if($id == self::PDB)
            {
                $pdb = $identifier;
            }
            else if($id == self::PUBCHEM)
            {
                $pubchem = $identifier;
            }
            else if($id == self::DRUGBANK)
            {
                $drugbank = $identifier;
            } 
            else if($id == self::CHEBI)
            {
                $chebi = $identifier;
            }
            else if($id == self::CHEMBL)
            {
                $chembl = $identifier;
            }
        }

        return new Iterable_object(array
        (
            'pdb' => $pdb,
            'pubchem' => $pubchem,
            'drugbank' => $drugbank,
            'chembl' => $chembl,
            'chebi' => $chebi
        ));
    }
}
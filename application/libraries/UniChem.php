<?php

/**
 * UniChem api handler
 */
class UniChem
{
    /** Holds connection */
    private $client;

    /** STATUS */
    private $STATUS = false;

    /** SOURCE IDs */
    const CHEMBL = 1;
    const DRUGBANK = 2;
    const PDB = 3;
    const CHEBI = 7;
    const PUBCHEM = 22;


    /**
     * Constructor
     */
    function __construct()
    {
        try 
        {
            $config = new Config();

            // Add to the system setting in next update
            // $this->client = new Http_request($config->get(Configs::RDKIT_URI));
            $this->client = new Http_request('https://www.ebi.ac.uk/unichem/rest/');

            // Try to connect
            $this->STATUS =  $this->client->test_connection('test');
        } 
        catch (Exception $e) 
        {
            $this->STATUS = false;
            throw new Exception('Unichem service is unreachable.');
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
     * Returns identifiers
     * 
     */
    public function get_identifiers($inchikey)
    {
        if(!$inchikey)
        {
            return (object) array();
        }

        $uri = 'inchikey/' . $inchikey;
        $method = Http_request::METHOD_GET;

        try 
        {
            $response = $this->client->request($uri, $method);

            if (!empty($response)) 
            {
                return $this->parse_identifiers_response($response);
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

        return (object) array
        (
            'pdb' => $pdb,
            'pubchem' => $pubchem,
            'drugbank' => $drugbank,
            'chembl' => $chembl,
            'chebi' => $chebi
        );
    }
}
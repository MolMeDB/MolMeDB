<?php

/**
 * Chembl api handler
 */
class Chembl
{
    /** Holds connection */
    private $client;

    /** STATUS */
    private $STATUS = false;



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
            $this->client = new Http_request('https://www.ebi.ac.uk/chembl/api/data/');

            // Try to connect
            $this->STATUS =  $this->client->test_connection('test');
        } 
        catch (Exception $e) 
        {
            $this->STATUS = false;
            throw new Exception('Chembl service is unreachable.');
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
     * Returns data from pubchem source
     * 
     * @param string $chembl_id
     * 
     * @return object|null
     */
    public function get_molecule_data($chembl_id)
    {
        if(!$chembl_id)
        {
            return NULL;
        }

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
            elseif($response && $response->molecule_structures)
            {
                $d = $response->molecule_structures;

                $result['SMILES'] = $d->canonical_smiles ? $d->canonical_smiles : NULL;
            }

            // Return result
            if(!empty($result))
            {
                return (object) $result;
            }

            return NULL;
        } 
        catch (Exception $e) 
        {
            return NULL;
        }
    }

}
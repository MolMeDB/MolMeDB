<?php

/**
 * Pubchem api handler
 */
class Pubchem
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
            $this->client = new Http_request('https://pubchem.ncbi.nlm.nih.gov/rest/pug/');

            // Try to connect
            $this->STATUS =  $this->client->test_connection('test');
        } 
        catch (Exception $e) 
        {
            $this->STATUS = false;
            throw new Exception('Pubchem service is unreachable.');
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

        $properties = 'XLogP,CanonicalSMILES,IsomericSMILES,InChIKey,IUPACName,MolecularWeight';
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
                return $response->PropertyTable->Properties[0];
            }

            return NULL;
        } 
        catch (Exception $e) 
        {
            return NULL;
        }
    }

}
<?php

/**
 * PDB api handler
 */
class PDB
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
            $this->client = new Http_request('https://files.rcsb.org/');

            // Try to connect
            $this->STATUS =  $this->client->test_connection('test');
        } 
        catch (Exception $e) 
        {
            $this->STATUS = false;
        }
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
        if(!$substance->pdb)
        {
            return NULL;
        }

        $uri = "ligands/view/$substance->pdb" . "_ideal.sdf";
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
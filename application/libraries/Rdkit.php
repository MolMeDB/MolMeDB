<?php

/**
 * Rdkit class for handling rdkit request
 * for server service
 */
class Rdkit
{
    /** SERVICE STATUS */
    private $STATUS = false;

    /** Holds connection */
    private $client;

    /**
     * Constructor
     * Checks service status
     */
    function __construct()
    {
        try
        {
            $config = new Config();

            // Add to the system setting in next update
            $this->client = new Http_request($config->get(Configs::RDKIT_URI));

            // Try to connect
            $this->STATUS =  $this->client->test_connection('test');
        }
        catch(Exception $e)
        {
            $this->STATUS = false;
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
     * For given SMILES returns it in canonized form
     * 
     * @param string $smiles
     * 
     * @return $string
     */
    public function canonize_smiles($smiles)
    {
        if(!$this->STATUS)
        {
            return false;
        }

        $uri = 'smiles/canonize';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $smiles
        );

        try
        {
            $response = $this->client->request($uri, $method, $params);

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
     * For given SMILES returns InChIKey
     * 
     * @param string $smiles
     * 
     * @return $string
     */
    public function get_inchi_key($smiles)
    {
        if(!$this->STATUS)
        {
            return false;
        }

        $uri = 'makeInchi';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $smiles
        );

        try
        {
            $response = $this->client->request($uri, $method, $params);

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
        if(!$this->STATUS)
        {
            return NULL;
        }

        $uri = 'general';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $smiles
        );

        try
        {
            $response = $this->client->request($uri, $method, $params);

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
        if(!$this->STATUS || !$substance->SMILES)
        {
            return NULL;
        }

        $uri = '3dstructure/generate';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $substance->SMILES
        );

        try
        {
            $response = $this->client->request($uri, $method, $params, false, 6*60);

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
        if(!$this->STATUS || !$substance->SMILES)
        {
            return NULL;
        }

        $uri = '2dstructure/generate';
        $method = Http_request::METHOD_GET;
        $params = array
        (
            'smi' => $substance->SMILES
        );

        try
        {
            $response = $this->client->request($uri, $method, $params);

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

}
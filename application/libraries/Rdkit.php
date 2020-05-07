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

            throw new Exception('RDkit service is unreachable.');
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
}
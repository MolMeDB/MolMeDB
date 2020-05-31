<?php

/**
 * API handler for inner request
 */
class ApiEditor extends ApiController
{    
    /**
     * Constructor
     */
    function __construct()
    {
        // Is user logged in ?
        $this->user = isset($_SESSION['user']) ? $_SESSION['user'] : NULL;
    }

    /**
     * Return membrane detail by ID tag
     * 
     * @GET 
     * @param idTag
     */
    public function getMembrane($idTag = NULL)
    {
        $membrane_model = new Membranes();

        $membrane = $membrane_model->where('idTag', $idTag)
            ->get_one();
        
        $this->answer($membrane->as_array());
    }

    /**
     * Return membrane detail by ID tag
     * 
     * @GET 
     * @param idTag
     */
    public function getMethod($idTag = NULL)
    {
        $method_model = new Methods();

        $method = $method_model->where('idTag', $idTag)
            ->get_one();

        $this->answer($method->as_array());
    }
}
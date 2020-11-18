<?php

/**
 * API HANDLER for search engine requests
 */
class ApiSearchEngine extends ApiController
{    
    /** SEARCH TYPES */
    const T_COMPOUND = 'compounds';
    const T_SMILES = 'smiles';
    const T_MEMBRANES = 'membranes';
    const T_METHOD = 'methods';
    const T_TRANSPORTER = 'transporter';
    // const T_UNIPROT = 'uniprot';

    // Valid search types
    private $valid_search_types = array
    (
        self::T_COMPOUND,
        self::T_SMILES,
        self::T_MEMBRANES,
        self::T_METHOD,
        // self::T_UNIPROT,
        self::T_TRANSPORTER
    );


    /**
     * Constructor
     */
    function __construct()
    {
        // Is user logged in ?
        $this->user = isset($_SESSION['user']) ? $_SESSION['user'] : NULL;
    }

    /**
     * Internal function for search engine whisper
     * 
     * @GET
     * @param q Query
     * @param type
     */
    public function search($query, $type)
    {
        $query = '%' . $query . '%';

        // Check type validity
        if(!in_array($type, $this->valid_search_types))
        {
            $this->bad_request();
        }

        $data = array();
        
        switch($type)
        {
            case self::T_COMPOUND:
                $comp_model = new Substances();
                $data = $comp_model->loadSearchWhisper($query);
                break;
            
            case self::T_SMILES:
                $smiles_model = new Smiles();
                $data = $smiles_model->loadWhisperCompounds($query);
                break;
            
            case self::T_MEMBRANES:
                $membrane_model = new Membranes();
                $data = $membrane_model->loadSearchWhisper($query);
                break;
            
            case self::T_METHOD:
                $method_model = new Methods();
                $data = $method_model->loadSearchWhisper($query);
                break;
            
            case self::T_TRANSPORTER:
                $transporter_model = new Transporter_targets();
                $data = $transporter_model->loadSearchWhisper($query);
                break;
        }
        
        $this->answer($data);
    }
    
}
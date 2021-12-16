<?php

class ApiPublications extends ApiController
{   
    /**
     * Constructor
     */
    function __construct()
    {
        
    }

    /**
     * Returns publictaion detail
     * 
     * @GET
     * @param id
     */
    public function get($id)
    {
        $ref = new Publications($id);

        if(!$ref->id)
        {
            //$this->answer(array(), self::CODE_OK_NO_CONTENT);
        }

        $result = array
        (
            'id'    => $ref->id,
            'citation'   => $ref->citation,
            'doi' => $ref->doi,
            'pmid' => $ref->pmid,
            'title' => $ref->title,
            'authors' => $ref->authors,
            'journal' => $ref->journal,
            'volume' => $ref->volume,
            'issue' => $ref->issue,
            'page' => $ref->page,
            'year' => $ref->page,
            'publicated_date' => $ref->publicated_date,
            'created'   => $ref->createDateTime
        );

        //$this->answer($result);
    }

    /**
     * Gets all publications
     * 
     * @GET
     */
    public function getAll()
    {
        $publ_model = new Publications();
        
        $data = $publ_model->order_by("citation")->get_all();
        
        //$this->answer($data->as_array(NULL, FALSE));
    }

    /**
     * Gets publication detail by DOI from remote server
     * 
     * @GET
     * @param doi 
     * 
     */
    public function get_by_doi($doi)
    {
        $PMC = new EuropePMC();

        if(!$PMC->is_connected())
        {
            //$this->answer(NULL, self::CODE_FORBIDDEN);
        }

        $publ = $PMC->search($doi);

        // Should contains max one result
        if(!isset($publ[0]))
        {
            //$this->answer(NULL, self::CODE_OK_NO_CONTENT);
        }

        //$this->answer($publ[0]);
    }
    
}
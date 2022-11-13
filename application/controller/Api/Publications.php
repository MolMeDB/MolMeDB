<?php

class ApiPublications extends ApiController
{   

    /**
     * Returns publication detail
     * 
     * @GET
     * 
     * @param $id
     * @param $doi
     * 
     * @Path(/detail)
     */
    public function detail($id, $doi)
    {
        $ref = new Publications($id);

        if(!$ref->id)
        {
            $ref = $ref->where('doi', $doi ? $doi : "NONSENSE!@#")->get_one();

            if(!$ref->id)
            {
                ResponseBuilder::not_found('Publication info not found.');
            }
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

        return $result;
    }

    /**
     * Returns all publications
     * 
     * @GET
     * 
     * @Path(/all)
     */
    public function getAll()
    {
        $publ_model = new Publications();
        
        $data = $publ_model->order_by("citation")->get_all();
        
        return $data;
    }

    /**
     * Gets publication detail by param from remote server
     * 
     * @GET
     * 
     * @param $doi
     * @param @DEFAULT[FALSE] $all - Return all results?
     * 
     * @Path(/find/remote)
     */
    public function get_by_doi($doi, $all)
    {
        $PMC = new EuropePMC();

        if(!$PMC->is_connected())
        {
            ResponseBuilder::server_error('Cannot establish a connection to the EuropePMC server.');
        }

        $publ = $PMC->search($doi);

        // Should contains max one result
        if(!isset($publ[0]))
        {
            ResponseBuilder::ok_no_content();
        }

        if($all)
        {
            return $publ;
        }

        return $publ[0];
    }
    
}
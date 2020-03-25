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
            $this->answer(array(), self::CODE_OK_NO_CONTENT);
        }

        $result = array
        (
            'id'    => $ref->id,
            'content'   => $ref->reference,
            'description'  => $ref->description,
            'doi' => $ref->doi,
            'created'   => $ref->createDateTime
        );

        $this->answer($result);
    }

    /**
     * Gets all publications
     * 
     * @GET
     */
    public function getAll()
    {
        $publ_model = new Publications();
        
        $data = $publ_model->get_all();
        
        $this->answer($data->as_array(NULL, FALSE));
    }
    
}
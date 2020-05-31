<?php

/**
 * Internal API HANDLER
 */
class ApiMolecules extends ApiController
{    
    /**
     * Constructor
     */
    function __construct()
    {
        
    }

    /**
     * Get molecule detail
     * 
     * @GET
     */
    public function get($identifier = NULL)
    {
        $substanceModel = new Substances();

        $substance = $substanceModel->where(array
            (
              'identifier' => $identifier  
            ))
            ->get_one();

        if(!$substance->id)
        {
            $this->answer(NULL, self::CODE_NOT_FOUND);
        }

        $res = array
        (
            'id' => $substance->id,
            'identifier' => $substance->identifier,
            'name' => $substance->name,
            'smiles' => $substance->SMILES,
            'mw'    => $substance->MW,
            'logP' => $substance->LogP,
            'pubchem_id' => $substance->pubchem,
            'drugbank_id' => $substance->drugbank,
            'chEBI_id' => $substance->chEBI,
            'pdb_id' => $substance->pdb,
            'chEMBL_id' => $substance->chEMBL
        );

        $this->answer($res);
    }

    
}
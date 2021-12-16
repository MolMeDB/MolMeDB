<?php

/**
 * SMILES api handler
 */
class ApiSmiles extends ApiController
{
    /**
     * Constructor
     */
    function __construct()
    {

    }

    /**
     * Canonize given smiles string
     * 
     * @GET
     * @param smi - SMILES string
     * 
     */
    public function canonize($smiles)
    {
        $smiles = strtoupper($smiles);

        $RDKIT = new Rdkit();

        // Is RDKIT running?
        if(!$RDKIT->is_connected())
        {
            //$this->answer(NULL, self::CODE_FORBIDDEN);
        }

        $canonized = $RDKIT->canonize_smiles($smiles);

        if($canonized)
        {
            $result = array
            (
                'requested' => $smiles,
                'canonized' => $canonized
            );

            //$this->answer($result);
        }

        //$this->answer(NULL, self::CODE_BAD_REQUEST);
    }
}
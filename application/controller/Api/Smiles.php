<?php

/**
 * SMILES api handler
 */
class ApiSmiles extends ApiController
{

    /**
     * Canonize given smiles string
     * 
     * @GET
     * @param @required $smiles - SMILES string
     * 
     * @PATH(/canonize)
     */
    public function canonize($smiles)
    {
        $smiles = strtoupper($smiles);

        $RDKIT = new Rdkit();

        // Is RDKIT running?
        if(!$RDKIT->is_connected())
        {
            ResponseBuilder::server_error('Cannot establish a connection to the RDKIT server.');
        }

        $canonized = $RDKIT->canonize_smiles($smiles);

        if($canonized)
        {
            $result = array
            (
                'requested' => $smiles,
                'canonized' => $canonized
            );

            return $result;
        }

        ResponseBuilder::server_error('Cannot canonize SMILES.');
    }
}
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
        $keys = array('identifier', 'name', 'pdb', 'pubchem', 'drugbank', 'chEMBL', 'chEBI', 'SMILES');

        if(!$identifier)
        {
            $this->answer(NULL, self::CODE_NOT_FOUND);
        }

        $rdkit = new Rdkit();

        foreach($keys as $k)
        {
            if($k == 'SMILES' && $rdkit->is_connected())
            {
                $identifier = $rdkit->canonize_smiles($identifier);
            }

            $substance = $substanceModel->where(array
                (
                    $k => $identifier  
                ))
                ->get_one();

            if($substance->id)
            {
                break;
            }
        }
        
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

    /**
     * Find molecules details
     * 
     * @GET
     * @param identifier
     * @param smiles
     * @param name
     * @param pubchem
     * @param drugbank
     * @param chebi
     * @param chembl
     * @param pdb
     * @param limit 
     * @param offset - Default = 0
     */
    public function find($identifier, $smiles, $name, $pubchem, $drugbank, $chebi, $chembl, $pdb, $limit, $offset)
    {
        $substanceModel = new Substances();

        $substances = $substanceModel->search_by_params($identifier, $smiles, $name, $pubchem, $drugbank, $chebi, $chembl, $pdb, $limit, $offset);
        
        if(!$substances)
        {
            $this->answer(NULL, self::CODE_NOT_FOUND);
        }

        $res = array
        (
            'query' => array
            (
                'identifier' => $identifier,
                'smiles' => $smiles,
                'name' => $name,
                'pubchem' => $pubchem,
                'drugbank' => $drugbank,
                'chebi' => $chebi,
                '$chembl' => $chembl,
                'pdb' => $pdb,
                'limit' => $limit,
                'offset' => $offset
            ),
            'data' => array()
        );

        foreach($substances as $substance)
        {
            $res['data'][] = array
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
        }

        $this->answer($res);
    }

    
}
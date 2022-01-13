<?php

/**
 * PDB api handler
 */
class PDB extends Identifier_loader
{
    /** Holds connection */
    private $client;

    /** STATUS */
    private $STATUS = false;

    /** Data holder */
    private $data;

    /** Holds info about last used identifier type */
    public $last_identifier = null;

    /**
     * Constructor
     */
    function __construct()
    {
        try 
        {
            // Add to the system setting in next update
            $this->client = new Http_request('https://data.rcsb.org/rest/v1/core/');

            // Try to connect
            $this->STATUS =  $this->client->test_connection('test');
        } 
        catch (Exception $e) 
        {
            $this->STATUS = false;
        }
    }

    /**
     * Checks, if given remote server is reachable
     * 
     * @return boolean
     */
    function is_reachable()
    {
        return $this->is_connected();
    }

        /**
     * Returns SMILES for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_smiles($substance)
    {
        $data = $this->get_data($substance->pdb);

        if(!$data)
        {
            return false;
        }

        return $data->smiles;
    }

    /**
     * Returns name for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_name($substance)
    {
        $data = $this->get_data($substance->pdb);

        if(!$data)
        {
            return false;
        }

        return $data->name;
    }

    /**
     * Checks, if given identifier is valid
     * 
     * @param Substances $substance
     * 
     * @return boolean
     */
    public function is_valid_identifier($substance)
    {
        if(!$substance->pdb)
        {
            return NULL;
        }

        $uri = 'chemcomp/' . $substance->pdb;
        $method = Http_request::METHOD_GET;

        try 
        {
            $response = $this->client->request($uri, $method);

            if($response && $response->chem_comp)
            {
                return true;
            }
        }
        catch(Exception $e)
        {

        }

        return false;
    }   

    /**
     * Returns PDB id by given identifier
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_pdb($substance)
    {
        return false;
    }

    /**
     * Returns Pubchem id by given identifier
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_pubchem($substance)
    {
        return FALSE;   
    }

    /**
     * Returns drugbank id by given identifier
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_drugbank($substance)
    {
        if(!$substance->pdb)
        {
            return NULL;
        }

        $this->last_identifier = Validator_identifiers::ID_PDB;

        $uri = 'drugbank/' . $substance->pdb;
        $method = Http_request::METHOD_GET;

        try 
        {
            $response = $this->client->request($uri, $method);

            if($response && $response->drugbank_container_identifiers)
            {
                return $response->drugbank_container_identifiers->drugbank_id;
            }
        }
        catch(Exception $e)
        {

        }

        return false;
    }

    /**
     * Returns chembl id by given identifier
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_chembl($substance)
    {
        return FALSE;
    }

    /**
     * Returns inchikey for given substance
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_inchikey($substance)
    {
        return false;
    }

    /**
     * Returns chebi id by given identifier
     * 
     * @param Substances $substance
     * 
     * @return string|false - False, if not found
     */
    function get_chebi($substance)
    {
        return FALSE;
    }

    /**
     * Finds 3D structure for given molecule
     * 
     * @param Substances $substance
     * 
     * @return string|null
     */
    public function get_3d_structure($substance)
    {
        if(!$substance->pdb)
        {
            return NULL;
        }

        $this->last_identifier = Validator_identifiers::ID_PDB;

        try
        {        
            $client = new Http_request('https://files.rcsb.org/ligands/view/');
            $uri = $substance->pdb . "_ideal.sdf";

            $response = $client->request($uri, "GET", array(), FALSE, 10, FALSE);

            if($response)
            {
                return $response;
            }
        }
        catch(Exception $e)
        {
                return NULL;
        }
        
        return NULL;
    }

    /**
     * Returns data from PDB source
     * 
     * @param string $pdb_id
     * 
     * @return object|null
     */
    public function get_data($pdb_id)
    {
        if(!$pdb_id)
        {
            return NULL;
        }

        if(isset($this->data[$pdb_id]))
        {
            return $this->data[$pdb_id];
        }

        $this->last_identifier = Validator_identifiers::ID_PDB;

        $uri = 'chemcomp/' . $pdb_id;
        $method = Http_request::METHOD_GET;

        try 
        {
            $response = $this->client->request($uri, $method);

            if($response && $response->chem_comp)
            {
                $smiles = null;

                if($response->pdbx_chem_comp_descriptor && count($response->pdbx_chem_comp_descriptor))
                {
                    $search = ['SMILES_CANONICAL', "SMILES"];

                    foreach($search as $type)
                    {
                        foreach($response->pdbx_chem_comp_descriptor as $des)
                        {
                            if($des->comp_id != $response->chem_comp->id)
                            {
                                continue;
                            }

                            if($des->descriptor && $des->type == $type)
                            {
                                $smiles = $des->descriptor;
                                break;
                            }
                        }

                        if($smiles)
                        {
                            break;
                        }
                    }
                }

                $this->data[$pdb_id] = (object) array
                (
                    'id'        => $response->chem_comp->id,
                    'name'      => $response->chem_comp->name,
                    'formula'   => $response->chem_comp->formula,
                    'mw'        => $response->chem_comp->formula_weight,
                    'type'      => $response->chem_comp->type,
                    'smiles'    => $smiles
                );

                return $this->data[$pdb_id];
            }

            return NULL;
        } 
        catch (Exception $e) 
        {
            return NULL;
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

}
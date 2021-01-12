<?php

/**
 * Interaction controller
 */
class ApiInteractions extends ApiController
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
     * Returns interactions by reference ID
     * 
     * @GET
     * @param id - Reference ID
     */
    public function get_by_reference($id)
    {
        $inter = new Interactions();

        $data = $inter
            ->where(array
            (
                'id_reference'  => $id,
                'visibility'    => Interactions::VISIBLE
            ))
            ->get_all();

        $result = array();

        foreach($data as $row)
        {
            $result[] = array
            (
                'name'  => $row->substance ? $row->substance->name : NULL,
                'identifier' => $row->substance ? $row->substance->identifier : NULL,
                'pubchem' => $row->substance ? $row->substance->pubchem : NULL,
                'drugbank' => $row->substance ? $row->substance->drugbank : NULL,
                'membrane'  => $row->membrane ? $row->membrane->name : NULL,
                'method'  => $row->method ? $row->method->name : NULL,
                'publication' => $row->reference ? $row->reference->citation : NULL,
                'secondary_publication' => $row->dataset ? $row->dataset->publication->citation : NULL,
                'SMILES'  => $row->substance ? $row->substance->SMILES : NULL,
                'LogP'  => $row->substance ? $row->substance->LogP : NULL,
                'MW'  => $row->substance ? $row->substance->MW : NULL,
                'charge'  => $row->charge,
                'temperature'  => $row->temperature,
                'comment'  => $row->comment,
                'Position'  => $row->Position,
                'Position_acc'  => $row->Position_acc,
                'Penetration'  => $row->Penetration,
                'Penetration_acc'  => $row->Penetration_acc,
                'LogK'  => $row->LogK,
                'LogK_acc'  => $row->LogK_acc,
                'Water'  => $row->Water,
                'Water_acc'  => $row->Water_acc,
                'Penetration'  => $row->Penetration,
                'Penetration_acc'  => $row->Penetration_acc,
                'LogPerm'  => $row->LogPerm,
                'LogPerm_acc'  => $row->LogPerm_acc,
            );
        }
        
        $this->answer($result);
    }
    
}
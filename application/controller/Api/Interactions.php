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
     * Returns interactions for given params
     * 
     * @GET
     * @param membrane
     * @param method
     * @param molecule
     * @param publication
     * @param limit
     * @param offset
     */
    public function get($membrane, $method, $molecule, $publication, $limit, $offset)
    {
        $inter_model = new Interactions();
        $membraneModel = new Membranes();
        $methodModel = new Methods();
        $publicationModel = new Publications();
        $substanceModel = new Substances();

        $membrane = $membraneModel->where('name', $membrane)->get_one();
        $method = $methodModel->where('name', $method)->get_one();
        $molecule = $substanceModel->where('identifier', $molecule)->get_one();
        $reference = NULL;
        $limit_arr = array();
        
        $find_array = array();

        // Find publication
        if($publication)
        {
            $publication = "%$publication%";
            $reference = $publicationModel->get_by_query($publication);
        }

        if($membrane->id)
        {
            $find_array['id_membrane'] = $membrane->id;
        }
        if($method->id)
        {
            $find_array['id_method'] = $method->id;
        }
        if($reference && $reference->id)
        {
            $find_array['id_reference'] = $reference->id;
        }
        if($molecule->id)
        {
            $find_array['id_substance'] = $molecule->id;
        }
        
        if(!count($find_array))
        {
            $this->answer(NULL, self::CODE_NOT_FOUND);
        }

        $interactions = $inter_model->where($find_array)
            ->limit($limit)
            ->offset($offset)
            ->get_all();

        $data = array();

        foreach($interactions as $i)
        {
            $data[] = array
            (
                'id' => $i->id,
                'membrane' => $i->membrane->name,
                'method' => $i->method->name,
                'molecule' => $i->substance ? array
                (
                    'name' => $i->substance->name,
                    'SMILES' => $i->substance->SMILES,
                    'LogP'  => $i->substance->LogP,
                    'MW'  => $i->substance->MW,
                ) : NULL, 
                'primary_reference' => $i->publication ? $i->publication->citation : NULL,
                'secondary_reference' => $i->dataset->publication ? $i->dataset->publication->citation : NULL,
                'charge'  => $i->charge,
                'temperature'  => $i->temperature,
                'Position'  => $i->Position,
                'Position_acc'  => $i->Position_acc,
                'Penetration'  => $i->Penetration,
                'Penetration_acc'  => $i->Penetration_acc,
                'LogK'  => $i->LogK,
                'LogK_acc'  => $i->LogK_acc,
                'Water'  => $i->Water,
                'Water_acc'  => $i->Water_acc,
                'Penetration'  => $i->Penetration,
                'Penetration_acc'  => $i->Penetration_acc,
                'LogPerm'  => $i->LogPerm,
                'LogPerm_acc'  => $i->LogPerm_acc,
            );
        }

        $this->answer($data);
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
                'membrane'  => $row->membrane ? $row->membrane->name : NULL,
                'method'  => $row->method ? $row->method->name : NULL,
                'publication' => $row->reference ? $row->reference->citation : NULL,
                'SMILES'  => $row->substance ? $row->substance->SMILES : NULL,
                'LogP'  => $row->substance ? $row->substance->LogP : NULL,
                'MW'  => $row->substance ? $row->substance->MW : NULL,
                'charge'  => $row->charge,
                'temperature'  => $row->temperature,
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
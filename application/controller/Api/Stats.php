<?php

class ApiStats extends ApiController
{    
    function __construct()
    {
        
    }

    /** Get compounds for given 
     * method x membrane combination
     * 
     * @GET
     * @param idMembrane
     * @param idMethod
     * 
     */
    public function loadCompounds($idMembrane, $idMethod)
    {
        $substanceModel = new Substances();
        
        $data = $substanceModel->getSubstancesToStat($idMembrane, $idMethod);

        $this->answer($data->as_array());
    }

    
    /**
     * Gets membrane subcategories(methods) in stats
     * 
     * @GET
     * @param id - ID of membrane
     * 
     */
    public function membrane_subcats($id)
    {
        $membrane = new Membranes($id);
        $interaction_model = new Interactions();

        if(!$membrane->id)
        {
            $this->answer(NULL, self::CODE_BAD_REQUEST);
        }

        $data = $interaction_model
            ->where(array
            (
                'id_membrane'   => $membrane->id,
                'visibility'    => Interactions::VISIBLE
            ))
            ->select_list(array("id_method", "COUNT(id_method) as count"))
            ->distinct()
            ->group_by('id_method')
            ->get_all();

        $result = array();

        foreach($data as $row)
        {
            if(!$row->method)
            {
                continue;
            }

            $result[] = array
            (
                'id'    => $row->id_method,
                'name'  => $row->method->name,
                'count' => $row->count
            );
        }

        $this->answer($result);
    }

    /**
     * Gets method subcategories(membranes) in stats
     * 
     * @GET
     * @param id - ID of method
     * 
     */
    public function method_subcats($id)
    {
        $method = new Methods($id);
        $interaction_model = new Interactions();

        if(!$method->id)
        {
            $this->answer(NULL, self::CODE_BAD_REQUEST);
        }

        $data = $interaction_model
            ->where(array
            (
                'id_method' => $method->id,
                'visibility'    => Interactions::VISIBLE
            ))
            ->select_list(array("id_membrane", "COUNT(id_membrane) as count"))
            ->distinct()
            ->group_by('id_membrane')
            ->get_all();

        $result = array();

        foreach($data as $row)
        {
            if(!$row->membrane)
            {
                continue;
            }

            $result[] = array
            (
                'id'    => $row->id_membrane,
                'name'  => $row->membrane->name,
                'count' => $row->count
            );
        }

        $this->answer($result);
    }

    /**
     * Get detail for membrane-method combination
     * 
     * @GET
     * @param id_membrane
     * @param id_method
     * 
     */
    public function detail($id_membrane, $id_method)
    {
        $interaction_model = new Interactions();
        $membrane = new Membranes($id_membrane);
        $method = new Methods($id_method);

        if(!$membrane->id || !$method->id)
        {
            $this->answer(NULL, self::CODE_BAD_REQUEST);
        }

        $interactions = $interaction_model->where(array
            (
                'id_membrane'   => $id_membrane,
                'id_method'     => $id_method,
                'visibility'    => Interactions::VISIBLE
            ))
            ->select_list('id')
            ->get_all();

        $compounds = $interaction_model->where(array
            (
                'id_membrane'   => $id_membrane,
                'id_method'     => $id_method,
                'visibility'    => Interactions::VISIBLE
            ))
            ->select_list('id_substance')
            ->group_by('id_substance')
            ->get_all();

        $result = array
        (
            'membrane'      => $membrane->name,
            'method'        => $method->name,
            'total_compounds'   => $compounds->count(),
            'total_interactions'    => $interactions->count(),
            'interaction_ids'   => array(),
            'substance_ids'     => array()
        ); 

        foreach($interactions as $i)
        {
            $result['interaction_ids'][] = $i->id;
        }

        foreach($compounds as $c)
        {
            $result['substance_ids'][] = array
            (
                'name'  => $c->substance->name,
                'id'    => $c->substance->id
            );
        }

        $this->answer($result);
    }
}
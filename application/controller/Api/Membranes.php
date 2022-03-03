<?php

/**
 * Internal API HANDLER
 */
class ApiMembranes extends ApiController
{    
    /**
     * Returns membrane detail by given param
     * Priority: $id > $cam > $tag
     * 
     * @GET
     * @param $id
     * @param $cam
     * @param $tag
     * 
     * @PATH(/detail)
     */
    public function get_detail($id, $cam, $tag)
    {
        $membrane = new Membranes($id);

        if(!$membrane->id)
        {
            $membrane = $membrane->where('CAM', $cam ? $cam : "NONSENSE!@#")->get_one();
            if(!$membrane->id)
            {
                $membrane = $membrane->where('idTag', $tag ? $tag : "NONSENSE!@#")->get_one();
            }

            if(!$membrane->id)
            {
                ResponseBuilder::not_found('Membrane not found.');
            }
        }

        $result = array
        (
            'id'    => $membrane->id,
            'name'  => $membrane->name,
            'keywords' => $membrane->keywords,
            'description'   => $membrane->description,
            'reference' => $membrane->references,
            'CAM'   => $membrane->CAM,
            'created'   => $membrane->createDateTime
        );

        return $result;
    }

    /**
     * Returns all membranes for given parameters
     * 
     * @GET
     * @param $id_compound
     * @param $id_method
     * @PATH(/all)
     */
    public function getAll($id_compound, $id_method)
    {
        if(!is_array($id_compound) && $id_compound)
        {
            $id_compound = [$id_compound];
        }

        if(!is_array($id_method) && $id_method)
        {
            $id_method = [$id_method];
        }

        $result = array();

        if(!is_array($id_compound) && !is_array($id_method))
        {
            $membrane_model = new Membranes();

            $data = $membrane_model->get_all();

            foreach ($data as $row) 
            {
                $result[] = array
                (
                    'id'    => $row->id,
                    'name'  => $row->name,
                    'cam'   => $row->CAM
                );
            }
        }
        else
        {
            $interaction_model = new Interactions();

            $data = $interaction_model
                ->where('visibility', Interactions::VISIBLE);

            if(is_array($id_compound))
            {
                $data->in('id_substance', $id_compound);
            }
            if(is_array($id_method))
            {
                $data->in('id_method', $id_method);
            }

            $data = $data->select_list('id_membrane')
                ->distinct()
                ->get_all();

            foreach ($data as $row) 
            {
                if (!$row->membrane) 
                {
                    continue;
                }
    
                $result[] = array
                (
                    'id'    => $row->membrane->id,
                    'name'  => $row->membrane->name,
                    'cam'   => $row->membrane->CAM
                );
            }
        }
        
        return $result;
    }
    
    // /**
    //  * Returns membrane categories
    //  * 
    //  * @GET
    //  * @param idCategory
    //  * @param idSubcategory
    //  * @param level
    //  */
    // public function getCategories($cat_id, $subcat_id, $level)
    // {   
    //     $membraneModel = new Membranes();
        
    //     switch($level)
    //     {
    //         case 1:
    //             $data = $membraneModel->get_all_subcategories();
    //             break;
            
    //         case 2:
    //             $data = $membraneModel->get_membranes_by_categories($subcat_id, $cat_id);
    //             break;
            
    //         default:
    //             $data = array();
    //             break;
    //     }
        
    //     //$this->answer($data->as_array());
    // }
    
    // /**
    //  * Return membrane category
    //  * 
    //  * @GET
    //  * @param @required $id
    //  * @PATH(/category)
    //  */
    // public function getCategory($id)
    // {
    //     $membrane_model = new Membranes();
        
    //     $data = array();
        
    //     $data['membrane_categories'] = $membrane_model->get_membrane_category($idMembrane)->as_array();
        
    //     $data['all_subcategories'] = $membrane_model->get_all_subcategories()->as_array();
        
    //     //$this->answer($data);
    // }
    
}
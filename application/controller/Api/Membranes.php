<?php

/**
 * Internal API HANDLER
 */
class ApiMembranes extends ApiController
{    
    /**
     * Constructor
     */
    function __construct()
    {
        
    }

    /**
     * Returns membrane detail
     * 
     * @GET
     * @param q
     */
    public function get($q = NULL)
    {
        $membraneModel = new Membranes();
        $membrane = $membraneModel->search($q, 1)[0];

        if(!$membrane->id)
        {
            $this->answer(array(), self::CODE_NOT_FOUND);
        }

        $result = array
        (
            'id'    => $membrane->id,
            'name'  => $membrane->name,
            'keywords' => $membrane->keywords,
            'description'   => str_replace(PHP_EOL, '', strip_tags($membrane->description)),
            'references' => str_replace(PHP_EOL, '', strip_tags($membrane->references)),
        );

        $this->answer($result);
    }

    /**
     * Returns all membranes for given 
     * 
     * @POST
     * @param substance_ids Array
     * @param method_ids  Array
     */
    public function getAll($substance_ids = NULL, $method_ids = NULL) 
    {
        if(!$substance_ids)
        {
            $substance_ids = [];
        }

        if(!$method_ids)
        {
            $method_ids = [];
        }

        // Verify input
        $substance_ids = $this->remove_empty_values($substance_ids);
        $method_ids = $this->remove_empty_values($method_ids);

        $result = array();

        if(empty($substance_ids) && empty($method_ids))
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
                ->where('visibility', Interactions::VISIBLE)
                ->in('id_substance', $substance_ids)
                ->in('id_method', $method_ids)
                ->select_list('id_membrane')
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
        
        $this->answer($result);
    }
    
    /**
     * Returns membrane categories
     * 
     * @GET
     * @param idCategory
     * @param idSubcategory
     * @param level
     */
    public function getCategories($cat_id = NULL, $subcat_id = NULL, $level = NULL)
    {   
        $membraneModel = new Membranes();
        
        switch($level)
        {
            case 1:
                $data = $membraneModel->get_all_subcategories();
                break;
            
            case 2:
                $data = $membraneModel->get_membranes_by_categories($subcat_id, $cat_id);
                break;
            
            default:
                $data = array();
                break;
        }
        
        $this->answer($data->as_array());
    }
    
    /**
     * Return membrane category
     * 
     * @GET
     * @param idMembrane
     */
    public function getCategory($idMembrane = NULL)
    {
        $membrane_model = new Membranes();
        
        $data = array();
        
        $data['membrane_categories'] = $membrane_model->get_membrane_category($idMembrane)->as_array();
        
        $data['all_subcategories'] = $membrane_model->get_all_subcategories()->as_array();
        
        $this->answer($data);
    }
    
}
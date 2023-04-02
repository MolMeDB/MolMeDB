<?php

use EasyRdf\Http\Response;

/**
 * Internal API HANDLER
 */
class ApiMembranes extends ApiController
{    
     ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ################################################################################
    ############################### PUBLIC #########################################
    ################################################################################
    ################################################################################

    ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ########## membranes/all<?categorized> #########################################
    ################################################################################

    /**
     * Returns all membranes (PUBLIC)
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @optional $categorized
     * 
     * @Path(/all)
     */
    public function P_get_all($categorized)
    {
        $result = [];
        if(!$categorized)
        {
            $all = Membranes::instance()->get_all();

            foreach($all as $mem)
            {
                $result[] = $mem->get_public_detail();
            }
        }
        else
        {
            $d = Membranes::instance()->get_active_categories();

            foreach($d as $id => $cats)
            {
                $m = new Membranes($id);
                $result[] = array
                (
                    'membrane' => $m->get_public_detail(),
                    'category' => $cats->category,
                    'subcategory' => $cats->subcategory,
                );
            }
        }

        return $result;
    }

    ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ########## membranes/categories ################################################
    ################################################################################

    /**
     * Returns all membrane categories (PUBLIC)
     * 
     * @GET
     * @PUBLIC
     * 
     * @Path(/categories)
     */
    public function P_get_categories()
    {
        $et_model = new Enum_types();
        $categories = $et_model->get_categories(Enum_types::TYPE_MEMBRANE_CATS);
        $result = $this->clarify_cat_structure($categories);
        return $result;
    }

    /**
     * Clarify category structure
     * 
     * @param array $cats
     * 
     * @return array
     */
    private function clarify_cat_structure($cats)
    {
        $result = $cats;

        foreach($cats as $key => $row)
        {
            unset($result[$key]['id_element']);
            unset($result[$key]['id_enum_type']);
            unset($result[$key]['fixed']);
            unset($result[$key]['value']);
            unset($result[$key]['last']);
            if(isset($result[$key]['children']))
            {
                $result[$key]['children'] = $this->clarify_cat_structure($result[$key]['children']);
                $result[$key]['subcategories'] = $result[$key]['children'];
                unset($result[$key]['children']);
            }
        }

        return $result;
    }

     ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ########## membranes/detail ################################################
    ################################################################################

    /**
     * Returns detail of membrane
     * 
     * @GET
     * @PUBLIC
     * 
     * @param @optional $identifier
     * @param @optional $category
     * 
     * @Path(/detail)
     */
    public function P_detail($identifier, $category)
    {
        if(!$identifier && !$category)
        {
            ResponseBuilder::bad_request('At least one parameter must be set.');
        }

        $result = [];
        $membranes = Membranes::instance()->find_all($identifier ? $identifier : $category);

        foreach($membranes as $mem)
        {
            $result[] = $mem->get_public_detail(true);
        }

        return $result;
    }

     ////////////////////////////////////////////////////////////////////////////////

    ################################################################################
    ################################################################################
    ############################### PRIVATE ########################################
    ################################################################################
    ################################################################################

    ////////////////////////////////////////////////////////////////////////////////

    /**
     * Returns membrane detail by given param
     * Priority: $id > $cam > $tag
     * 
     * @GET
     * @INTERNAL
     * 
     * @param $id
     * @param $cam
     * @param $tag
     * 
     * @PATH(/p/detail)
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
            'id_cosmo_file' => $membrane->id_cosmo_file,
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
     * @INTERNAL
     * 
     * @param $id_compound
     * @param $id_method
     * 
     * @PATH(/get/all)
     */
    public function getAll_internal($id_compound, $id_method)
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
}